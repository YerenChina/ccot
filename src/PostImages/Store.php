<?php
namespace BushcraftCN\CCOT\PostImages;
use BushcraftCN\CCOT\{Crypto, I18n, Limiter, Problem, Setup};
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Flarum\Post\Post;
use Flarum\Discussion\Discussion;
use Flarum\Http\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\UploadedFileInterface;
final class Store
{
    private static array $locks=[];
    public function __construct(public ConnectionInterface $db, private Paths $paths, private Settings $options, private SettingsRepositoryInterface $settings, private Processor $processor, private UrlGenerator $url) {}
    public function directory(): string
    {
        $public=realpath($this->paths->public);$assets=$this->paths->public.'/assets';$dir=$assets.'/ccot-post-images';
        if(!$public||is_link($assets)||is_link($dir))throw new Problem(I18n::t('api.post_images_storage_failed'),503);
        foreach([$assets,$dir]as$path)if(!is_dir($path)&&!@mkdir($path,0755))throw new Problem(I18n::t('api.post_images_storage_failed'),503);
        if(!str_starts_with(realpath($dir).'/', $public.'/'))throw new Problem(I18n::t('api.post_images_storage_failed'),503);
        return $dir;
    }
    public function url(string $id,string $variant): string {return $this->url->to('forum')->base().'/assets/ccot-post-images/'.$id.'-'.$variant.'.webp';}
    private function bytesOnDisk(): int
    {
        $total=0;
        // Retained files from a previous full uninstall no longer have CCOT index rows.
        foreach([$this->directory(),$this->paths->storage.'/ccot-post-images-removed']as$dir){
            if(is_link($dir))throw new Problem(I18n::t('api.post_images_storage_failed'),503);
            if(is_dir($dir))foreach(new \DirectoryIterator($dir)as$f)if($f->isFile()&&!$f->isLink()&&preg_match('/^[a-f0-9]{32}-(?:image|thumb)\.webp$/D',$f->getFilename()))$total+=$f->getSize();
        }
        return $total;
    }
    public function quarantine(string $id,string $variant,string $source): void
    {
        $this->path($id,$variant);$dir=$this->paths->storage.'/ccot-post-images-removed';
        if(is_link($dir)||is_link($source)||(!is_dir($dir)&&!@mkdir($dir,0700)))throw new Problem(I18n::t('api.post_images_storage_failed'),503);
        $dest=$dir.'/'.$id.'-'.$variant.'.webp';
        if(is_link($dest)||(is_file($dest)&&hash_file('sha256',$dest)!==hash_file('sha256',$source)))throw new Problem(I18n::t('api.post_images_storage_failed'),503);
        if(!is_file($dest))Setup::write($dest,file_get_contents($source));
        if(!unlink($source))throw new Problem(I18n::t('api.post_images_storage_failed'),503);
    }
    public function block(string $id): void
    {
        $this->lock(function()use($id){
            $this->path($id,'image');$row=$this->db->table('ccot_post_images')->where('id',$id)->first();
            if(!$row)throw new Problem(I18n::t('api.post_images_not_found'),404);
            if($row->deleted_at??null)return;
            foreach(['image','thumb']as$v){$path=$this->path($id,$v);if(file_exists($path)&&!is_file($path))throw new Problem(I18n::t('api.post_images_storage_failed'),503);}
            foreach(['image','thumb']as$v){$path=$this->path($id,$v);if(is_file($path))$this->quarantine($id,$v,$path);}
            $this->db->table('ccot_post_images')->where('id',$id)->update(['blocked_at'=>time()]);
        });
    }
    public function permanentlyDelete(string $id): void
    {
        $this->lock(function () use ($id) {
            $this->path($id, 'image');
            $row = $this->db->table('ccot_post_images')->where('id', $id)->first();
            if (! $row) throw new Problem(I18n::t('api.post_images_not_found'), 404);
            if ($row->deleted_at !== null) return;
            // First make both public URLs inaccessible. A partial failure stays blocked and can be retried.
            $this->block($id);
            $directory = $this->paths->storage.'/ccot-post-images-removed';
            if (is_link($directory)) throw new Problem(I18n::t('api.post_images_storage_failed'), 503);
            foreach (['image', 'thumb'] as $variant) {
                $path = $directory.'/'.$id.'-'.$variant.'.webp';
                if (is_link($path) || (file_exists($path) && (! is_file($path) || ! @unlink($path)))) throw new Problem(I18n::t('api.post_images_storage_failed'), 503);
            }
            // Keep the identity and association so existing post text and edits remain valid.
            $this->db->table('ccot_post_images')->where('id', $id)->update(['deleted_at' => time()]);
        });
    }

