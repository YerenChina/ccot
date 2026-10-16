<?php
namespace BushcraftCN\CCOT;

use Flarum\Foundation\{Config, Paths};
use Illuminate\Database\ConnectionInterface;

/** Normal Flarum activation/upgrade prepares private storage; never replaces an old key. */
final class Setup
{
    public function __construct(private Config $config, private Paths $paths, private ConnectionInterface $db) {}

    public function prepare(): void
    {
        $directory = $this->paths->storage.'/ccot-private';
        $public = realpath($this->paths->public);
        $storage = realpath($this->paths->storage);
        if (! $public || ! $storage || is_link($directory) || str_starts_with($storage.'/', $public.'/')) throw new \RuntimeException('CCOT private storage is unsafe');
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true)) throw new \RuntimeException('CCOT private storage is not writable');
        $path = $this->config['ccot.secret_file'] ?? $directory.'/key';
        $parent = realpath(dirname($path));
        if (! $parent || is_link($path) || str_starts_with($parent.'/', $public.'/')) throw new \RuntimeException('CCOT key path is unsafe');
        if (! is_file($path)) {
            // A missing configured key, SMS file or any keyed row means recovery, not initialization.
            $used = ! empty($this->config['ccot.secret_file']) || is_file($this->config['ccot.sms_config_file'] ?? $parent.'/sms-config.enc') || is_file(getenv('CCOT_SMS_CONFIG_FILE') ?: '');
            foreach (['ccot_phones', 'ccot_codes', 'ccot_limits', 'ccot_blocks'] as $table) {
                if ($this->db->getSchemaBuilder()->hasTable($table) && $this->db->table($table)->exists()) $used = true;
            }
            if ($this->db->getSchemaBuilder()->hasTable('ccot_tickets') && $this->db->table('ccot_tickets')->where('message', 'like', 'v1:%')->exists()) $used = true;
            if ($used) throw new \RuntimeException('CCOT original key is missing; restore it from backup');
            $handle = @fopen($path, 'xb');
            if (! $handle) throw new \RuntimeException('CCOT key cannot be initialized');
            try {
                $key = base64_encode(random_bytes(32))."\n";
                if (! @chmod($path, 0600) || fwrite($handle, $key) !== strlen($key) || ! fflush($handle)) throw new \RuntimeException('CCOT key cannot be written');
            } catch (\Throwable $e) { @unlink($path); throw $e; }
            finally { fclose($handle); }
        }
        $crypto = new Crypto($this->config, $this->paths); // Validate without changing existing bytes.
        if ($this->db->getSchemaBuilder()->hasTable('ccot_phones')) foreach ($this->db->table('ccot_phones')->cursor() as $phone) {
            if (! hash_equals($phone->phone_hash, $crypto->digest('phone', $crypto->decrypt($phone->cipher)))) throw new \RuntimeException('CCOT original key does not match existing bindings');
        }
        $sms = getenv('CCOT_SMS_CONFIG_FILE') ?: ($this->config['ccot.sms_config_file'] ?? $parent.'/sms-config.enc');
        if (is_file($sms)) {
            if (is_link($sms) || ! is_array(json_decode($crypto->decrypt(trim(file_get_contents($sms))), true, 32, JSON_THROW_ON_ERROR))) throw new \RuntimeException('CCOT private SMS configuration is invalid');
        }
    }

    public static function write(string $file, string $bytes, int $mode = 0600): void
    {
        if (is_link($file)) throw new \RuntimeException('CCOT refuses symbolic-link writes');
        $tmp = tempnam(dirname($file), '.ccot-');
        try {
            if (! $tmp || ! chmod($tmp, $mode) || file_put_contents($tmp, $bytes) !== strlen($bytes) || ! rename($tmp, $file)) throw new \RuntimeException('CCOT atomic write failed');
        } finally { if ($tmp && is_file($tmp)) @unlink($tmp); }
    }

    /** Detach only the known old installer wrapper; preserve unrelated application extenders. */
    public function detachLegacy(): void
    {
        $extend = $this->paths->base.'/extend.php';
        $original = $this->paths->base.'/extend.ccot-original.php';
        if (! is_file($extend) || is_link($extend)) throw new \RuntimeException('CCOT application extenders are unavailable');
        $contents = file_get_contents($extend);
        $wrapper = "<?php\n// CCOT application guard; original application extenders preserved.\nreturn array_merge(require __DIR__.'/extend.ccot-original.php', require __DIR__.'/ccot-guard.php');\n";
        if ($contents === $wrapper && is_file($original) && ! is_link($original)) {
            self::write($extend, file_get_contents($original));
            if (! unlink($original)) throw new \RuntimeException('CCOT legacy wrapper cleanup failed');
        } elseif (str_contains($contents, "require __DIR__.'/ccot-guard.php'")) {
            self::write($extend, str_replace("require __DIR__.'/ccot-guard.php'", '[]', $contents));
        } elseif (str_contains($contents, 'ccot-guard.php')) {
            throw new \RuntimeException('CCOT legacy integration was customized; detach its guard explicitly before continuing');
        }
        $guard = $this->config['ccot.site_guard_file'] ?? $this->paths->base.'/ccot-guard.php';
        if (is_file($guard)) {
            if (is_link($guard) || ! str_contains(file_get_contents($guard), 'class CcotSiteGuard')) throw new \RuntimeException('CCOT legacy guard file is unrecognized');
            if (! unlink($guard)) throw new \RuntimeException('CCOT legacy guard cleanup failed');
        }
    }
}
