<?php

namespace BushcraftCN\CCOT\PostImages;

use BushcraftCN\CCOT\OperationLock;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Psr\Log\LoggerInterface;

/** Visit-driven, bounded maintenance. No cron, detached worker or external request. */
final class AutoCleanup implements MiddlewareInterface
{
    public const STATE = 'bushcraftcn-ccot.post_images.cleanup_state';

    public function __construct(private Store $store, private Paths $paths, private SettingsRepositoryInterface $settings, private LoggerInterface $logger) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'GET' && ! RequestUtil::isInternal($request)) $this->run();
        return $handler->handle($request);
    }

    public function run(): void
    {
        $state = json_decode($this->settings->get(self::STATE, '{}'), true) ?: [];
        if (($state['next'] ?? 0) > time()) return;
        try {
            OperationLock::run($this->paths, false, fn () => $this->store->lock(function () {
                $db = $this->store->db;
                // Read fresh under the lock: another request may have run since application boot.
                $read = fn ($key) => json_decode($db->table('settings')->where('key', $key)->value('value') ?? '{}', true) ?: [];
                $state = $read(self::STATE);
                if (($state['next'] ?? 0) > time() || ! in_array('bushcraftcn-ccot', $read('extensions_enabled'), true) || ($read('bushcraftcn-ccot.uninstall')['phase'] ?? '') === 'purging' || ! $db->getSchemaBuilder()->hasColumn('ccot_post_images', 'deleted_at')) return;
                // Claim this interval even if processing fails; preserve the cursor for retry.
                $state['next'] = time() + 300;
                $this->settings->set(self::STATE, json_encode($state, JSON_THROW_ON_ERROR));
                try {
                    $result = $this->store->automaticBatch($state['cursor'] ?? []);
                    $state = ['next' => time() + 300, 'lastRun' => time(), 'cursor' => $result['cursor'], 'error' => false];
                } catch (\Throwable) {
                    $state['error'] = true;
                    $this->logger->warning('CCOT post-image automatic cleanup failed; check image directory permissions. A later visit will retry.');
                }
                $this->settings->set(self::STATE, json_encode($state, JSON_THROW_ON_ERROR));
            }));
        } catch (\Throwable) {
            // A concurrent upload/uninstall holds the lock. Reading must remain available.
            $this->logger->warning('CCOT post-image automatic cleanup was deferred; reading remains available.');
        }
    }
}
