<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserInterface\Http;

use function Amp\ByteStream\buffer;

use Amp\Http\HttpStatus;
use App\UserInterface\Http\JsonErrorHandler;
use Testo\Assert;
use Testo\Test;

#[Test]
final class JsonErrorHandlerTest
{
    public function testItReturnsStableJsonForRouterErrors(): void
    {
        $handler = new JsonErrorHandler();
        $notFound = $handler->handleError(HttpStatus::NOT_FOUND);
        $methodNotAllowed = $handler->handleError(HttpStatus::METHOD_NOT_ALLOWED);

        Assert::same($notFound->getStatus(), HttpStatus::NOT_FOUND);
        Assert::same($notFound->getHeader('content-type'), 'application/json; charset=utf-8');
        Assert::same(json_decode(buffer($notFound->getBody()), true, flags: JSON_THROW_ON_ERROR), [
            'success' => false,
            'error' => 'Not Found',
            'error_code' => 'route_not_found',
        ]);
        Assert::same(json_decode(buffer($methodNotAllowed->getBody()), true, flags: JSON_THROW_ON_ERROR), [
            'success' => false,
            'error' => 'Method Not Allowed',
            'error_code' => 'method_not_allowed',
        ]);
    }
}
