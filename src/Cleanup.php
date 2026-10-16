<?php

namespace BushcraftCN\CCOT;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;

final class Cleanup extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('ccot:cleanup')->setDescription('Clean expired CCOT verification, rate limits and closed tickets; preserve held tickets.');
    }

    protected function fire(): int
    {
        return OperationLock::run(resolve(\Flarum\Foundation\Paths::class), false, fn () => $this->clean());
    }

    private function clean(): int
    {
        $db = resolve(ConnectionInterface::class);
        if (! $db->getSchemaBuilder()->hasTable('ccot_codes') || (json_decode(resolve(\Flarum\Settings\SettingsRepositoryInterface::class)->get('bushcraftcn-ccot.uninstall','{}'),true)['phase']??'')==='purging') {
            $this->info('CCOT cleanup skipped during uninstall maintenance.'); return 0;
        }
        $counts = [];
        foreach (['ccot_codes', 'ccot_limits', 'ccot_blocks'] as $table) $counts[$table] = $db->table($table)->where('expires_at', '<', time())->delete();
        $images = resolve(TicketFiles::class)->cleanupExpired(time() - TicketFiles::RETENTION_DAYS * 86400);
        $counts['ccot_tickets'] = $images['tickets'];
        $counts['file_cleanup_pending'] = $images['file_cleanup_pending'];
        $counts['orphan_files'] = $images['orphan_files'];
        $this->info(json_encode($counts));
        return 0;
    }
}
