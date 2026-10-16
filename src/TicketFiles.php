<?php

namespace BushcraftCN\CCOT;

use Flarum\Foundation\Paths;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\UploadedFileInterface;

/** Private ticket images only; unrelated to public post uploads. */
final class TicketFiles
{
    public const MAX_FILES = 3;
    public const MAX_BYTES = 2097152;
    public const RETENTION_DAYS = 180;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private ConnectionInterface $db, private Crypto $crypto, private Paths $paths) {}

    public function directory(): string
    {
        $storage = realpath($this->paths->storage);
        $public = realpath($this->paths->public);
        $path = $this->paths->storage.'/ccot-ticket-images';
        if (! $storage || ! $public || is_link($path) || str_starts_with($storage.'/', $public.'/')) {
            throw new Problem(I18n::t('api.the_private_ticket_image_directory_is_unavailable_contact_an_administrator'), 503);
        }
        return $storage.'/ccot-ticket-images';
    }

    public function prepare(mixed $uploads): array
    {
        if (! is_array($uploads) || count($uploads) > self::MAX_FILES) throw new Problem(I18n::t('api.upload_at_most_3_images_per_ticket'), 422);
        $prepared = [];
        foreach ($uploads as $upload) {
            if (! $upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) throw new Problem(I18n::t('api.image_upload_failed_check_the_file_size_and_retry_up'), 422);
            $stream = $upload->getStream();
            if ($stream->isSeekable()) $stream->rewind();
            $bytes = '';
            while (! $stream->eof() && strlen($bytes) <= self::MAX_BYTES) {
                $part = $stream->read(min(65536, self::MAX_BYTES + 1 - strlen($bytes)));
                if ($part === '') break;
                $bytes .= $part;
            }
            if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) throw new Problem(I18n::t('api.each_image_must_be_nonempty_and_no_larger_than_2'), 422);
            $info = @getimagesizefromstring($bytes);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (! $info || ! isset(self::TYPES[$mime]) || ($info['mime'] ?? '') !== $mime) throw new Problem(I18n::t('api.only_valid_jpg_png_and_webp_images_are_supported_not'), 422);
            if ($info[0] < 1 || $info[1] < 1 || $info[0] > 10000 || $info[1] > 10000 || $info[0] * $info[1] > 20000000) throw new Problem(I18n::t('api.the_image_is_too_large_resize_it_to_at_most'), 422);
            $decoded = @imagecreatefromstring($bytes);
            if ($decoded === false) throw new Problem(I18n::t('api.the_image_is_incomplete_or_cannot_be_decoded_choose_another'), 422);
            unset($decoded);
            $prepared[] = ['bytes' => $bytes, 'mime' => $mime, 'size' => strlen($bytes), 'width' => $info[0], 'height' => $info[1]];
        }
        return $prepared;
    }

    public function store(int $ticketId, array $prepared, array &$written): void
    {
        if (! $prepared) return;
        $directory = $this->directory();
        if (! is_dir($directory) && ! @mkdir($directory, 0700) && ! is_dir($directory)) throw new Problem(I18n::t('api.ticket_images_could_not_be_saved_contact_an_administrator'), 503);
        foreach ($prepared as $image) {
            $id = bin2hex(random_bytes(16));
            $file = @fopen($directory.'/'.$id.'.bin', 'xb');
            if (! $file) throw new Problem(I18n::t('api.ticket_images_could_not_be_saved_please_retry_later'), 503);
            $written[] = $id;
            try {
                if (! @chmod($directory.'/'.$id.'.bin', 0600)) throw new Problem(I18n::t('api.could_not_set_ticket_image_permissions'), 503);
                $cipher = $image['bytes'];
                if (fwrite($file, $cipher) !== strlen($cipher) || ! fflush($file)) throw new Problem(I18n::t('api.ticket_images_could_not_be_saved_please_retry_later'), 503);
            } finally { fclose($file); }
            $this->db->table('ccot_ticket_images')->insert(['id' => $id, 'ticket_id' => $ticketId, 'mime' => $image['mime'], 'size' => $image['size'], 'width' => $image['width'], 'height' => $image['height'], 'storage_version' => 1]);
        }
    }

    public function metadata(array $ticketIds): array
    {
        $grouped = [];
        if (! $ticketIds) return $grouped;
        foreach ($this->db->table('ccot_ticket_images')->whereIn('ticket_id', $ticketIds)->orderBy('id')->get() as $image) {
            $grouped[$image->ticket_id][] = ['id' => $image->id, 'mime' => $image->mime, 'size' => $image->size, 'width' => $image->width, 'height' => $image->height, 'name' => I18n::t('api.ticket_image').(self::TYPES[$image->mime] ?? 'bin')];
        }
        return $grouped;
    }

    public function read(object $image): string
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $image->id)) throw new Problem(I18n::t('api.image_not_found'), 404);
        $path = $this->directory().'/'.$image->id.((int) ($image->storage_version ?? 0) === 1 ? '.bin' : '.enc');
        if (! is_file($path) || is_link($path)) throw new Problem(I18n::t('api.the_image_does_not_exist_or_has_been_removed'), 404);
        if (filesize($path) > self::MAX_BYTES * 2) throw new Problem(I18n::t('api.ticket_images_cannot_be_read_contact_an_administrator'), 503);
        try { $bytes = (string) @file_get_contents($path); if ((int) ($image->storage_version ?? 0) !== 1) $bytes = $this->crypto->decrypt($bytes); }
        catch (\Throwable) { throw new Problem(I18n::t('api.ticket_images_cannot_be_read_contact_an_administrator'), 503); }
        if (strlen($bytes) !== (int) $image->size) throw new Problem(I18n::t('api.ticket_images_cannot_be_read_contact_an_administrator'), 503);
        return $bytes;
    }

    public function removeFiles(array $ids): bool
    {
        if (! $ids) return true;
        try { $directory = $this->directory(); }
        catch (Problem) { return false; }
        $complete = true;
        foreach ($ids as $id) {
            if (! preg_match('/^[a-f0-9]{32}$/D', $id)) { $complete = false; continue; }
            foreach (['bin', 'enc'] as $format) {
                $path = $directory.'/'.$id.'.'.$format;
                if (is_link($path) || (file_exists($path) && ! @unlink($path))) $complete = false;
            }
        }
        return $complete;
    }

    /** Row lock serializes deletion against an administrator saving legal hold. */
    public function deleteTicket(int $id, ?int $expiredBefore = null): ?bool
    {
        $ids = $this->db->transaction(function () use ($id, $expiredBefore) {
            $ticket = $this->db->table('ccot_tickets')->where('id', $id)->lockForUpdate()->first();
            if ($expiredBefore !== null && (! $ticket || $ticket->hold || $ticket->status !== 'closed' || $ticket->updated_at >= $expiredBefore)) return null;
            if (! $ticket) throw new Problem(I18n::t('api.ticket_not_found'), 404);
            if ($ticket->hold) throw new Problem(I18n::t('api.this_ticket_is_held_and_cannot_be_deleted_review_and'), 409);
            $ids = $this->db->table('ccot_ticket_images')->where('ticket_id', $id)->pluck('id')->all();
            $this->db->table('ccot_ticket_images')->where('ticket_id', $id)->delete();
            $this->db->table('ccot_tickets')->where('id', $id)->delete();
            return $ids;
        });
        return $ids === null ? null : $this->removeFiles($ids);
    }

    public function cleanupExpired(int $before): array
    {
        $count = 0; $pending = 0;
        $this->db->table('ccot_tickets')->where('status', 'closed')->where('hold', false)->where('updated_at', '<', $before)->orderBy('id')->chunkById(100, function ($rows) use ($before, &$count, &$pending) {
            foreach ($rows as $row) {
                $removed = $this->deleteTicket($row->id, $before);
                if ($removed !== null) { $count++; if (! $removed) $pending++; }
            }
        });
        return ['tickets' => $count, 'file_cleanup_pending' => $pending, 'orphan_files' => $this->cleanupOrphans()];
    }

    public function cleanupOrphans(): int
    {
        $directory = $this->directory();
        if (! is_dir($directory)) return 0;
        $removed = 0;
        foreach (new \DirectoryIterator($directory) as $file) {
            // Grace period excludes files written by a not-yet-committed submission.
            if ($file->isLink() || ! $file->isFile() || ! preg_match('/^([a-f0-9]{32})\.(?:enc|bin)$/D', $file->getFilename(), $match) || $file->getMTime() >= time() - 3600) continue;
            if (! $this->db->table('ccot_ticket_images')->where('id', $match[1])->exists() && $this->removeFiles([$match[1]])) $removed++;
        }
        return $removed;
    }
}
