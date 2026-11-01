<?php

declare(strict_types=1);

namespace App\UserInterface\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;

final class JsonResponse
{
    public static function create(mixed $payload, int $status = HttpStatus::OK): Response
    {
        return new Response(
            status: $status,
            headers: ['content-type' => 'application/json; charset=utf-8'],
            body: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
