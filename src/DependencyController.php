<?php

namespace BushcraftCN\CCOT;

use Flarum\Http\RequestUtil;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class DependencyController implements RequestHandlerInterface
{
    public function __construct(private DependencyStatus $status) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! RequestUtil::getActor($request)->isAdmin()) return PrivateResponse::json(['errors' => [['status' => '403', 'detail' => I18n::t('api.administrator_permission_required')]]], 403);
        try {
            return PrivateResponse::json($this->status->read());
        } catch (\Throwable) {
            return PrivateResponse::json(['errors' => [['status' => '503', 'detail' => I18n::t('lib.dependencies_failed')]]], 503);
        }
    }
}
