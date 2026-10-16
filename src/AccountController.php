<?php

namespace BushcraftCN\CCOT;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AccountController implements RequestHandlerInterface
{
    public function __construct(private AccountLifecycle $lifecycle, private SettingsRepositoryInterface $settings) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (! RequestUtil::getActor($request)->isAdmin()) throw new Problem(I18n::t('api.administrator_permission_required'), 403);
            $action = basename($request->getUri()->getPath()); $body = $request->getParsedBody() ?? [];
            if (! in_array($request->getMethod(), ['GET', 'POST'], true) || ($request->getMethod() === 'GET' && $action !== 'account-state')) throw new Problem(I18n::t('api.request_method_not_supported'), 405);
            if ($request->getMethod() === 'POST') {
                if ($action === 'account-registration') {
                    if (! is_bool($body['open'] ?? null)) throw new Problem(I18n::t('api.invalid_registration_switch_value'), 422);
                    if ($this->lifecycle->status()['phase'] === 'purging') throw new Problem(I18n::t('api.cleanup_is_incomplete_retry_uninstall_first'), 409);
                    $this->settings->set('allow_sign_up', $body['open'] ? '1' : '0');
                } elseif ($action === 'account-start') $this->lifecycle->start();
                elseif ($action === 'account-cancel') $this->lifecycle->cancel();
                elseif ($action === 'account-export') return $this->lifecycle->export();
                elseif ($action === 'account-saved') {
                    if (($body['saved'] ?? false) !== true) throw new Problem(I18n::t('api.confirm_that_you_have_privately_saved_the_mapping'), 422);
                    $this->lifecycle->confirmSaved();
                } else throw new Problem(I18n::t('api.operation_not_found'), 404);
            }
            return PrivateResponse::json($this->lifecycle->status(), 200, ['Cache-Control'=>'no-store, private']);
        } catch (Problem $e) { return PrivateResponse::json(['errors'=>[['status'=>(string) $e->status,'detail'=>$e->getMessage()]]],$e->status); }
        catch (\Throwable) { return PrivateResponse::json(['errors'=>[['status'=>'503','detail'=>I18n::t('api.account_maintenance_data_is_unavailable_check_the_original_key_and')]]],503); }
    }
}
