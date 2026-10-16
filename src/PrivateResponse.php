<?php

namespace BushcraftCN\CCOT;

use Laminas\Diactoros\Response\JsonResponse;

final class PrivateResponse
{
    public static function json(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge($headers, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Vary' => 'Cookie, Authorization',
        ]));
    }
}
