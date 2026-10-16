<?php
namespace BushcraftCN\CCOT;

use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

/** Resumable conversions: authenticate old ciphertext before replacing any stored value. */
final class Upgrade
{
    public function __construct(private ConnectionInterface $db, private Crypto $crypto, private Paths $paths, private UrlGenerator $url) {}

    public function run(): void
    {
        $schema = $this->db->getSchemaBuilder();
        foreach (['ccot_tickets'=>'content_version', 'ccot_ticket_images'=>'storage_version'] as $table=>$column) {
            if (! $schema->hasColumn($table, $column)) $schema->table($table, fn (Blueprint $t) => $t->unsignedTinyInteger($column)->default(0));
        }
        $this->db->table('ccot_tickets')->where('content_version', 0)->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $values = ['content_version'=>1];
                foreach (['message','contact','reply'] as $key) $values[$key] = $row->$key === null ? null : $this->crypto->decrypt($row->$key);
                $this->db->table('ccot_tickets')->where('id', $row->id)->where('content_version', 0)->update($values);
            }
        });
        $files = resolve(TicketFiles::class);
        foreach ($this->db->table('ccot_ticket_images')->orderBy('id')->cursor() as $image) {
            $old = $files->directory().'/'.$image->id.'.enc';
            if ((int) $image->storage_version === 0) {
                $bytes = $files->read($image);
                Setup::write($files->directory().'/'.$image->id.'.bin', $bytes);
                $this->db->table('ccot_ticket_images')->where('id', $image->id)->update(['storage_version'=>1]);
            }
            if (is_file($old) && (is_link($old) || ! unlink($old))) throw new \RuntimeException('CCOT legacy ticket image cleanup failed');
        }
        $this->postImages();
    }

    private function postImages(): void
    {
        $store = resolve(PostImages\Store::class);
        $oldDirectory = $this->paths->storage.'/ccot-post-images';
        if (is_link($oldDirectory)) throw new \RuntimeException('CCOT legacy post image directory is unsafe');
        $store->lock(function () use ($store, $oldDirectory) {
            foreach ($this->db->table('ccot_post_images')->orderBy('id')->cursor() as $row) {
                foreach (['image','thumb'] as $variant) {
                    $old = $oldDirectory.'/'.$row->id.'-'.$variant.'.webp';
                    $new = $store->path($row->id, $variant);
                    if (is_link($old)) throw new \RuntimeException('CCOT legacy post image is unsafe');
                    if ($row->blocked_at !== null) {
                        // A previously removed image must never be published by migration.
                        if (is_file($old)) $store->quarantine($row->id, $variant, $old);
                        if (is_file($new)) $store->quarantine($row->id, $variant, $new);
                        continue;
                    }
                    if (is_file($old)) {
                        if (is_file($new) && hash_file('sha256', $new) !== hash_file('sha256', $old)) throw new \RuntimeException('CCOT post image destination conflicts');
                        if (! is_file($new)) Setup::write($new, file_get_contents($old), 0644);
                    } elseif (! is_file($new)) throw new \RuntimeException('CCOT post image is missing; restore the file before upgrading');
                }
            }
            // Only this site's exact former endpoints and known image IDs are rewritten.
            $base = $this->url->to('api')->base().'/ccot/post-images/';
            $this->db->transaction(function () use ($store, $base) {
                $this->db->table('posts')->where('type', 'comment')->where('content', 'like', '%/ccot/post-images/%')->orderBy('id')->chunkById(100, function ($posts) use ($store, $base) {
                    foreach ($posts as $post) {
                        $content = $post->content;
                        foreach (PostImages\Markup::ids($content) as $id) {
                            $row = $this->db->table('ccot_post_images')->where('id', $id)->first();
                            if (! $row) throw new \RuntimeException('CCOT post image reference has no index; repair before upgrading');
                            foreach (['image','thumb'] as $variant) $content = str_replace(htmlspecialchars($base.$id.'/'.$variant, ENT_XML1|ENT_QUOTES, 'UTF-8'), htmlspecialchars($store->url($id, $variant), ENT_XML1|ENT_QUOTES, 'UTF-8'), $content);
                        }
                        if ($content !== $post->content) $this->db->table('posts')->where('id', $post->id)->update(['content'=>$content]);
                    }
                });
            });
            // Public copies and native post links are now durable; remove owned legacy copies only.
            foreach ($this->db->table('ccot_post_images')->pluck('id') as $id) foreach (['image','thumb'] as $variant) {
                $old = $oldDirectory.'/'.$id.'-'.$variant.'.webp';
                if (is_file($old) && ! unlink($old)) throw new \RuntimeException('CCOT legacy image cleanup failed');
            }
            if (is_dir($oldDirectory)) {
                if (is_link($oldDirectory.'/.lock')) throw new \RuntimeException('CCOT legacy lock is unsafe');
                if (is_file($oldDirectory.'/.lock')) unlink($oldDirectory.'/.lock');
                @rmdir($oldDirectory); // Unknown files are preserved for administrator inspection.
            }
        });
    }
}
