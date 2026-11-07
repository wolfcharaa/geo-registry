<?php

declare(strict_types=1);

namespace App\UserInterface\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

final class JsonErrorHandler implements ErrorHandler
{
    public function handleError(int $status, ?string $reason = null, ?Request $request = null): Response
    {
        return JsonResponse::create([
            'success' => false,
            'error' => HttpStatus::getReason($status),
            'error_code' => match ($status) {
                HttpStatus::NOT_FOUND => 'route_not_found',
                HttpStatus::METHOD_NOT_ALLOWED => 'method_not_allowed',
                default => 'http_error',
            },
        ], $status);
    }
}
