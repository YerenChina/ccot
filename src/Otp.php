<?php

namespace BushcraftCN\CCOT;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

final class Otp
{
    public function __construct(private ConnectionInterface $db, private Crypto $crypto, private Limiter $limits, private SmsGateway $sms, private SettingsRepositoryInterface $settings, private LoggerInterface $log, private SmsConfig $smsConfig) {}

    public function issue(#[\SensitiveParameter] string $phone, string $purpose, string $ip, ?int $userId): string
    {
        if (! in_array($purpose, ['register', 'login', 'reset', 'old', 'new', 'bind', 'test'], true)) throw new Problem(I18n::t('api.invalid_verification_code_purpose'), 422);
        if ($purpose === 'test' && (! $userId || ! \Flarum\User\User::find($userId)?->isAdmin())) throw new Problem(I18n::t('api.sms_test_permission_required'), 403);
        if (in_array($purpose, ['old', 'new', 'bind'], true) && ! $userId) throw new Problem(I18n::t('api.please_log_in_first'), 401);
        $smsConfig = $this->smsConfig->read();
        if ($smsConfig['paused']) throw new Problem(I18n::t('api.sms_sending_is_paused'), 503);
        $hash = $this->crypto->digest('phone', $phone);
        if ($this->db->table('ccot_blocks')->where('phone_hash', $hash)->where('expires_at', '>', time())->exists()) throw new Problem(I18n::t('api.the_account_is_restricted_contact_an_administrator'), 403);
        $binding = $this->db->table('ccot_phones')->where('phone_hash', $hash)->first();
        if ($purpose === 'register' && $binding) throw new Problem(I18n::t('api.this_phone_number_is_registered_please_log_in'), 409);
        if (in_array($purpose, ['login', 'reset'], true) && ! $binding) throw new Problem(I18n::t('api.the_account_could_not_be_verified_check_the_phone_number'), 401);
        if (in_array($purpose, ['new', 'bind'], true) && $binding) throw new Problem(I18n::t('api.this_phone_number_is_already_bound_to_an_account'), 409);
        $this->sms->assertReady($phone);
        $this->limits->cooldown('sms.phone.interval', $hash, max(10, (int) $smsConfig['interval']));
        $this->limits->take('sms.phone.day', $hash, 86400, max(1, (int) $smsConfig['phone_limit']));
        $this->limits->take('sms.ip.hour', $ip, 3600, max(1, (int) $smsConfig['ip_limit']));
        $this->limits->take('sms.site.day', 'all', 86400, max(1, min(10000, (int) $smsConfig['site_limit'])));
        $id = bin2hex(random_bytes(16));
        $code = (string) random_int(100000, 999999);
        $this->db->table('ccot_codes')->insert([
            'id' => $id, 'phone_hash' => $hash, 'purpose' => $purpose, 'user_id' => $userId,
            'code_hash' => $this->crypto->digest('otp', $id.':'.$code), 'expires_at' => time() + 300,
            'attempts' => 0, 'state' => 'pending',
        ]);
        try {
            $provider = $this->sms->send($id, $phone, $code);
            $this->db->table('ccot_codes')->where('id', $id)->update(['state' => 'sent']);
            $this->log->info('CCOT sms accepted', ['challenge_id' => $id] + $provider);
        } catch (\Throwable $error) {
            $this->db->table('ccot_codes')->where('id', $id)->update(['state' => 'failed']);
            if ((! $error instanceof Problem || $error->providerCode !== null) && $this->limits->take('sms.provider.failures', 'all', 300, 5) >= 5) $this->settings->set('bushcraftcn-ccot.sms_paused', '1');
            $code = $error instanceof Problem ? $error->providerCode : null;
            $this->log->warning('CCOT sms unavailable', ['challenge_id' => $id, 'provider_code' => $code]);
            throw new Problem($error instanceof Problem ? $error->getMessage() : I18n::t('api.sms_services_are_unavailable_please_retry_later'), $error instanceof Problem ? $error->status : 503, $code, $error instanceof Problem ? $error->retryAfter : null);
        }
        return $id;
    }

    /** Reserve the attempt before the consuming transaction, so failures cannot reset its counter. */
    public function attempt(string $id): void
    {
        $changed = $this->db->table('ccot_codes')->where('id', $id)->where('attempts', '<', 5)->increment('attempts');
        if (! $changed) throw new Problem(I18n::t('api.the_code_is_invalid_or_its_attempt_limit_is_exhausted'), 422);
    }

    /** Called only inside the account mutation transaction; row lock makes consumption atomic. */
    public function consume(string $id, #[\SensitiveParameter] string $phone, #[\SensitiveParameter] string $code, string $purpose, ?int $userId): void
    {
        $row = $this->db->table('ccot_codes')->where('id', $id)->lockForUpdate()->first();
        if (! $row || $row->state !== 'sent' || $row->expires_at < time() || $row->attempts > 5
            || $row->purpose !== $purpose || $row->user_id !== $userId
            || ! hash_equals($row->phone_hash, $this->crypto->digest('phone', $phone))
            || ! hash_equals($row->code_hash, $this->crypto->digest('otp', $id.':'.$code))) {
            throw new Problem(I18n::t('api.the_code_is_incorrect_expired_or_already_used'), 422);
        }
        $this->db->table('ccot_codes')->where('id', $id)->update(['state' => 'consumed']);
    }
}
