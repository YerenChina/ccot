<?php

namespace BushcraftCN\CCOT;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TicketImageController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db, private TicketFiles $files) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = ['Cache-Control' => 'private, no-store, max-age=0', 'Vary' => 'Cookie, Authorization', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Referrer-Policy' => 'no-referrer'];
        try {
            $actor = RequestUtil::getActor($request);
            if ($actor->isGuest()) throw new Problem(I18n::t('api.please_log_in_first'), 401);
            $params = $request->getAttribute('routeParameters', []);
            $ticket = $this->db->table('ccot_tickets')->where('id', (int) ($params['id'] ?? 0))->first();
            if (! $ticket || (! $actor->isAdmin() && (int) $ticket->user_id !== (int) $actor->id)) throw new Problem(I18n::t('api.the_image_does_not_exist_or_access_is_denied'), 404);
            $image = $this->db->table('ccot_ticket_images')->where('ticket_id', $ticket->id)->where('id', (string) ($params['image'] ?? ''))->first();
            if (! $image) throw new Problem(I18n::t('api.the_image_does_not_exist_or_access_is_denied'), 404);
            $bytes = $this->files->read($image);
            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$image->mime] ?? null;
            if (! $extension) throw new Problem(I18n::t('api.image_format_unavailable'), 503);
            $response = new Response('php://temp', 200, $headers + ['Content-Type' => $image->mime, 'Content-Length' => (string) strlen($bytes), 'Content-Disposition' => 'inline; filename="ticket-image.'.$extension.'"']);
            $response->getBody()->write($bytes);
            $response->getBody()->rewind();
            return $response;
        } catch (Problem $error) {
            return new JsonResponse(['errors' => [['status' => (string) $error->status, 'detail' => $error->getMessage()]]], $error->status, $headers);
        }
    }
}
