<?php

namespace BushcraftCN\CCOT;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\HtmlResponse;

/** Preparation stores only a keyed fingerprint, never an exported phone list. */
final class AccountLifecycle
{
    private const STATE = 'bushcraftcn-ccot.uninstall';
    private static array $authorized = [];

    public function __construct(private ConnectionInterface $db, private SettingsRepositoryInterface $settings, private Config $config, private Paths $paths) {}

    private function crypto(): Crypto
    {
        return new Crypto($this->config, $this->paths);
    }

    private function preparation(): array
    {
        return json_decode($this->settings->get(self::STATE, '{}'), true) ?: [];
    }

    private function writeState(array $state): void
    {
        $this->settings->set(self::STATE, json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function rows(): iterable
    {
        return $this->db->table('ccot_phones as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->select(['p.user_id', 'p.phone_hash', 'p.cipher', 'p.verified_at', 'u.username', 'u.password'])
            ->orderBy('p.user_id')->cursor();
    }

    private function fingerprint(): string
    {
        $hash = hash_init('sha256');
        foreach ($this->rows() as $row) hash_update($hash, json_encode([$row->user_id, $row->phone_hash, $row->cipher, $row->verified_at, $row->username], JSON_THROW_ON_ERROR)."\n");
        return $this->crypto()->digest('uninstall-export', hash_final($hash));
    }

    private function compatible(): bool
    {
        if (! $this->db->table('group_user')->where('group_id', 1)->join('users', 'users.id', '=', 'group_user.user_id')->whereNotNull('users.password')->where('users.password', '!=', '')->where('users.username', '!=', '')->exists()) return false;
        foreach ($this->rows() as $row) {
            // Existing identifiers need not match today's NEW-user naming rules.
            if (! is_string($row->username) || trim($row->username) === '' || ! is_string($row->password) || $row->password === '') return false;
        }
        return true;
    }

    public function status(): array
    {
        $state = $this->preparation();
        $phase = $state['phase'] ?? '';
        $exportValid = false;
        if ($phase === 'preparing' && isset($state['fingerprint'])) $exportValid = hash_equals($state['fingerprint'], $this->fingerprint());
        $checks = [
            ['label'=>I18n::t('api.new_registration_is_closed'), 'ok'=>! Registration::isOpen($this->settings)],
            ['label'=>I18n::t('api.native_account_identifiers_and_administrator_login_are_available'), 'ok'=>$phase === 'purging' || $this->compatible()],
            ['label'=>I18n::t('api.a_mapping_for_the_current_accounts_has_been_exported'), 'ok'=>$exportValid],
        ];
        return ['allowSignUp'=>Registration::isOpen($this->settings), 'preparing'=>$phase !== '', 'phase'=>$phase,
            'exportValid'=>$exportValid, 'exportSaved'=>$exportValid && ($state['saved'] ?? false), 'checks'=>$checks];
    }

    public function start(): void
    {
        if (($this->preparation()['phase'] ?? '') === 'purging') throw new Problem(I18n::t('api.cleanup_is_incomplete_retry_uninstall_cancelling_to_resume_use_is'), 409);
        $this->settings->set('allow_sign_up', '0');
        $this->writeState(['phase'=>'preparing', 'saved'=>false]);
    }

    public function cancel(): void
    {
        if (($this->preparation()['phase'] ?? '') === 'purging') throw new Problem(I18n::t('api.cleanup_has_started_and_cannot_be_cancelled_retry_uninstall'), 409);
        $this->settings->delete(self::STATE);
        // Starting/cancelling preparation never implicitly opens registration.
        $this->settings->set('allow_sign_up', '0');
    }

    public function export(): HtmlResponse
    {
        if (($this->preparation()['phase'] ?? '') !== 'preparing') throw new Problem(I18n::t('api.begin_uninstall_preparation_first'), 409);
        if (Registration::isOpen($this->settings)) throw new Problem(I18n::t('api.close_new_registration_before_exporting_the_mapping'), 409);
        if (! $this->compatible()) throw new Problem(I18n::t('api.some_accounts_have_no_usable_native_username_or_password_resolve'), 409);
        $escape = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $crypto = $this->crypto();
        $title = $escape(I18n::t('api.mapping_title'));
        $html = '<!doctype html><html lang="'.$escape(I18n::locale()).'"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'"><title>'.$title.'</title><style>body{font:16px sans-serif;padding:24px}table{border-collapse:collapse}th,td{border:1px solid #aaa;padding:8px 16px;text-align:left}</style></head><body><h1>'.$title.'</h1><p>'.$escape(I18n::t('api.mapping_description')).'</p><table><thead><tr><th>'.$escape(I18n::t('lib.phone_number')).'</th><th>'.$escape(I18n::t('api.username')).'</th><th>'.$escape(I18n::t('api.user_id')).'</th></tr></thead><tbody>';
        foreach ($this->rows() as $row) {
            $phone = $crypto->phone($crypto->decrypt($row->cipher));
            if (! hash_equals($row->phone_hash, $crypto->digest('phone', $phone))) throw new Problem(I18n::t('api.phone_binding_validation_failed_restore_the_correct_original_key_or'), 409);
            $html .= '<tr><td>'.$escape($phone).'</td><td>'.$escape($row->username).'</td><td>'.$escape($row->user_id).'</td></tr>';
        }
        $html .= '</tbody></table></body></html>';
        $this->writeState(['phase'=>'preparing', 'fingerprint'=>$this->fingerprint(), 'saved'=>false]);
        return new HtmlResponse($html, 200, ['Content-Disposition'=>'attachment; filename="CCOT-phone-usernames.html"', 'Cache-Control'=>'no-store, private', 'X-Content-Type-Options'=>'nosniff', 'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'"]);
    }

    public function confirmSaved(): void
    {
        $status = $this->status();
        if (! $status['exportValid'] || $status['allowSignUp']) throw new Problem(I18n::t('api.the_mapping_is_outdated_or_registration_is_open_prepare_and'), 409);
        $state = $this->preparation(); $state['saved'] = true; $this->writeState($state);
    }

    private function files(): array
    {
        $key = $this->config['ccot.secret_file'] ?? $this->paths->storage.'/ccot-private/key';
        return ['secret_file'=>$key, 'sms_config_file'=>$this->config['ccot.sms_config_file'] ?? dirname($key).'/sms-config.enc', 'sms_override_file'=>getenv('CCOT_SMS_CONFIG_FILE') ?: '', 'site_guard_file'=>$this->config['ccot.site_guard_file'] ?? ''];
    }

    private function authorize(): void
    {
        if (self::$authorized[$this->paths->base] ?? false) return;
        $state = $this->preparation();
        if (($state['phase'] ?? '') !== 'purging') {
            if (($state['phase'] ?? '') !== 'preparing') throw new Problem(I18n::t('api.complete_ccot_uninstall_preparation_export_the_latest_phone_to_username'), 409);
            $status = $this->status();
            if (! $status['preparing'] || ! $status['exportSaved'] || $status['allowSignUp'] || ! $this->compatible()) throw new Problem(I18n::t('api.complete_ccot_uninstall_preparation_export_the_latest_phone_to_username'), 409);
            $this->preflightFiles();
            $state['phase'] = 'purging';
            $state['files'] = $this->files();
            $this->writeState($state);
        }
        self::$authorized[$this->paths->base] = true;
    }

    public function uninstall(callable $operation): mixed
    {
        $this->authorize();
        try { return $operation(); }
        finally { unset(self::$authorized[$this->paths->base]); }
    }

    public function assertAuthorized(): void
    {
        // Native Flarum purge is available after disable: validate preparation in the migration.
        // A direct rollback without a saved current mapping is rejected before dropping tables.
        $lease = false;
        try { $lease = OperationLock::holdForPurge($this->paths); $this->authorize(); }
        catch (Problem $error) {
            if ($lease) OperationLock::releasePurge($this->paths);
            throw new \Flarum\Foundation\ValidationException(['ccotUninstall'=>$error->getMessage()]);
        }
    }

    private function preflightFiles(): void
    {
        foreach ([$this->paths->base.'/config.php', $this->paths->base.'/extend.php'] as $file) {
            if (is_link($file) || ! is_file($file) || ! is_writable(dirname($file))) throw new Problem(I18n::t('api.the_application_configuration_directory_is_not_writable_uninstall_has_not'), 409);
        }
        $critical = array_map(fn ($name)=>$this->paths->base.'/'.$name, ['config.php','extend.php','extend.ccot-original.php','site.php','flarum']);
        foreach (['secret_file','sms_config_file','sms_override_file','site_guard_file'] as $name) {
            $path = $this->files()[$name];
            if ($path === '') continue;
            if (! is_string($path) || $path === '' || in_array($path, $critical, true) || is_link($path) || ! is_writable(dirname($path)) || str_starts_with($path, $this->paths->public.'/')) throw new Problem(I18n::t('api.a_ccot_private_file_path_or_permission_is_invalid_uninstall'), 409);
        }
        $directory = $this->paths->storage.'/ccot-ticket-images';
        if (is_link($directory) || (is_dir($directory) && ! is_writable($directory))) throw new Problem(I18n::t('api.the_ticket_image_directory_cannot_be_cleaned_uninstall_has_not'), 409);
        if (is_dir($directory)) foreach (new \DirectoryIterator($directory) as $file) {
            if ($file->isDot()) continue;
            if ($file->isLink() || ! $file->isFile() || ! preg_match('/^[a-f0-9]{32}\.(?:enc|bin)$/D', $file->getFilename())) throw new Problem(I18n::t('api.the_ticket_image_directory_contains_other_files_uninstall_has_not'), 409);
        }
    }

    /** Called before dropping the image index. Hold is deliberately not an uninstall gate. */
    public function purgeImages(): void
    {
        $this->assertAuthorized();
        $directory = $this->paths->storage.'/ccot-ticket-images';
        if (is_link($directory)) throw new Problem(I18n::t('api.the_ticket_image_directory_is_a_symbolic_link_cleanup_is'), 503);
        if (! is_dir($directory)) return;
        foreach (new \DirectoryIterator($directory) as $file) {
            if ($file->isDot()) continue;
            if (! $file->isFile() || $file->isLink() || ! preg_match('/^[a-f0-9]{32}\.(?:enc|bin)$/D', $file->getFilename()) || ! @unlink($file->getPathname())) throw new Problem(I18n::t('api.ticket_image_cleanup_is_incomplete_check_private_directory_permissions_and'), 503);
        }
        if (! @rmdir($directory)) throw new Problem(I18n::t('api.ticket_image_directory_cleanup_is_incomplete_check_permissions_and_retry'), 503);
    }

    private function removeFile(string $path): void
    {
        if ($path === '') return;
        if (is_link($path) || (file_exists($path) && (! is_file($path) || ! @unlink($path)))) throw new Problem(I18n::t('api.ccot_private_file_cleanup_is_incomplete_check_permissions_and_retry'), 503);
    }

    private function writeFile(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), '.ccot-cleanup-');
        if (! $temporary || ! @chmod($temporary, 0600) || file_put_contents($temporary, $contents) !== strlen($contents) || ! @rename($temporary, $path)) {
            if ($temporary && is_file($temporary)) @unlink($temporary);
            throw new Problem(I18n::t('api.application_configuration_cleanup_is_incomplete_check_permissions_and_retry_uninstall'), 503);
        }
    }

    /** Last down-migration calls this even when the extension is already disabled. */
    public function finish(): void
    {
        if ($this->preparation() === []) return; // Old application listener may fire after the final migration.
        $this->assertAuthorized();
        foreach (['ccot_phones', 'ccot_codes', 'ccot_limits', 'ccot_blocks', 'ccot_tickets', 'ccot_ticket_images', 'ccot_post_images'] as $table) {
            if ($this->db->getSchemaBuilder()->hasTable($table)) throw new Problem(I18n::t('api.ccot_table_cleanup_is_incomplete_retry_uninstall'), 503);
        }
        $this->purgeImages();
        $configuration = require $this->paths->base.'/config.php';
        $ccot = ($this->preparation()['files'] ?? []) + $this->files();
        // Retain the key until the other private-file removals have succeeded.
        $smsFiles = array_filter([$ccot['sms_config_file'] ?? '', $ccot['sms_override_file'] ?? '']);
        foreach (array_unique(array_merge($smsFiles, array_map(fn ($path) => $path.'.lock', $smsFiles))) as $path) $this->removeFile($path);
        $this->db->table('users')->orderBy('id')->chunkById(100, function ($users) {
            foreach ($users as $user) {
                $preferences = json_decode($user->preferences ?: '{}', true) ?: [];
                if (! array_key_exists('ccotRejectMessages', $preferences) && ! array_key_exists('ccotBlockedUsers', $preferences)) continue;
                unset($preferences['ccotRejectMessages'], $preferences['ccotBlockedUsers']);
                $this->db->table('users')->where('id', $user->id)->update(['preferences'=>json_encode((object) $preferences, JSON_THROW_ON_ERROR)]);
            }
        });
        // Keep Nicknames and displayed names; native registration must ask for its normal login name.
        $this->settings->set('flarum-nicknames.random_username', '0');
        $this->settings->set('display_name_driver', 'nickname');
        $this->settings->set('allow_sign_up', '1');
        $extend = $this->paths->base.'/extend.php'; $original = $this->paths->base.'/extend.ccot-original.php';
        $contents = (string) file_get_contents($extend);
        $wrapper = "<?php\n// CCOT application guard; original application extenders preserved.\nreturn array_merge(require __DIR__.'/extend.ccot-original.php', require __DIR__.'/ccot-guard.php');\n";
        if ($contents === $wrapper && is_file($original) && ! is_link($original)) {
            $this->writeFile($extend, (string) file_get_contents($original));
            $this->removeFile($original);
        } elseif (str_contains($contents, "require __DIR__.'/ccot-guard.php'")) {
            $this->writeFile($extend, str_replace("require __DIR__.'/ccot-guard.php'", '[]', $contents));
        } elseif (str_contains($contents, 'ccot-guard.php')) {
            throw new Problem(I18n::t('api.application_extenders_have_changed_check_the_ccot_integration_and_retry'), 503);
        }
        // Write the configuration before deleting the key; a failed write keeps recovery material.
        unset($configuration['ccot']);
        $this->writeFile($this->paths->base.'/config.php', "<?php\nreturn ".var_export($configuration, true).";\n");
        $this->removeFile($ccot['site_guard_file'] ?? '');
        $this->removeFile($ccot['secret_file'] ?? '');
        $this->removeFile($this->paths->storage.'/ccot-private/post-images.lock');
        if ($this->db->getSchemaBuilder()->hasTable('group_permission')) $this->db->table('group_permission')->where('permission', 'like', 'bushcraftcn-ccot.%')->delete();
        foreach (array_unique([dirname($ccot['secret_file']), dirname($ccot['sms_config_file']), $this->paths->storage.'/ccot-private']) as $directory) if (is_dir($directory) && ! is_link($directory)) @rmdir($directory);
        foreach ($this->db->table('settings')->where('key', 'like', 'bushcraftcn-ccot.%')->where('key', '!=', self::STATE)->pluck('key') as $key) $this->settings->delete($key);
        $this->settings->delete(self::STATE);
        OperationLock::removeAfterUnlock($this->paths);
    }
}
