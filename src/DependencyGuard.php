<?php

namespace BushcraftCN\CCOT;

use Flarum\Http\RequestUtil;
use Flarum\User\UserRepository;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

/** Enabled-only readiness gate; keeps administrator repair and native logout available. */
final class DependencyGuard implements MiddlewareInterface
{
    public function __construct(private DependencyStatus $status, private UserRepository $users) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->status->ready()) return $handler->handle($request);
        $path = rtrim(rawurldecode(preg_replace('~^/api(?=/|$)~', '', $request->getUri()->getPath())), '/');
        $read = in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($path === '/ccot/dependencies' && $read) return $handler->handle($request);
        if ($path === '/ccot' || str_starts_with($path, '/ccot/')) return $this->deny();
        if ($read || $path === '/logout') return $handler->handle($request);

        $actor = RequestUtil::getActor($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if ($actor->isAdmin() && (Guard::nativeMaintenance($path) || Guard::nativeReadState($request, $path)) && ! ($path === '/users' && $request->getMethod() === 'POST')) {
            // Native repair settings remain usable; hidden CCOT forms cannot be submitted through /settings.
            foreach (array_keys($body) as $key) if (str_starts_with((string) $key, 'bushcraftcn-ccot.')) return $this->deny();
            return $handler->handle($request);
        }
        if (in_array($path, ['/login', '/token'], true) && is_string($body['identification'] ?? null)
            && strlen($body['identification']) <= 150 && is_string($body['password'] ?? null) && strlen($body['password']) <= 256) {
            $user = $this->users->findByIdentification($body['identification']);
            if ($user?->isAdmin()) return $handler->handle($request); // Core still verifies the password.
        }
        return $this->deny();
    }

    private function deny(): ResponseInterface
    {
        return PrivateResponse::json(['errors' => [['status' => '503', 'detail' => I18n::t('lib.dependencies_unavailable')]]], 503);
    }
}
