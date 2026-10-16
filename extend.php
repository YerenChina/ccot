<?php

use BushcraftCN\CCOT;
use Flarum\Api\Resource;
use Flarum\Api\Schema\Str;
use Flarum\Extend;
use Flarum\User\User;

$forum = (new Extend\Routes('forum'));
foreach (['code', 'register', 'login', 'reset', 'bind', 'change'] as $action) $forum->post('/ccot/auth/'.$action, 'ccot.auth.'.$action, CCOT\AuthController::class);
$settings = (new Extend\Settings())
    ->default('bushcraftcn-ccot.sms_mode', 'aliyun')
    ->default('bushcraftcn-ccot.sms_daily_limit', '100')
    ->default('bushcraftcn-ccot.sms_paused', '0')
    ->default('bushcraftcn-ccot.show_legacy_entry', '1')
    ->default('bushcraftcn-ccot.agreement', file_get_contents(__DIR__.'/resources/agreement.txt'))
    ->default('bushcraftcn-ccot.privacy', file_get_contents(__DIR__.'/resources/privacy.txt'))
    ->default('bushcraftcn-ccot.rules', file_get_contents(__DIR__.'/resources/rules.txt'))
    ->default('bushcraftcn-ccot.faq', file_get_contents(__DIR__.'/resources/faq.txt'))
    ->serializeToForum('ccotPostImages', 'bushcraftcn-ccot.post_images.enabled', fn () => resolve(CCOT\PostImages\Settings::class)->read())
    ->serializeToForum('ccotEnabled', 'bushcraftcn-ccot.enabled', fn () => true)
    ->serializeToForum('ccotShowLegacyEntry', 'bushcraftcn-ccot.show_legacy_entry', fn ($value) => (string) $value === '1');

$accounts = (new Extend\Routes('api'));
// The old application wrapper can still be loaded in the upgrade request itself.
// It is detached by migration; the next request uses these enabled-only routes.
if (! class_exists('CcotSiteGuard', false)) $accounts
        ->get('/ccot/account-state', 'ccot.account.state', CCOT\AccountController::class)
        ->post('/ccot/account-registration', 'ccot.account.registration', CCOT\AccountController::class)
        ->post('/ccot/account-start', 'ccot.account.start', CCOT\AccountController::class)
        ->post('/ccot/account-cancel', 'ccot.account.cancel', CCOT\AccountController::class)
        ->post('/ccot/account-export', 'ccot.account.export', CCOT\AccountController::class)
        ->post('/ccot/account-saved', 'ccot.account.saved', CCOT\AccountController::class);

