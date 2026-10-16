<?php

// Recovery only: never starts an uninstall or drops data tables.
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, 'CCOT cleanup not completed. Restore writable application/private paths and retry. Details withheld: '.get_class($error)."\n");
    exit(1);
});
$forum = isset($argv[1]) ? realpath($argv[1]) : false;
if (! $forum || ($argv[2] ?? '') !== '--finish-confirmed-uninstall' || ! is_file($forum.'/config.php') || ! is_file($forum.'/vendor/autoload.php')) {
    throw new RuntimeException('Specify forum directory and explicit cleanup confirmation');
}
$app = (require $forum.'/site.php')->bootApp();
$container = $app->getContainer();
$settings = $container->make(Flarum\Settings\SettingsRepositoryInterface::class);
$state = json_decode($settings->get('bushcraftcn-ccot.uninstall', '{}'), true) ?: [];
if (($state['phase'] ?? '') !== 'purging') throw new RuntimeException('No interrupted confirmed uninstall');
$db = $container->make(Illuminate\Database\ConnectionInterface::class);
foreach (['ccot_phones','ccot_codes','ccot_limits','ccot_blocks','ccot_tickets','ccot_ticket_images','ccot_post_images'] as $table) {
    if ($db->getSchemaBuilder()->hasTable($table)) throw new RuntimeException('Retry the normal extension uninstall while data tables remain');
}
$paths = $container->make(Flarum\Foundation\Paths::class);
BushcraftCN\CCOT\OperationLock::run($paths, true, function () use ($container) {
    $lifecycle = $container->make(BushcraftCN\CCOT\AccountLifecycle::class);
    $lifecycle->uninstall(fn () => $lifecycle->finish());
});
echo "CCOT interrupted private-file cleanup completed. Native accounts retained; native registration is open.\n";
