<?php
namespace BushcraftCN\CCOT;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\UserRepository;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;


/** Active-extension middleware only; never installed into application configuration. */
final class Guard implements MiddlewareInterface
{
    public static function nativeMaintenance(string $path): bool
    {
        return (bool) preg_match('~^/(extensions(?:/|$)|extension-manager(?:/|$)|extension-bisect$|settings$|groups(?:/|$)|permission$|users(?:/|$)|logo(?:-dark-mode)?$|email-logo$|favicon$|cache$|mail/test$|queue(?:/|$))~', $path);
    }

    /** Only the native current-actor read marker, never content or relationships. */
    public static function nativeReadState(ServerRequestInterface $request, string $path): bool
    {
        if ($request->getMethod() !== 'PATCH' || ! preg_match('~^/discussions/([1-9][0-9]*)$~D', $path, $match)) return false;
        $body = $request->getParsedBody();
        if (! is_array($body) || array_diff(array_keys($body), ['data', 'meta']) || ($body['meta'] ?? []) !== []) return false;
        $data = $body['data'] ?? null;
        if (! is_array($data) || array_diff(array_keys($data), ['type', 'id', 'attributes']) || ($data['type'] ?? null) !== 'discussions' || (! is_string($data['id'] ?? null) && ! is_int($data['id'] ?? null)) || (string) $data['id'] !== $match[1]) return false;
        $attributes = $data['attributes'] ?? null;
        return is_array($attributes) && array_keys($attributes) === ['lastReadPostNumber'] && is_int($attributes['lastReadPostNumber']) && $attributes['lastReadPostNumber'] > 0 && $attributes['lastReadPostNumber'] <= 2147483647;
    }

