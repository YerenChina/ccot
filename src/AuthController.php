<?php

namespace BushcraftCN\CCOT;

use Flarum\Api\Client;
use Flarum\Http\RequestUtil;
use Flarum\Http\SessionAccessToken;
use Flarum\Http\SessionAuthenticator;
use Flarum\User\Event\LoggedIn;
use Flarum\User\User;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final class AuthController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db, private Crypto $crypto, private Otp $otp, private Limiter $limits, private Client $api, private RegistrationContext $context, private SessionAuthenticator $auth, private Dispatcher $events, private LoggerInterface $log, private Region $region, private SettingsRepositoryInterface $settings) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $b = $request->getParsedBody() ?? [];
            if (! is_array($b)) throw new Problem(I18n::t('api.invalid_request_body'), 422);
            foreach (['password', 'challenge', 'code', 'oldChallenge', 'oldCode', 'nickname', 'purpose'] as $field) {
                if (isset($b[$field]) && (! is_string($b[$field]) || strlen($b[$field]) > ($field === 'nickname' ? 1000 : 256))) throw new Problem(I18n::t('api.check_your_input'), 422);
            }
            $action = basename($request->getUri()->getPath());
            if ($action === 'login' && isset($b['mode']) && ! in_array($b['mode'], ['password', 'sms'], true)) throw new Problem(I18n::t('api.invalid_login_method'), 422);
            // Refuse before charging any authentication/SMS budget or consuming a code.
            if ($action === 'register' || ($action === 'code' && ($b['purpose'] ?? null) === 'register')) Registration::requireOpen($this->settings);
            $actor = RequestUtil::getActor($request);
            $phone = $this->crypto->phone($b['phone'] ?? null);
            $hash = $this->crypto->digest('phone', $phone);
            // Use the address resolved by core (REMOTE_ADDR), never trust forwarding headers here.
            $ip = (string) $request->getAttribute('ipAddress', $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            if ($action === 'code') {
                $purpose = (string) ($b['purpose'] ?? '');
                if ($purpose === 'old') {
                    $existing = $this->db->table('ccot_phones')->where('user_id', $actor->id)->first();
                    if (! $existing || ! hash_equals($existing->phone_hash, $hash)) throw new Problem(I18n::t('api.use_the_currently_bound_phone_number'), 422);
                }
                $id = $this->otp->issue($phone, $purpose, $ip, in_array($purpose, ['old', 'new', 'bind'], true) ? $actor->id : null);
                return PrivateResponse::json(['challenge' => $id, 'message' => I18n::t('api.the_code_request_was_accepted_check_your_sms')]);
            }
            $this->limits->take('auth.ip', $ip, 300, 30);
            $this->limits->take('auth.phone', $hash, 300, 10);
            $passwordLogin = $action === 'login' && ($b['mode'] ?? '') === 'password';
            if (! $passwordLogin) $this->otp->attempt((string) ($b['challenge'] ?? ''));
            if ($action === 'change') $this->otp->attempt((string) ($b['oldChallenge'] ?? ''));
            $user = $this->db->transaction(function () use ($action, $actor, $phone, $hash, $b, $passwordLogin, $request, $ip) {
                if ($this->db->table('ccot_blocks')->where('phone_hash', $hash)->where('expires_at', '>', time())->exists()) throw new Problem(I18n::t('api.the_account_is_restricted_contact_an_administrator'), 403);
                $binding = $this->db->table('ccot_phones')->where('phone_hash', $hash)->lockForUpdate()->first();
                if ($action === 'register') {
                    if ($binding) throw new Problem(I18n::t('api.this_phone_number_is_registered_please_log_in'), 409);
                    if (! is_string($b['password'] ?? null) || strlen($b['password']) > 256 || trim((string) ($b['nickname'] ?? '')) === '') throw new Problem(I18n::t('api.enter_a_nickname_and_valid_password'), 422);
                    if (($b['adult'] ?? false) !== true || ($b['agreement'] ?? false) !== true) throw new Problem(I18n::t('api.confirm_that_you_are_an_adult_and_have_read_the'), 422);
                    $this->otp->consume($b['challenge'], $phone, (string) ($b['code'] ?? ''), 'register', null);
                    $this->context->verified = true;
                    try {
                        // Official API supplies nickname uniqueness, generated username and password validation.
                        $response = $this->api->withParentRequest($request)->withBody(['data' => ['type' => 'users', 'attributes' => [
                            'nickname' => trim((string) ($b['nickname'] ?? '')), 'password' => (string) ($b['password'] ?? ''), 'email' => null,
                        ]]])->post('/users');
                        if ($response->getStatusCode() !== 201) {
                            $errors = json_decode((string) $response->getBody(), true)['errors'] ?? [];
                            $this->log->warning('CCOT native registration rejected', ['status' => $response->getStatusCode(), 'fields' => array_map(fn ($error) => $error['source']['pointer'] ?? '', $errors)]);
                            throw new Problem(I18n::t('api.the_nickname_is_taken_or_registration_details_are_invalid_check'), 422);
                        }
                        $data = json_decode((string) $response->getBody(), true);
                        $user = User::findOrFail($data['data']['id']);
                    } finally {
                        $this->context->verified = false;
                    }
                    $this->db->table('ccot_phones')->insert(['user_id' => $user->id, 'phone_hash' => $hash, 'cipher' => $this->crypto->encrypt($phone), 'verified_at' => time(), 'region' => $this->region->locate($ip)]);
                    return $user;
                }
                if (in_array($action, ['bind', 'change'], true)) {
                    $actor->assertRegistered();
                    if ($binding) throw new Problem(I18n::t('api.this_phone_number_is_already_bound_to_an_account'), 409);
                    $existing = $this->db->table('ccot_phones')->where('user_id', $actor->id)->lockForUpdate()->first();
                    if ($action === 'bind' && $existing) throw new Problem(I18n::t('api.use_the_phone_change_procedure'), 409);
                    if ($action === 'change') {
                        if (! $existing) throw new Problem(I18n::t('api.bind_a_phone_number_first'), 422);
                        $old = $this->crypto->decrypt($existing->cipher);
                        $this->otp->consume($b['oldChallenge'], $old, (string) ($b['oldCode'] ?? ''), 'old', $actor->id);
                    }
                    $this->otp->consume($b['challenge'], $phone, (string) ($b['code'] ?? ''), $action === 'change' ? 'new' : 'bind', $actor->id);
                    $this->db->table('ccot_phones')->updateOrInsert(['user_id' => $actor->id], ['phone_hash' => $hash, 'cipher' => $this->crypto->encrypt($phone), 'verified_at' => time(), 'region' => $this->region->locate($ip)]);
                    $actor->accessTokens()->delete();
                    $this->log->info('CCOT phone binding changed', ['user_id' => $actor->id]);
                    return $actor;
                }
                if (! $binding || ! in_array($action, ['login', 'reset'], true)) throw new Problem(I18n::t('api.login_failed_check_the_account_or_register_first'), 401);
                $user = User::findOrFail($binding->user_id);
                if ($user->suspended_until && $user->suspended_until->isFuture()) throw new Problem(I18n::t('api.the_account_is_restricted_contact_an_administrator'), 403);
                if ($passwordLogin) {
                    if (! $user->checkPassword((string) ($b['password'] ?? ''))) throw new Problem(I18n::t('api.incorrect_phone_number_or_password'), 401);
                } else {
                    $this->otp->consume($b['challenge'], $phone, (string) ($b['code'] ?? ''), $action, null);
                }
                if ($action === 'reset') {
                    $password = $b['password'] ?? '';
                    if (! is_string($password) || strlen($password) < 8 || strlen($password) > 256) throw new Problem(I18n::t('api.the_password_must_contain_8_to_256_bytes'), 422);
                    $user->changePassword($password);
                    $user->save();
                    $user->accessTokens()->delete();
                }
                $this->db->table('ccot_phones')->where('user_id', $user->id)->update(['region' => $this->region->locate($ip)]);
                return $user;
            });
            $this->log->info('CCOT authentication succeeded', ['user_id' => $user->id, 'action' => $action, 'method' => $passwordLogin ? 'password' : 'sms', 'ip' => $ip]);
            $token = SessionAccessToken::generate($user->id);
            $token->touch(request: $request);
            $this->auth->logIn($request->getAttribute('session'), $token);
            $this->events->dispatch(new LoggedIn($user, $token));
            return PrivateResponse::json(['userId' => $user->id]);
        } catch (Problem $e) {
            return PrivateResponse::json(['errors' => [['status' => (string) $e->status, 'detail' => $e->getMessage()]]], $e->status);
        } catch (\Throwable) {
            $this->log->error('CCOT authentication unavailable');
            return PrivateResponse::json(['errors' => [['status' => '503', 'detail' => I18n::t('api.account_services_are_unavailable_please_retry_later')]]], 503);
        }
    }
}