return [
    $accounts,
    (new Extend\Middleware('api'))->insertBefore(\Flarum\Api\Middleware\ThrottleApi::class, CCOT\Guard::class),
    (new Extend\Middleware('forum'))->add(CCOT\Guard::class),
    (new Extend\Formatter())->configure(CCOT\PostImages\Formatter::class),
    (new Extend\ApiResource(Resource\PostResource::class))->fields(fn () => [
        \Flarum\Api\Schema\Arr::make('ccotPostImages')->get(fn ($post) => $post instanceof \Flarum\Post\CommentPost ? resolve(CCOT\PostImages\Store::class)->forPost($post) : []),
        \Flarum\Api\Schema\Arr::make('ccotImageEditor')->visible(fn ($post, $context) => $post instanceof \Flarum\Post\CommentPost && $context->getActor()->can('edit', $post))->get(fn ($post) => CCOT\PostImages\Markup::editor($post)),
    ]),
    (new Extend\Middleware('api'))->insertBefore(\Flarum\Api\Middleware\ThrottleApi::class, CCOT\PostImages\WriteTransaction::class),
    (new Extend\Event())->listen(\Flarum\Post\Event\Saving::class, CCOT\PostImages\SaveImages::class),
    (new Extend\Event())->listen(\Flarum\Post\Event\Deleted::class, CCOT\PostImages\DeletedImages::class),
    (new Extend\Event())->listen(\Flarum\Discussion\Event\Deleting::class, CCOT\PostImages\DeletedImages::class),
    (new Extend\Console())->command(CCOT\PostImages\Cleanup::class),
    (new Extend\Routes('api'))
        ->get('/ccot/post-images/settings', 'ccot.post-images.settings', CCOT\PostImages\Controller::class)
        ->post('/ccot/post-images/settings', 'ccot.post-images.settings.save', CCOT\PostImages\Controller::class)
        ->get('/ccot/post-images/manage', 'ccot.post-images.manage', CCOT\PostImages\Controller::class)
        ->post('/ccot/post-images', 'ccot.post-images.upload', CCOT\PostImages\Controller::class)
        ->delete('/ccot/post-images/{id}', 'ccot.post-images.remove', CCOT\PostImages\Controller::class)
        ->post('/ccot/post-images/{id}/block', 'ccot.post-images.block', CCOT\PostImages\Controller::class),
    (new Extend\Locales(__DIR__.'/locale')),
    new CCOT\Lifecycle(),
    $forum,
    (new Extend\Routes('api'))
        ->get('/ccot/config', 'ccot.config', CCOT\ToolsController::class)
        ->get('/ccot/health', 'ccot.health', CCOT\ToolsController::class)
        ->get('/ccot/sms-settings', 'ccot.sms.settings', CCOT\SmsAdminController::class)
        ->post('/ccot/sms-settings', 'ccot.sms.save', CCOT\SmsAdminController::class)
        ->post('/ccot/sms-test', 'ccot.sms.test', CCOT\SmsAdminController::class)
        ->post('/ccot/sms-test-verify', 'ccot.sms.verify', CCOT\SmsAdminController::class)
        ->post('/ccot/block', 'ccot.block', CCOT\ToolsController::class)
        ->get('/ccot/tickets', 'ccot.tickets.index', CCOT\ToolsController::class)
        ->post('/ccot/tickets', 'ccot.tickets.create', CCOT\ToolsController::class)
        ->patch('/ccot/tickets/{id}', 'ccot.tickets.update', CCOT\ToolsController::class)
        ->delete('/ccot/tickets/{id}', 'ccot.tickets.delete', CCOT\ToolsController::class)
        ->get('/ccot/tickets/{id}/images/{image}', 'ccot.tickets.image', CCOT\TicketImageController::class),
    $settings,
    (new Extend\Event())->listen(\Flarum\Settings\Event\Saving::class, CCOT\FooterSettings::class),
    (new Extend\ApiResource(Resource\UserResource::class))
        ->fields(CCOT\UserFields::class)
        ->field('email', CCOT\UserFields::optionalEmail(...)),
    (new Extend\User())
        ->permissionGroups(CCOT\PhoneGroups::class)
        ->registerPreference('ccotRejectMessages', 'boolval', false)
        ->registerPreference('ccotBlockedUsers', fn ($value) => array_slice(array_values(array_unique(array_map('intval', is_array($value) ? $value : []))), 0, 500), []),
    (new Extend\Conditional())->whenExtensionEnabled('flarum-messages', fn () => [
        (new Extend\Policy())->modelPolicy(User::class, CCOT\MessagesPolicy::class),
    ]),
    (new Extend\ServiceProvider())->register(CCOT\Provider::class),
    (new Extend\Notification())->driver('email', CCOT\EmailDriver::class),
    (new Extend\Console())->command(CCOT\Cleanup::class)
        ->schedule('ccot:cleanup', fn (\Illuminate\Console\Scheduling\Event $event) => $event->hourly()->withoutOverlapping()),
    (new Extend\Frontend('forum'))->js(__DIR__.'/js/dist/forum.js')->css(__DIR__.'/less/ccot.less')
        ->route('/ccot/account/{action}', 'ccot.account')
        ->route('/ccot/page/{page}', 'ccot.page')
        ->route('/ccot/tickets', 'ccot.tickets'),
    (new Extend\Frontend('admin'))->js(__DIR__.'/js/dist/admin.js')->css(__DIR__.'/less/ccot.less'),
];
