<?php

namespace BushcraftCN\CCOT;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;

/** Runtime configuration only: never part of the installed package or public API. */
final class SmsConfig
{
    public function __construct(private Config $config, private Crypto $crypto, private SettingsRepositoryInterface $settings, private ?\Flarum\Foundation\Paths $paths = null) {}

    public function path(): string
    {
        $path = getenv('CCOT_SMS_CONFIG_FILE') ?: ($this->config['ccot.sms_config_file'] ?? dirname($this->crypto->file()).'/sms-config.enc');
        $parent = realpath(dirname($path));
        $public = realpath($this->paths?->public ?? dirname($this->config['ccot.site_guard_file'] ?? '').'/public');
        if (! $parent || ! $public || is_link($path) || is_link($path.'.lock') || str_starts_with($parent.DIRECTORY_SEPARATOR, $public.DIRECTORY_SEPARATOR)) throw new Problem(I18n::t('api.the_private_sms_configuration_path_is_unsafe'), 503);
        return $path;
    }

    public function local(): bool
    {
        return $this->config->environment() === 'development' || in_array($this->config->url()->getHost(), ['localhost', '127.0.0.1', '::1'], true);
    }

    public function read(): array
    {
        $value = fn ($key, $default) => $this->settings->get('bushcraftcn-ccot.'.$key, $default);
        $data = [
            'mode' => $value('sms_mode', 'aliyun'), 'enabled' => (bool) $this->config['ccot.real_sms_authorized'],
            'key_id' => '', 'key_secret' => '', 'disable_environment' => false, 'sign' => $value('sms_sign', ''), 'template' => $value('sms_template', ''),
            'variable' => $value('sms_code_variable', 'code'), 'interval' => (int) $value('sms_interval', '60'),
            'phone_limit' => (int) $value('sms_phone_daily_limit', '10'), 'ip_limit' => (int) $value('sms_ip_hourly_limit', '20'),
            'site_limit' => (int) $value('sms_daily_limit', '100'), 'paused' => $value('sms_paused', '0') === '1',
            'test_phone' => '', 'test_limit' => 3, 'grant_id' => '', 'test_expires' => 0,
        ];
        $path = $this->path();
        if (is_file($path)) {
            $saved = json_decode($this->crypto->decrypt(trim(file_get_contents($path))), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($saved)) throw new Problem(I18n::t('api.the_private_sms_configuration_is_invalid'), 503);
            $data = array_replace($data, array_intersect_key($saved, $data));
        }
        // Retired provider configurations must never silently authorize real delivery.
        if ($data['mode'] !== 'aliyun') $data['enabled'] = false;
        $data['mode'] = 'aliyun';
        $data['paused'] = $data['paused'] || $value('sms_paused', '0') === '1';
        if (! $data['disable_environment'] && ! $data['key_id'] && ! $data['key_secret']) {
            $data['key_id'] = getenv('CCOT_ALIYUN_ACCESS_KEY_ID') ?: '';
            $data['key_secret'] = getenv('CCOT_ALIYUN_ACCESS_KEY_SECRET') ?: '';
        }
        return $data;
    }

    public function publicStatus(): array
    {
        $data = $this->read();
        $data['key_configured'] = $data['key_id'] !== '' && $data['key_secret'] !== '';
        $data['key_id_masked'] = $data['key_id'] ? I18n::t('api.key_configured', ['suffix'=>substr($data['key_id'], -4)]) : I18n::t('api.not_configured');
        $data['test_phone_masked'] = $data['test_phone'] ? $this->crypto->mask($data['test_phone']) : '';
        $data['local'] = $this->local();
        unset($data['key_id'], $data['key_secret'], $data['test_phone']);
        return $data;
    }

