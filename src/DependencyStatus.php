<?php

namespace BushcraftCN\CCOT;

use Composer\InstalledVersions;
use Flarum\Extension\{Extension, ExtensionManager};

/** Read-only checks of the current PHP process, installed packages and enabled extensions. */
final class DependencyStatus
{
    private ?array $snapshot = null;

    public function __construct(private ExtensionManager $extensions) {}

    /** Installation requirements and deferred requirements are both mandatory for business use. */
    public static function requirements(): array
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true, 32, JSON_THROW_ON_ERROR);
        return $composer['require'] + ['ext-exif' => '*'];
    }

    public function ready(): bool
    {
        try { return $this->read()['issues'] === []; }
        catch (\Throwable) { return false; }
    }

    public function read(): array
    {
        if ($this->snapshot !== null) return $this->snapshot;
        $required = self::requirements();
        $runtime = ['php' => PHP_VERSION, 'extensions' => [], 'packages' => [], 'codecs' => []];
        $classes = [
            'intervention/image' => \Intervention\Image\ImageManager::class,
            'alibabacloud/dysmsapi-20170525' => \AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi::class,
            'zoujingli/ip2region' => 'Ip2Region',
        ];
        foreach ($required as $name => $constraint) {
            if (str_starts_with($name, 'ext-')) {
                $runtime['extensions'][$name] = extension_loaded(substr($name, 4));
                $functions = match ($name) {
                    'ext-openssl' => ['openssl_encrypt', 'openssl_decrypt'],
                    'ext-exif' => ['exif_read_data'],
                    'ext-gd' => ['gd_info', 'imagetypes', 'imagecreatefromstring'],
                    default => [],
                };
                foreach ($functions as $function) if (! function_exists($function)) $runtime['extensions'][$name] = false;
                if ($name === 'ext-fileinfo' && ! class_exists(\finfo::class)) $runtime['extensions'][$name] = false;
            } elseif (str_contains($name, '/')) {
                $installed = InstalledVersions::isInstalled($name);
                $extension = $this->extensions->getExtension(Extension::nameToId($name));
                try {
                    $path = $installed ? InstalledVersions::getInstallPath($name) : null;
                    $usable = ($path === null || is_file($path.'/composer.json'))
                        && (! $extension || ($path !== null && is_file($path.'/extend.php')))
                        && (! isset($classes[$name]) || class_exists($classes[$name]));
                }
                catch (\Throwable) { $usable = false; }
                $runtime['packages'][$name] = [
                    'installed' => $installed,
                    'usable' => $usable,
                    'enabled' => $extension ? $this->extensions->isEnabled($extension->getId()) : null,
                    'extensionId' => $extension?->getId(),
                    'title' => $extension?->getTitle() ?? $name,
                ];
            }
        }
        // GD can be installed without a required codec; ext-gd alone is not sufficient.
        if ($runtime['extensions']['ext-gd'] ?? false) {
            $types = function_exists('imagetypes') ? imagetypes() : 0;
            foreach (['JPEG' => ['IMG_JPG', 'imagecreatefromjpeg'], 'PNG' => ['IMG_PNG', 'imagecreatefrompng'], 'WebP' => ['IMG_WEBP', 'imagecreatefromwebp']] as $format => [$flag, $decoder]) {
                $runtime['codecs'][$format] = defined($flag) && ($types & constant($flag)) && function_exists($decoder) && ($format !== 'WebP' || function_exists('imagewebp'));
            }
        }
        return $this->snapshot = ['issues' => self::evaluate($required, $runtime)];
    }

    /** Evaluate an observed snapshot without changing any environment or business data. */
    public static function evaluate(array $required, array $runtime): array
    {
        $issues = [];
        $add = static function (string $id, string $label, string $detail, string $action, ?string $extensionId = null) use (&$issues): void {
            $issues[] = compact('id', 'label', 'detail', 'action', 'extensionId');
        };
        // Read this package's current ~major.minor.patch PHP constraint; do not emulate Composer's resolver.
        if (! preg_match('/^~([0-9]+)\.([0-9]+)\.([0-9]+)$/D', $required['php'], $range)) throw new \UnexpectedValueException('Unsupported PHP requirement');
        $upper = $range[1].'.'.((int) $range[2] + 1).'.0';
        if (version_compare($runtime['php'], substr($required['php'], 1), '<') || version_compare($runtime['php'], $upper, '>=')) {
            $add('php', 'PHP', I18n::t('lib.dependencies_version', ['required' => $required['php'], 'actual' => $runtime['php']]), I18n::t('lib.dependencies_php_version_fix'));
        }
        foreach ($required as $name => $constraint) {
            if (str_starts_with($name, 'ext-')) {
                if (! ($runtime['extensions'][$name] ?? false)) $add($name, I18n::t('lib.dependencies_php_extension', ['name' => substr($name, 4)]), I18n::t('lib.dependencies_missing'), I18n::t('lib.dependencies_php_fix'));
                continue;
            }
            if (! str_contains($name, '/')) continue; // Composer's runtime API is a virtual platform package.
            $package = $runtime['packages'][$name] ?? [];
            $label = $package['title'] ?? $name;
            if (! ($package['installed'] ?? false) || ! ($package['usable'] ?? false)) {
                $add($name, $label, I18n::t('lib.dependencies_package_missing', ['name' => $name]), I18n::t('lib.dependencies_composer_fix'));
            } elseif (($package['enabled'] ?? null) === false) {
                $add($name, $label, I18n::t('lib.dependencies_not_enabled'), I18n::t('lib.dependencies_enable_fix'), $package['extensionId']);
            }
        }
        foreach ($runtime['codecs'] ?? [] as $format => $ready) {
            if (! $ready) $add('gd-'.$format, I18n::t('lib.dependencies_codec', ['format' => $format]), I18n::t('lib.dependencies_missing'), I18n::t('lib.dependencies_php_fix'));
        }
        return $issues;
    }
}