    public function previewFile(string $id, string $variant): string
    {
        $public = $this->path($id, $variant);
        $row = $this->db->table('ccot_post_images')->where('id', $id)->first();
        if (! $row || $row->deleted_at !== null) throw new Problem(I18n::t('api.post_images_not_found'), 404);
        $directory = $this->paths->storage.'/ccot-post-images-removed';
        $path = $row->blocked_at !== null ? $directory.'/'.$id.'-'.$variant.'.webp' : $public;
        if (($row->blocked_at !== null && is_link($directory)) || is_link($path) || ! is_file($path) || ! is_readable($path)) throw new Problem(I18n::t('api.post_images_not_found'), 404);
        return $path;
    }
    public function lock(callable $fn): mixed
    {
        $this->directory();$dir=$this->paths->storage.'/ccot-private';if(is_link($dir))throw new Problem(I18n::t('api.post_images_storage_failed'),503);
        if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new Problem(I18n::t('api.post_images_storage_failed'),503);
        if(isset(self::$locks[$dir]))return $fn();
        $file=$dir.'/post-images.lock';if(is_link($file))throw new Problem(I18n::t('api.post_images_storage_failed'),503);
        $h=@fopen($file,'c');if(!$h)throw new Problem(I18n::t('api.post_images_storage_failed'),503);@chmod($file,0600);
        try {if(!flock($h,LOCK_EX|LOCK_NB))throw new Problem(I18n::t('api.post_images_busy'),429);self::$locks[$dir]=true;return $fn();}
        finally {unset(self::$locks[$dir]);flock($h,LOCK_UN);fclose($h);}
    }
    public function assertVerified(User $actor): void
    {
        if($actor->isGuest()||!in_array('bushcraftcn-ccot',json_decode($this->settings->get('extensions_enabled','[]'),true)?:[],true))throw new Problem(I18n::t('api.complete_phone_verification_first'),403);
        if($actor->suspended_until&&$actor->suspended_until->isFuture())throw new Problem(I18n::t('api.post_images_permission'),403);
        $phone=$this->db->table('ccot_phones')->where('user_id',$actor->id)->first();
        if(!$phone)throw new Problem(I18n::t('api.complete_phone_verification_first'),403);
        try{$crypto=resolve(Crypto::class);$ok=hash_equals($phone->phone_hash,$crypto->digest('phone',$crypto->decrypt($phone->cipher)));}catch(\Throwable){$ok=false;}
        if(!$ok||$this->db->table('ccot_blocks')->where('phone_hash',$phone->phone_hash)->where('expires_at','>',time())->exists())throw new Problem(I18n::t('api.post_images_permission'),403);
    }
    public function assertContext(User $actor,array $body): void
    {
        $this->assertVerified($actor);
        if(isset($body['post_id'])){$id=$this->numeric($body['post_id']);$post=Post::whereVisibleTo($actor)->find($id);if(!$post||!$actor->can('edit',$post))throw new Problem(I18n::t('api.post_images_permission'),403);}
        elseif(isset($body['discussion_id'])){$id=$this->numeric($body['discussion_id']);$discussion=Discussion::whereVisibleTo($actor)->find($id);if(!$discussion||!$actor->can('reply',$discussion))throw new Problem(I18n::t('api.post_images_permission'),403);}
        elseif(!$actor->can('startDiscussion'))throw new Problem(I18n::t('api.post_images_permission'),403);
    }
    private function numeric(mixed $v): int {if(!(is_int($v)||is_string($v)&&preg_match('/^[1-9][0-9]{0,9}$/D',$v))||(int)$v<1)throw new Problem(I18n::t('api.post_images_permission'),403);return (int)$v;}
    public function upload(User $actor, array $body, UploadedFileInterface $file): array
    {
        $this->assertContext($actor,$body);$options=$this->options->read();if(!$options['enabled'])throw new Problem(I18n::t('api.post_images_disabled'),403);
        $key=$body['request_key']??null;if(!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$key))throw new Problem(I18n::t('api.post_images_invalid_request'),422);
        return $this->lock(function()use($actor,$body,$file,$key,$options){
            $existing=$this->db->table('ccot_post_images')->where('user_id',$actor->id)->where('request_key',$key)->first();
            if($existing){$stream=$file->getStream();$stream->rewind();if($file->getError()!==UPLOAD_ERR_OK||!$file->getSize()||$file->getSize()>$options['max_mb']*1048576||hash('sha256',$stream->read($options['max_mb']*1048576+1))!==$existing->source_hash)throw new Problem(I18n::t('api.post_images_invalid_request'),409);return $this->data($existing);}
            $limiter=resolve(Limiter::class);$limiter->take('post.image.short',(string)$actor->id,600,20);$limiter->take('post.image.day',(string)$actor->id,86400,200);
            if($this->db->table('ccot_post_images')->where('user_id',$actor->id)->whereNull('post_id')->whereNull('unused_at')->count()>=12)throw new Problem(I18n::t('api.post_images_pending_limit'),429);
            $processed=$this->processor->process($file,$options);$length=strlen($processed['image'])+strlen($processed['thumb']);
            $used=$this->bytesOnDisk();if($used+$length>$options['capacity_gb']*1073741824)throw new Problem(I18n::t('api.post_images_capacity'),507);
            $id=bin2hex(random_bytes(16));$written=[];
            try {
                foreach(['image','thumb']as$variant){$path=$this->path($id,$variant);$h=@fopen($path,'x');if(!$h)throw new \RuntimeException('Post image write failed');$written[]=$path;@chmod($path,0644);$n=fwrite($h,$processed[$variant]);fclose($h);if($n!==strlen($processed[$variant]))throw new \RuntimeException('Incomplete image write');}
                $this->db->table('ccot_post_images')->insert(['id'=>$id,'user_id'=>$actor->id,'request_key'=>$key,'source_hash'=>$processed['source_hash'],'width'=>$processed['width'],'height'=>$processed['height'],'bytes'=>strlen($processed['image']),'thumb_bytes'=>strlen($processed['thumb']),'created_at'=>time()]);
            } catch(\Throwable) {foreach($written as$path)@unlink($path);throw new Problem(I18n::t('api.post_images_storage_failed'),503);}
            return $this->data($this->db->table('ccot_post_images')->where('id',$id)->first());
        });
    }
    public function path(string $id,string $variant): string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!in_array($variant,['image','thumb'],true))throw new Problem(I18n::t('api.post_images_not_found'),404);
        $path=$this->directory().'/'.$id.'-'.$variant.'.webp';if(is_link($this->directory())||is_link($path))throw new Problem(I18n::t('api.post_images_not_found'),404);return $path;
    }
    public function data(object $row): array
    {

        return ['id'=>$row->id,'width'=>(int)$row->width,'height'=>(int)$row->height,'image'=>$this->url($row->id,'image'),'thumb'=>$this->url($row->id,'thumb'),'blocked'=>$row->blocked_at!==null,'deleted'=>($row->deleted_at??null)!==null,'postId'=>$row->post_id?(int)$row->post_id:null];
    }
    public function forPost(Post $post): array
    {
        $ids=Markup::ids($post->parsed_content??'');$rows=$this->db->table('ccot_post_images')->where('post_id',$post->id)->whereIn('id',$ids)->get()->keyBy('id');$out=[];foreach($ids as$id)if(isset($rows[$id]))$out[]=$this->data($rows[$id]);return $out;
    }
    public function candidates(): array
    {
        $found=[];
        foreach($this->candidateQuery()->orderBy('created_at')->orderBy('id')->lazy(100) as $row){
            if($this->referenced($row->id))continue;
            $found[]=['id'=>$row->id,'bytes'=>(int)$row->bytes+(int)$row->thumb_bytes,'createdAt'=>(int)$row->created_at];
            if(count($found)>=500)break;
        }
        return $found;
    }
    private function candidateQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->db->table('ccot_post_images')->whereNull('post_id')->whereNull('deleted_at')->where(function($q){$q->where('unused_at','<=',time()-604800)->orWhere(fn($q)=>$q->whereNull('unused_at')->where('created_at','<=',time()-604800));});
    }
    private function removeManagedFile(string $path): void
    {
        if(is_link($path)||(file_exists($path)&&(!is_file($path)||!@unlink($path))))throw new Problem(I18n::t('api.post_images_storage_failed'),503);
    }
    public function automaticBatch(array $cursor): array
    {
        $query=$this->candidateQuery();
        if(isset($cursor['created'],$cursor['id']))$query->where(fn($q)=>$q->where('created_at','>',$cursor['created'])->orWhere(fn($q)=>$q->where('created_at',$cursor['created'])->where('id','>',$cursor['id'])));
        $rows=$query->orderBy('created_at')->orderBy('id')->limit(50)->get();
        $count=$this->clean($rows->pluck('id')->all());
        $last=$rows->last();
        return ['deleted'=>$count,'cursor'=>$last ? ['created'=>(int)$last->created_at,'id'=>$last->id] : []];
    }
    public function referenced(string $id): bool
    {
        foreach($this->db->table('posts')->where('type','comment')->where('content','like','%'.$id.'%')->pluck('content')as$xml)if(in_array($id,Markup::ids($xml??''),true))return true;return false;
    }
    public function clean(array $ids): int
    {
        if(count($ids)>500)throw new Problem(I18n::t('api.post_images_invalid_request'),422);
        return $this->lock(function()use($ids){$count=0;foreach(array_unique($ids)as$id){$this->path($id,'image');$row=$this->candidateQuery()->where('id',$id)->first();if(!$row||$this->referenced($id))continue;foreach(['image','thumb']as$v)$this->removeManagedFile($this->path($id,$v));$removed=$this->paths->storage.'/ccot-post-images-removed';if(is_link($removed))throw new Problem(I18n::t('api.post_images_storage_failed'),503);foreach(['image','thumb']as$v)$this->removeManagedFile($removed.'/'.$id.'-'.$v.'.webp');$this->db->table('ccot_post_images')->where('id',$id)->delete();$count++;}return $count;});
    }
}
