<?php

namespace BushcraftCN\CCOT;

use Flarum\Foundation\Paths;

/** One site lock serializes export/uninstall against account and settings writes. */
final class OperationLock
{
    private static array $held = [];
    private static array $remove = [];
    private static array $purgeHandles = [];

    /** Down migrations outlive a single callback; hold one request-scoped exclusive lease. */
    public static function holdForPurge(Paths $paths): bool
    {
        $path = $paths->storage.'/ccot-private/lifecycle.lock';
        if (isset(self::$held[$path])) {
            if (! self::$held[$path]) throw new Problem(I18n::t('api.account_maintenance_is_busy_please_retry_later'), 409);
            return false;
        }
        $directory = dirname($path);
        if (is_link($directory) || is_link($path) || (! is_dir($directory) && ! @mkdir($directory, 0700, true))) throw new Problem(I18n::t('api.the_account_maintenance_directory_is_unavailable'), 503);
        $handle = @fopen($path, 'c');
        if (! $handle || ! @chmod($path, 0600)) { if ($handle) fclose($handle); throw new Problem(I18n::t('api.the_account_maintenance_lock_is_unavailable'), 503); }
        $until = microtime(true) + 10;
        while (! flock($handle, LOCK_EX|LOCK_NB)) {
            if (microtime(true) >= $until) { fclose($handle); throw new Problem(I18n::t('api.account_maintenance_is_busy_please_retry_later'), 409); }
            usleep(20000);
        }
        self::$held[$path] = true;
        self::$purgeHandles[$path] = $handle;
        register_shutdown_function(fn () => self::releasePurge($paths));
        return true;
    }

    public static function releasePurge(Paths $paths): void
    {
        $path = $paths->storage.'/ccot-private/lifecycle.lock';
        if (! isset(self::$purgeHandles[$path])) return;
        flock(self::$purgeHandles[$path], LOCK_UN); fclose(self::$purgeHandles[$path]);
        unset(self::$purgeHandles[$path], self::$held[$path]);
        if (isset(self::$remove[$path])) {
            unset(self::$remove[$path]);
            if (is_file($path) && ! is_link($path)) @unlink($path);
            @rmdir(dirname($path));
        }
    }

    public static function run(Paths $paths, bool $exclusive, callable $operation): mixed
    {
        $path = $paths->storage.'/ccot-private/lifecycle.lock';
        if (isset(self::$held[$path])) {
            if ($exclusive && ! self::$held[$path]) throw new Problem(I18n::t('api.account_maintenance_is_busy_please_retry_later'), 409);
            return $operation();
        }
        $directory = dirname($path);
        if (is_link($directory) || is_link($path) || (! is_dir($directory) && ! @mkdir($directory, 0700, true))) throw new Problem(I18n::t('api.the_account_maintenance_directory_is_unavailable'), 503);
        $handle = @fopen($path, 'c');
        if (! $handle || ! @chmod($path, 0600)) {
            if ($handle) fclose($handle);
            throw new Problem(I18n::t('api.the_account_maintenance_lock_is_unavailable'), 503);
        }
        // A bounded wait avoids tying up PHP indefinitely when another worker is busy.
        $until = microtime(true) + 10;
        while (! flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            if (microtime(true) >= $until) { fclose($handle); throw new Problem(I18n::t('api.account_maintenance_is_busy_please_retry_later'), 409); }
            usleep(20000);
        }
        self::$held[$path] = $exclusive;
        try { return $operation(); }
        finally {
            unset(self::$held[$path]);
            flock($handle, LOCK_UN); fclose($handle);
            if (isset(self::$remove[$path])) {
                unset(self::$remove[$path]);
                if (is_file($path) && ! is_link($path)) @unlink($path);
                @rmdir($directory);
            }
        }
    }

    public static function removeAfterUnlock(Paths $paths): void
    {
        self::$remove[$paths->storage.'/ccot-private/lifecycle.lock'] = true;
    }
}