    public function __construct(private Config $config, private SettingsRepositoryInterface $settings, private ConnectionInterface $db, private UserRepository $users, private Paths $paths) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rawurldecode(preg_replace('~^/api(?=/|$)~', '', $request->getUri()->getPath()));
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) return $handler->handle($request);
        if (! class_exists(\BushcraftCN\CCOT\OperationLock::class)) return $this->protect($request, $handler, $path);
        try {
            $exclusive = str_starts_with($path, '/ccot/account-') || ($request->getMethod() === 'DELETE' && $path === '/extensions/bushcraftcn-ccot');
            return \BushcraftCN\CCOT\OperationLock::run($this->paths, $exclusive, fn () => $this->protect($request, $handler, $path));
        } catch (\BushcraftCN\CCOT\Problem $e) { return $this->deny($e->getMessage(), $e->status); }
    }

    private function protect(ServerRequestInterface $request, RequestHandlerInterface $handler, string $path): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($path === '/settings') {
            foreach ((array) ($request->getParsedBody() ?? []) as $key => $value) {
                if ($key === 'bushcraftcn-ccot.uninstall' || $value === 'bushcraftcn-ccot.uninstall') return $this->deny(I18n::t('api.uninstall_preparation_can_only_be_changed_through_ccot_account_management'), 403);
            }
        }
        $enabled = in_array('bushcraftcn-ccot', json_decode($this->settings->get('extensions_enabled', '[]'), true) ?: [], true)
            && class_exists(\BushcraftCN\CCOT\AuthController::class);
        $maintenance = $actor->isAdmin() && (self::nativeMaintenance($path) || self::nativeReadState($request, $path)
            || in_array($path, ['/ccot/sms-settings', '/ccot/sms-test', '/ccot/sms-test-verify', '/ccot/block'], true)
            || str_starts_with($path, '/ccot/account-')
            || str_starts_with($path, '/ccot/post-images/')
            || (in_array($request->getMethod(), ['PATCH', 'DELETE'], true) && preg_match('~^/ccot/tickets/[1-9][0-9]*$~D', $path)));
        if ($actor->isAdmin() && $request->getMethod() === 'DELETE' && $path === '/extensions/bushcraftcn-ccot') {
            if (! class_exists(\BushcraftCN\CCOT\AccountLifecycle::class)) return $this->deny(I18n::t('api.restore_the_ccot_code_before_completing_uninstall_preparation'), 409);
            try { return resolve(\BushcraftCN\CCOT\AccountLifecycle::class)->uninstall(fn () => $handler->handle($request)); }
            catch (\BushcraftCN\CCOT\Problem $error) { throw $error; }
            catch (\Throwable) { return $this->deny(I18n::t('api.uninstall_is_incomplete_keep_the_mapping_check_the_original_key'), 503); }
        }
        if ($path === '/token' || $path === '/login') {
            $body = $request->getParsedBody() ?? [];
            if (! is_string($body['identification'] ?? null) || strlen($body['identification']) > 150 || ! is_string($body['password'] ?? null) || strlen($body['password']) > 256) return $this->deny(I18n::t('api.enter_a_valid_account_and_password'), 422);
            $user = $this->users->findByIdentification($body['identification']);
            if ($user && ! $user->isAdmin()) {
                if ($user->suspended_until && $user->suspended_until->isFuture()) return $this->deny(I18n::t('api.the_account_is_restricted_contact_an_administrator'), 403);
                if ($this->db->table('ccot_phones')->where('user_id', $user->id)->exists()) return $this->deny(I18n::t('api.this_account_has_a_bound_phone_number_use_phone_login'), 403);
            }
            if ($enabled && $path === '/token' && (! $user || ! $user->isAdmin())) {
                try {
                    $limits = resolve(\BushcraftCN\CCOT\Limiter::class);
                    $limits->take('legacy.ip', (string) $request->getAttribute('ipAddress', $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'), 300, 30);
                    $limits->take('legacy.identity', strtolower($body['identification']), 300, 10);
                } catch (\BushcraftCN\CCOT\Problem $e) { throw $e; }
                catch (\Throwable) { return $this->deny(I18n::t('api.account_verification_data_is_unavailable_contact_an_administrator'), 503); }
            }
            // Core authenticates the original password and creates its normal session.
            // An unbound account's permissions and every write remain restricted below.
            return $handler->handle($request);
        }
        if ($path === '/logout') return $handler->handle($request);
        $preparation = json_decode($this->settings->get('bushcraftcn-ccot.uninstall', '{}'), true) ?: [];
        if (($preparation['phase'] ?? '') === 'purging' && ! $maintenance) return $this->deny(I18n::t('api.account_services_are_being_uninstalled_please_retry_later'), 503);
        if ($enabled && ($this->settings->get('display_name_driver') !== 'nickname' || $this->settings->get('flarum-nicknames.unique') !== '1')) {
            if (! $maintenance) return $this->deny(I18n::t('api.nickname_settings_need_administrator_repair_writing_is_paused'), 503);
        }
        if ($path === '/users' && $request->getMethod() === 'POST') {
            \BushcraftCN\CCOT\Registration::requireOpen($this->settings);
            if (! RequestUtil::isInternal($request) || ! resolve(\BushcraftCN\CCOT\RegistrationContext::class)->verified) return $this->deny(I18n::t('api.registration_requires_sms_verification'), 403);
            return $this->save($request, $handler);
        }
        if (str_starts_with($path, '/ccot/auth/') || $path === '/ccot/tickets') return $handler->handle($request);
        if ($maintenance) return $this->save($request, $handler);
        if ($actor->suspended_until && $actor->suspended_until->isFuture()) return $this->deny(I18n::t('api.the_account_is_restricted_contact_an_administrator'), 403);
        if ($actor->isGuest() || ! $this->db->getSchemaBuilder()->hasTable('ccot_phones')) return $this->deny(I18n::t('api.complete_phone_verification_first'), 403);
        $phone = $this->db->table('ccot_phones')->where('user_id', $actor->id)->first();
        if (! $phone) return $this->deny(I18n::t('api.complete_phone_verification_first'), 403);
        try {
            $crypto = resolve(\BushcraftCN\CCOT\Crypto::class);
            if (! hash_equals($phone->phone_hash, $crypto->digest('phone', $crypto->decrypt($phone->cipher)))) return $this->deny(I18n::t('api.verification_data_needs_administrator_maintenance_writing_is_paused'), 503);
        } catch (\Throwable) {
            return $this->deny(I18n::t('api.verification_data_needs_administrator_maintenance_writing_is_paused'), 503);
        }
        if ($this->db->table('ccot_blocks')->where('phone_hash', $phone->phone_hash)->where('expires_at', '>', time())->exists()) return $this->deny(I18n::t('api.the_account_is_restricted_contact_an_administrator'), 403);
        return $this->save($request, $handler);
    }

    private function save(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'ccot_users_nickname_unique')) return $this->deny(I18n::t('api.the_nickname_is_taken_choose_another_one'), 422);
            throw $e;
        }
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'detail' => $message]]], $status, ['Cache-Control'=>'private, no-store, max-age=0', 'Vary'=>'Cookie, Authorization']);
    }
}
