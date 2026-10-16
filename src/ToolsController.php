<?php

namespace BushcraftCN\CCOT;

use Flarum\Foundation\Config;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final class ToolsController implements RequestHandlerInterface
{
    public const SETTINGS = ['organization', 'organization_url', 'contact', 'icp', 'police', 'agreement', 'privacy', 'rules', 'faq', 'sms_mode', 'sms_sign', 'sms_template', 'sms_daily_limit', 'sms_paused'];
    public function __construct(private ConnectionInterface $db, private Crypto $crypto, private Limiter $limits, private SettingsRepositoryInterface $settings, private Config $config, private LoggerInterface $log, private SmsConfig $smsConfig, private TicketFiles $files) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $path = $request->getUri()->getPath();
            $b = $request->getParsedBody() ?? [];
            if (! is_array($b)) throw new Problem(I18n::t('api.invalid_request_body_format'), 422);
            if (str_ends_with($path, '/config')) {
                $data = [];
                foreach (self::SETTINGS as $key) {
                    if (str_starts_with($key, 'sms_')) continue;
                    $data[$key] = $this->settings->get('bushcraftcn-ccot.'.$key, '');
                }
                $data['organization_url'] = FooterSettings::url($data['organization_url']);
                $data['allowSignUp'] = Registration::isOpen($this->settings);
                return PrivateResponse::json($data);
            }
            if (str_ends_with($path, '/health')) {
                if (! $actor->isAdmin()) throw new Problem(I18n::t('api.administrator_permission_required'), 403);
                return PrivateResponse::json([
                    'mode' => $this->smsConfig->read()['mode'],
                    'keyReady' => true, 'automaticSetup' => true,
                    'nicknameUnique' => $this->settings->get('flarum-nicknames.unique') === '1',
                    'smsRequestsToday' => $this->limits->count('sms.site.day', 'all', 86400),
                    'smsPaused' => $this->smsConfig->read()['paused'],
                    'realSmsAuthorized' => $this->smsConfig->read()['enabled'],
                    'ipv6Ready' => is_file($this->config['ccot.region_v6_file'] ?? ''),
                ]);
            }
            if (str_ends_with($path, '/block')) {
                if (! $actor->isAdmin()) throw new Problem(I18n::t('api.administrator_permission_required'), 403);
                $userId = (int) ($b['userId'] ?? 0);
                $row = $this->db->table('ccot_phones')->where('user_id', $userId)->first();
                $hash = $row ? $row->phone_hash : (isset($b['phone']) ? $this->crypto->digest('phone', $this->crypto->phone($b['phone'])) : null);
                if (! $hash) throw new Problem(I18n::t('api.the_account_has_no_verified_phone_binding'), 422);
                if (($b['blocked'] ?? false) === true) {
                    $days = max(1, min(365, (int) ($b['days'] ?? 180)));
                    $this->db->table('ccot_blocks')->updateOrInsert(['phone_hash' => $hash], ['actor_id' => $actor->id, 'expires_at' => time() + $days * 86400]);
                } else {
                    $this->db->table('ccot_blocks')->where('phone_hash', $hash)->delete();
                }
                $this->log->info('CCOT phone restriction changed', ['actor_id' => $actor->id, 'user_id' => $userId, 'blocked' => (bool) ($b['blocked'] ?? false)]);
                return PrivateResponse::json(['ok' => true]);
            }
            if ($request->getMethod() === 'POST' && str_ends_with($path, '/tickets')) {
                foreach (['message', 'contact', 'type'] as $field) {
                    if (array_key_exists($field, $b) && (! is_string($b[$field]) || ! mb_check_encoding($b[$field], 'UTF-8'))) throw new Problem(I18n::t('api.enter_valid_ticket_text'), 422);
                }
                if (array_key_exists('image_count', $b) && ! ((is_int($b['image_count']) && $b['image_count'] >= 0 && $b['image_count'] <= TicketFiles::MAX_FILES) || (is_string($b['image_count']) && preg_match('/^[0-3]$/D', $b['image_count'])))) throw new Problem(I18n::t('api.invalid_image_count'), 422);
                $ip = (string) $request->getAttribute('ipAddress', 'unknown');
                $this->limits->take('ticket.ip', $ip, 3600, 10);
                $message = trim((string) ($b['message'] ?? ''));
                $contact = trim((string) ($b['contact'] ?? ''));
                $type = (string) ($b['type'] ?? '');
                if (! in_array($type, ['report', 'appeal', 'account', 'privacy'], true) || strlen($message) < 3 || strlen($message) > 12000 || strlen($contact) > 600 || ($actor->isGuest() && ! $contact)) throw new Problem(I18n::t('api.enter_a_type_details_and_required_contact_information'), 422);
                $images = $this->files->prepare($request->getUploadedFiles()['images'] ?? []);
                if (isset($b['image_count']) && (int) $b['image_count'] !== count($images)) throw new Problem(I18n::t('api.some_images_were_not_uploaded_check_the_limits_of_2'), 422);
                $created = time();
                $written = [];
                try {
                    $id = $this->db->transaction(function () use ($actor, $type, $message, $contact, $created, $images, &$written) {
                        $id = $this->db->table('ccot_tickets')->insertGetId(['user_id' => $actor->isGuest() ? null : $actor->id, 'type' => $type, 'content_version' => 1, 'message' => $message, 'contact' => $contact ?: null, 'created_at' => $created, 'updated_at' => $created]);
                        $this->files->store($id, $images, $written);
                        return $id;
                    });
                } catch (\Throwable $error) {
                    if (! $this->files->removeFiles($written)) $this->log->warning('CCOT failed submission image cleanup pending');
                    throw $error;
                }
                return PrivateResponse::json(['id' => $id, 'number' => self::ticketNumber($id, $created), 'message' => I18n::t('api.received_please_wait_for_an_administrator')], 201);
            }
            if ($actor->isGuest()) throw new Problem(I18n::t('api.please_log_in_first'), 401);
            if ($request->getMethod() === 'DELETE') {
                if (! $actor->isAdmin()) throw new Problem(I18n::t('api.administrator_permission_required'), 403);
                $id = (int) ($request->getAttribute('routeParameters')['id'] ?? basename($path));
                $removed = $this->files->deleteTicket($id);
                $this->log->info('CCOT ticket deleted', ['actor_id' => $actor->id, 'ticket_id' => $id, 'file_cleanup_pending' => ! $removed]);
                return PrivateResponse::json(['ok' => true, 'message' => $removed ? I18n::t('api.the_ticket_and_attachments_were_deleted') : I18n::t('api.the_ticket_was_deleted_attachment_files_await_cleanup_check_private')]);
            }
            if ($request->getMethod() === 'PATCH') {
                if (! $actor->isAdmin()) throw new Problem(I18n::t('api.administrator_permission_required'), 403);
                foreach (['reply', 'status'] as $field) {
                    if (array_key_exists($field, $b) && (! is_string($b[$field]) || ! mb_check_encoding($b[$field], 'UTF-8'))) throw new Problem(I18n::t('api.enter_a_valid_reply_and_status'), 422);
                }
                $id = (int) ($request->getAttribute('routeParameters')['id'] ?? basename($path));
                $reply = (string) ($b['reply'] ?? '');
                $status = (string) ($b['status'] ?? '');
                if (strlen($reply) > 12000 || ! in_array($status, ['pending', 'working', 'closed'], true)) throw new Problem(I18n::t('api.invalid_reply_or_status'), 422);
                $values = ['reply' => $reply ?: null, 'status' => $status, 'updated_at' => time()];
                if (array_key_exists('hold', $b)) {
                    if (! is_bool($b['hold'])) throw new Problem(I18n::t('api.invalid_hold_value'), 422);
                    $values['hold'] = $b['hold'];
                }
                $changed = $this->db->table('ccot_tickets')->where('id', $id)->update($values);
                if (! $changed) throw new Problem(I18n::t('api.ticket_not_found'), 404);
                $this->log->info('CCOT ticket updated', ['actor_id' => $actor->id, 'ticket_id' => $id]);
                return PrivateResponse::json(['ok' => true]);
            }
            $q = $this->db->table('ccot_tickets');
            $query = $request->getQueryParams();
            if (! $actor->isAdmin() || ($query['mine'] ?? '') === '1') $q->where('user_id', $actor->id);
            $scope = $query['scope'] ?? 'all';
            if (! in_array($scope, ['all', 'active', 'history'], true)) throw new Problem(I18n::t('api.invalid_ticket_list_scope'), 422);
            $counts = ['pending' => 0, 'working' => 0, 'closed' => 0];
            foreach ((clone $q)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->get() as $count) {
                if (array_key_exists($count->status, $counts)) $counts[$count->status] = (int) $count->total;
            }
            $counts['active'] = $counts['pending'] + $counts['working'];
            if ($scope === 'active') $q->whereIn('status', ['pending', 'working']);
            if ($scope === 'history') $q->where('status', 'closed');
            $page = max(1, min(100000, (int) ($query['page'] ?? 1)));
            $total = $q->count();
            $page = min($page, max(1, (int) ceil($total / 50)));
            if ($scope === 'history') $q->orderByDesc('updated_at');
            else $q->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'working' THEN 1 ELSE 2 END")->orderByDesc('created_at');
            $rows = $q->orderByDesc('id')->offset(($page - 1) * 50)->limit(50)->get()->all();
            $images = $this->files->metadata(array_column($rows, 'id'));
            foreach ($rows as $row) {
                if ((int) ($row->content_version ?? 0) !== 1) foreach (['message', 'contact', 'reply'] as $key) if ($row->$key) $row->$key = $this->crypto->decrypt($row->$key);
                unset($row->content_version);
                $row->number = self::ticketNumber($row->id, $row->created_at);
                $row->images = $images[$row->id] ?? [];
            }
            $result = ['tickets' => $rows, 'page' => $page, 'total' => $total, 'counts' => $counts, 'scope' => $scope];
            if ($actor->isAdmin()) {
                $result['imageStorage'] = $this->files->directory();
                $result['retentionDays'] = TicketFiles::RETENTION_DAYS;
            }
            return PrivateResponse::json($result);
        } catch (Problem $e) {
            return PrivateResponse::json(['errors' => [['status' => (string) $e->status, 'detail' => $e->getMessage()]]], $e->status);
        }
    }

    private static function ticketNumber(int $id, int $created): string
    {
        $date = (new \DateTimeImmutable('@'.$created))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Ymd');
        return 'GD'.$date.'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }
}
