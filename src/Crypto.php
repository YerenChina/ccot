<?php

namespace BushcraftCN\CCOT;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use RuntimeException;

final class Crypto
{
    private string $key;
    private string $path;

    public function __construct(Config $config, ?Paths $paths = null)
    {
        $this->path = $config['ccot.secret_file'] ?? ($paths ? $paths->storage.'/ccot-private/key' : '');
        $key = is_file($this->path) && ! is_link($this->path) ? base64_decode(trim(file_get_contents($this->path)), true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('CCOT key is missing or invalid');
        }
        $this->key = $key;
    }

    public function file(): string { return $this->path; }

    public function digest(string $purpose, #[\SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, hash_hkdf('sha256', $this->key, 32, $purpose));
    }

    public function encrypt(#[\SensitiveParameter] string $value): string
    {
        $iv = random_bytes(12);
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, 'ccot:v1');
        if ($encrypted === false) throw new RuntimeException('CCOT encryption failed');
        return 'v1:'.base64_encode($iv.$tag.$encrypted);
    }

    public function decrypt(#[\SensitiveParameter] string $value): string
    {
        if (! str_starts_with($value, 'v1:')) throw new RuntimeException('CCOT cipher version is invalid');
        $raw = base64_decode(substr($value, 3), true);
        if ($raw === false || strlen($raw) < 28) throw new RuntimeException('CCOT cipher is invalid');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'ccot:v1');
        if ($plain === false) throw new RuntimeException('CCOT cipher authentication failed');
        return $plain;
    }

    public function phone(#[\SensitiveParameter] mixed $phone): string
    {
        if (! is_string($phone) || ! preg_match('/^1[3-9][0-9]{9}$/D', $phone)) {
            throw new Problem(I18n::t('api.enter_a_mainland_china_phone_number'), 422);
        }
        return $phone;
    }

    public function mask(#[\SensitiveParameter] string $phone): string
    {
        return substr($phone, 0, 3).'****'.substr($phone, -4);
    }
}
