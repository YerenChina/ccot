<?php
// Optional legacy command. Normal installation uses Composer and the Flarum enable button.
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, "CCOT enable failed. Check private storage permissions and restore the original key if missing. Details withheld.\n");
    exit(1);
});
$forum = isset($argv[1]) ? realpath($argv[1]) : false;
if (! $forum || ! is_file($forum.'/site.php')) throw new RuntimeException('Specify the Flarum directory');
$app = (require $forum.'/site.php')->bootApp();
$container = $app->getContainer();
$manager = $container->make(Flarum\Extension\ExtensionManager::class);
if ($manager->isEnabled('bushcraftcn-ccot')) (new BushcraftCN\CCOT\Lifecycle())->configure($container);
else $manager->enable('bushcraftcn-ccot');
echo "CCOT is ready. Future installations only need the normal Flarum enable button.\n";