    public function save(#[\SensitiveParameter] array $body): array
    {
        $lock = fopen($this->path().'.lock', 'c');
        if (! $lock) throw new Problem(I18n::t('api.could_not_lock_the_private_sms_configuration'), 503);
        chmod($this->path().'.lock', 0600);
        try {
            if (! flock($lock, LOCK_EX)) throw new Problem(I18n::t('api.sms_configuration_is_busy_please_retry_later'), 503);
            return $this->saveLocked($body);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function saveLocked(#[\SensitiveParameter] array $body): array
    {
        if (! $this->local() && $this->config->url()->getScheme() !== 'https') throw new Problem(I18n::t('api.enable_https_before_using_a_production_environment'), 503);
        $current = $this->read();
        $next = $current;
        if (isset($body['mode']) && $body['mode'] !== 'aliyun') throw new Problem(I18n::t('api.only_alibaba_cloud_sms_is_supported'), 422);
        $next['mode'] = 'aliyun';
        foreach (['sign', 'template', 'variable'] as $field) {
            if (! is_string($body[$field] ?? null)) throw new Problem(I18n::t('api.check_the_sms_settings'), 422);
            $next[$field] = trim($body[$field]);
        }
        if (strlen($next['sign']) > 300 || strlen($next['template']) > 100 || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/D', $next['variable'])) throw new Problem(I18n::t('api.invalid_sms_signature_template_or_variable_name'), 422);
        foreach (['interval' => [10, 3600], 'phone_limit' => [1, 100], 'ip_limit' => [1, 1000], 'site_limit' => [1, 10000], 'test_limit' => [1, 20]] as $field => [$minimum, $maximum]) {
            $number = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT);
            if ($number === false || $number < $minimum || $number > $maximum) throw new Problem(I18n::t('api.sending_frequency_or_limits_are_outside_the_allowed_range'), 422);
            $next[$field] = $number;
        }
        foreach (['key_id', 'key_secret', 'test_phone'] as $field) {
            if (array_key_exists($field, $body) && ! is_string($body[$field])) throw new Problem(I18n::t('api.check_the_sms_settings'), 422);
        }
        $id = trim((string) ($body['key_id'] ?? ''));
        $secret = trim((string) ($body['key_secret'] ?? ''));
        if (($id !== '') !== ($secret !== '') || strlen($id) > 256 || strlen($secret) > 256) throw new Problem(I18n::t('api.enter_both_accesskey_id_and_secret_when_updating_credentials'), 422);
        if ($id !== '') { $next['key_id'] = $id; $next['key_secret'] = $secret; }
        if (($body['clear_keys'] ?? false) === true) { $next['key_id'] = ''; $next['key_secret'] = ''; $next['disable_environment'] = true; }
        $next['enabled'] = ($body['enabled'] ?? false) === true && ($body['clear_keys'] ?? false) !== true;
        $next['paused'] = ($body['paused'] ?? false) === true;
        $phone = trim((string) ($body['test_phone'] ?? ''));
        if ($phone !== '') $next['test_phone'] = $this->crypto->phone($phone);
        if ($next['enabled'] && (! $next['key_id'] || ! $next['key_secret'] || ! $next['sign'] || ! $next['template'])) throw new Problem(I18n::t('api.complete_the_accesskey_signature_and_template_before_allowing_real_sms'), 422);
        if ($this->local() && $next['enabled']) {
            if (! $next['test_phone']) throw new Problem(I18n::t('api.specify_a_recipient_phone_number_for_real_sms_testing'), 422);
            if (! $current['grant_id'] || ! $current['enabled'] || ($body['restart_test'] ?? false) === true) {
                $next['grant_id'] = bin2hex(random_bytes(16));
                $next['test_expires'] = time() + 7200;
            } elseif ($next['test_phone'] !== $current['test_phone'] || $next['test_limit'] !== $current['test_limit']) {
                throw new Problem(I18n::t('api.restart_the_test_when_changing_its_recipient_or_budget'), 422);
            }
        }
        $path = $this->path();
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        try {
            $handle = fopen($temporary, 'x');
            if (! $handle) throw new \RuntimeException('Cannot create protected configuration');
            chmod($temporary, 0600);
            $cipher = $this->crypto->encrypt(json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))."\n";
            $written = fwrite($handle, $cipher); fclose($handle);
            if ($written !== strlen($cipher) || ! rename($temporary, $path)) throw new \RuntimeException('Cannot save protected configuration');
        } finally { if (is_file($temporary)) unlink($temporary); }
        $this->settings->set('bushcraftcn-ccot.sms_paused', $next['paused'] ? '1' : '0');
        return $this->publicStatus();
    }
}
