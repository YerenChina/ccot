<?php

namespace BushcraftCN\CCOT\PostImages;

use BushcraftCN\CCOT\{I18n, Problem};
use Illuminate\Database\ConnectionInterface;

/** Administrator-only, bounded SQL pagination. No full-list or filesystem scan. */
final class Management
{
    public function __construct(private ConnectionInterface $db, private string $base) {}

    private function number(mixed $value, int $max): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value) || (int) $value > $max) {
            throw new Problem(I18n::t('api.post_images_invalid_request'), 422);
        }
        return (int) $value;
    }

    public function read(array $params): array
    {
        $page = $this->number($params['page'] ?? 1, 1000000);
        $perPage = $this->number($params['perPage'] ?? 20, 100);
        $state = $params['state'] ?? 'all';
        $grammar = $this->db->getQueryGrammar();
        if (! is_string($state) || ! in_array($state, ['all', 'published', 'pending', 'unused', 'blocked', 'deleted'], true)) throw new Problem(I18n::t('api.post_images_invalid_request'), 422);
        $query = $this->db->table('ccot_post_images as i')
            ->leftJoin('posts as p', 'p.id', '=', 'i.post_id')
            ->leftJoin('posts as original', 'original.id', '=', 'i.source_post_id')
            ->leftJoin('discussions as d', 'd.id', '=', 'p.discussion_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.user_id')
            ->leftJoin('users as a', 'a.id', '=', 'p.user_id');
        if ($state === 'deleted') $query->whereNotNull('i.deleted_at');
        elseif ($state !== 'all') {
            $query->whereNull('i.deleted_at');
            if ($state === 'blocked') $query->whereNotNull('i.blocked_at');
            else {
                $query->whereNull('i.blocked_at');
                if ($state === 'published') $query->whereNotNull('i.post_id');
                else {
                    $query->whereNull('i.post_id');
                    if ($state === 'pending') $query->whereNull('i.unused_at')->whereNull('i.source_post_id');
                    else $query->where(fn ($q) => $q->whereNotNull('i.unused_at')->orWhereNotNull('i.source_post_id'));
                }
            }
        }
        if (isset($params['discussion']) && $params['discussion'] !== '') {
            $discussion = $this->number($params['discussion'], 4294967295);
            $query->whereRaw('COALESCE('.$grammar->wrap('p.discussion_id').', '.$grammar->wrap('i.source_discussion_id').') = ?', [$discussion]);
        }
        $user = $params['user'] ?? '';
        if (! is_string($user) || strlen($user) > 180) throw new Problem(I18n::t('api.post_images_invalid_request'), 422);
        if ($user !== '') {
            if (ctype_digit($user)) $query->where('i.user_id', $this->number($user, 4294967295));
            else {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $user).'%';
                $query->where(fn ($q) => $q->whereRaw($grammar->wrap('u.username')." LIKE ? ESCAPE '!'", [$like])->orWhereRaw($grammar->wrap('u.nickname')." LIKE ? ESCAPE '!'", [$like]));
            }
        }
        if (isset($params['image']) && $params['image'] !== '') {
            if (! is_string($params['image']) || ! preg_match('/^[a-f0-9]{32}$/D', $params['image'])) throw new Problem(I18n::t('api.post_images_invalid_request'), 422);
            $query->where('i.id', $params['image']);
        }
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = $query->select('i.*', 'p.id as live_post_id', 'original.id as original_post_id', 'p.discussion_id as live_discussion_id', 'p.number as live_number', 'p.hidden_at', 'd.title as live_title', 'u.username', 'u.nickname', 'a.id as author_id', 'a.username as author_username', 'a.nickname as author_nickname')
            ->orderByDesc('i.created_at')->orderByDesc('i.id')->offset(($page - 1) * $perPage)->limit($perPage)->get();
        return [
            'images' => $rows->map(fn ($row) => $this->data($row))->all(),
            'pagination' => compact('page', 'perPage', 'total', 'pages'),
            'storage' => 'public/assets/ccot-post-images',
            'cleanupError' => (bool) ((json_decode($this->db->table('settings')->where('key', AutoCleanup::STATE)->value('value') ?? '{}', true) ?: [])['error'] ?? false),
        ];
    }

    private function data(object $row): array
    {
        $deleted = $row->deleted_at !== null;
        $blocked = $row->blocked_at !== null;
        $discussion = $row->live_discussion_id ?? $row->source_discussion_id;
        $number = $row->live_number ?? $row->source_number;
        $post = $row->live_post_id ?? $row->source_post_id;
        $state = $deleted ? 'deleted' : ($blocked ? 'blocked' : ($row->post_id !== null ? 'published' : ($row->unused_at !== null || $row->source_post_id !== null ? 'unused' : 'pending')));
        $preview = $this->base.'/api/ccot/post-images/'.$row->id.'/preview';
        return [
            'id' => $row->id, 'state' => $state, 'blocked' => $blocked, 'deleted' => $deleted,
            'image' => $deleted ? null : $preview, 'thumb' => $deleted ? null : $preview.'?variant=thumb',
            'width' => (int) $row->width, 'height' => (int) $row->height,
            'bytes' => (int) $row->bytes + (int) $row->thumb_bytes, 'createdAt' => (int) $row->created_at,
            'deletedAt' => $deleted ? (int) $row->deleted_at : null,
            'postId' => $post ? (int) $post : null,
            'postUrl' => $row->live_post_id || $row->original_post_id ? $this->base.'/d/'.$discussion.'/'.$number : null,
            'discussionId' => $discussion ? (int) $discussion : null,
            'discussionTitle' => $row->live_title ?? $row->source_title,
            'postNumber' => $number ? (int) $number : null,
            'postHidden' => $row->hidden_at !== null,
            'sourceRemoved' => $row->source_post_id !== null && $row->original_post_id === null,
            'uploader' => $row->user_id ? ['id' => (int) $row->user_id, 'name' => $row->nickname ?: $row->username, 'url' => $this->base.'/u/'.rawurlencode($row->username)] : null,
            'author' => $row->author_id ? ['id' => (int) $row->author_id, 'name' => $row->author_nickname ?: $row->author_username] : null,
        ];
    }
}
