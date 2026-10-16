<?php

namespace BushcraftCN\CCOT;

use AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest;
use AlibabaCloud\Dara\Models\RuntimeOptions;
use AlibabaCloud\Dara\RetryPolicy\RetryOptions;
use Darabonba\OpenApi\Models\Config as AlibabaConfig;
use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;

final class SmsGateway
{
    public function __construct(private Config $config, private SettingsRepositoryInterface $settings, private Crypto $crypto, private SmsConfig $smsConfig, private Limiter $limits) {}

    /** Read-only preflight: configuration failures must not consume OTP quotas. */
    public function assertReady(#[\SensitiveParameter] string $phone): array
    {
        $sms = $this->smsConfig->read();
        if ($sms['paused']) throw new Problem(I18n::t('api.sms_sending_is_paused_unpause_and_save_it_in_administration'), 503);
        $mode = $sms['mode'];
        if ($mode !== 'aliyun' || ! $sms['enabled']) throw new Problem(I18n::t('api.real_sms_is_disabled_select_allow_real_sms_sending_and'), 503);
        if (! $phone) throw new Problem(I18n::t('api.save_the_test_recipient_phone_number_first'), 422);
        if ($this->smsConfig->local() && (! $sms['test_phone'] || ! hash_equals($this->crypto->digest('phone', $sms['test_phone']), $this->crypto->digest('phone', $phone)))) throw new Problem(I18n::t('api.local_sms_testing_only_allows_the_configured_recipient'), 403);
        if (! $sms['key_id'] || ! $sms['key_secret'] || ! $sms['sign'] || ! $sms['template']) throw new Problem(I18n::t('api.sms_settings_are_incomplete_enter_the_accesskey_signature_and_template'), 503);
        if ($this->smsConfig->local()) $this->limits->checkScoped($sms['grant_id'], $sms['test_limit'], $sms['test_expires']);
        return $sms;
    }

    public function send(string $id, #[\SensitiveParameter] string $phone, #[\SensitiveParameter] string $code): array
    {
        $sms = $this->assertReady($phone);
        $keyId = $sms['key_id']; $keySecret = $sms['key_secret']; $sign = $sms['sign']; $template = $sms['template'];
        if ($this->smsConfig->local()) $this->limits->scoped($sms['grant_id'], $sms['test_limit'], $sms['test_expires']);
        // Never include provider exceptions, request bodies or credentials in user errors/logs.
        try {
            $client = new Dysmsapi(new AlibabaConfig(['accessKeyId' => $keyId, 'accessKeySecret' => $keySecret, 'endpoint' => 'dysmsapi.aliyuncs.com', 'retryOptions' => new RetryOptions(['retryable' => false])]));
            $result = $client->sendSmsWithOptions(new SendSmsRequest([
                'phoneNumbers' => $phone, 'signName' => $sign, 'templateCode' => $template,
                'templateParam' => json_encode([$sms['variable'] => $code]),
            ]), new RuntimeOptions(['connectTimeout' => 3000, 'readTimeout' => 5000, 'autoretry' => false]));
            if ($result->body->code !== 'OK') throw new Problem(I18n::t('api.sms_services_are_unavailable_please_retry_later'), 503, self::safeCode($result->body->code));
            $safeId = fn ($value) => is_string($value) && preg_match('/^[A-Za-z0-9-]{1,128}$/D', $value) ? $value : null;
            return ['provider' => 'aliyun', 'provider_code' => 'OK', 'request_id' => $safeId($result->body->requestId), 'biz_id' => $safeId($result->body->bizId)];
        } catch (Problem $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new Problem(I18n::t('api.sms_services_are_unavailable_please_retry_later'), 503, self::safeCode($error->getCode()));
        }
    }

    private static function safeCode(mixed $code): string
    {
        $value = is_string($code) ? strtoupper($code) : '';
        return preg_match('/^(ISV|ISP|SDK|CLIENT)\.[A-Z0-9_]{1,80}$/D', $value) ? $value : 'CLIENT.SEND_FAILED';
    }
}
