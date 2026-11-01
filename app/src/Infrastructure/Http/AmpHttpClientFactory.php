<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\SetRequestTimeout;

final class AmpHttpClientFactory
{
    public static function create(float $timeoutSeconds): HttpClient
    {
        $connectTimeout = min(5.0, $timeoutSeconds);

        return (new HttpClientBuilder())
            ->followRedirects(0)
            ->intercept(new SetRequestTimeout(
                tcpConnectTimeout: $connectTimeout,
                tlsHandshakeTimeout: $connectTimeout,
                transferTimeout: $timeoutSeconds,
                inactivityTimeout: $timeoutSeconds,
            ))
            ->build();
    }
}
