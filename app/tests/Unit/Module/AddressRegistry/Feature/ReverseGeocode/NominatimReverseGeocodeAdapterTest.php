<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Feature\ReverseGeocode;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;

use function Amp\delay;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use App\Infrastructure\Nominatim\NominatimClient;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeUnavailable;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Infrastructure\NominatimReverseGeocodeAdapter;
use JsonException;
use RuntimeException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class NominatimReverseGeocodeAdapterTest
{
    public function testItMapsTheRawPayloadAndBuildsTheReverseRequest(): void
    {
        $http = new FakeNominatimHttpClient(body: json_encode([
            'display_name' => 'Регион Альфа, Тестоград, Общая улица, 10',
            'lat' => '55.750001',
            'lon' => '37.610001',
            'osm_type' => 'way',
            'osm_id' => 770005,
        ], JSON_THROW_ON_ERROR));

        $location = $this->adapter($http)->locate(55.750001, 37.610001, new NullCancellation());

        Assert::notNull($location);
        Assert::same($location->displayName, 'Регион Альфа, Тестоград, Общая улица, 10');
        Assert::same($location->latitude, 55.750001);
        Assert::same($location->longitude, 37.610001);
        Assert::same($location->sourceType, 'way');
        Assert::same($location->sourceId, 770005);
        Assert::notNull($http->request);
        Assert::true(str_contains(
            (string) $http->request->getUri(),
            '/reverse?addressdetails=1&format=jsonv2&lat=55.750001&lon=37.610001&zoom=18',
        ));
        Assert::same($http->request->getHeader('User-Agent'), 'geo-registry-test');
        Assert::same($http->request->getHeader('Accept-Language'), 'ru');
    }

    public function testItMapsAValidNominatimMissToAnEmptyLocation(): void
    {
        $http = new FakeNominatimHttpClient(body: '{"error":"Unable to geocode"}');

        Assert::same($this->adapter($http)->locate(0, 0, new NullCancellation()), null);
    }

    public function testItMapsHttpStatusAndMalformedJsonToTypedUnavailableErrors(): void
    {
        $statusError = $this->unavailable(new FakeNominatimHttpClient(status: 503));
        $jsonError = $this->unavailable(new FakeNominatimHttpClient(body: '{malformed'));

        Assert::instanceOf($statusError->getPrevious(), RuntimeException::class);
        Assert::instanceOf($jsonError->getPrevious(), JsonException::class);
    }

    public function testItPropagatesExplicitCancellation(): void
    {
        $cancellation = new DeferredCancellation();
        $cancellation->cancel();
        $error = null;

        try {
            $this->adapter(new FakeNominatimHttpClient())->locate(
                55.750001,
                37.610001,
                $cancellation->getCancellation(),
            );
        } catch (CancelledException $exception) {
            $error = $exception;
        }

        Assert::instanceOf($error, CancelledException::class);
    }

    public function testItPropagatesTheExpiredDeadline(): void
    {
        $error = null;

        try {
            $this->adapter(new FakeNominatimHttpClient(waitForCancellation: true))->locate(
                55.750001,
                37.610001,
                new TimeoutCancellation(0.001),
            );
        } catch (CancelledException $exception) {
            $error = $exception;
        }

        Assert::instanceOf($error, CancelledException::class);
        Assert::instanceOf($error->getPrevious(), TimeoutException::class);
    }

    private function unavailable(FakeNominatimHttpClient $http): ReverseGeocodeUnavailable
    {
        try {
            $this->adapter($http)->locate(55.750001, 37.610001, new NullCancellation());
        } catch (ReverseGeocodeUnavailable $exception) {
            return $exception;
        }

        throw new \LogicException('Expected reverse geocoding to be unavailable.');
    }

    private function adapter(FakeNominatimHttpClient $http): NominatimReverseGeocodeAdapter
    {
        return new NominatimReverseGeocodeAdapter(new NominatimClient(
            new HttpClient($http, []),
            'http://nominatim.test',
            'geo-registry-test',
            'ru',
        ));
    }
}

final class FakeNominatimHttpClient implements DelegateHttpClient
{
    public ?Request $request = null;

    public function __construct(
        private readonly int $status = 200,
        private readonly string $body = '{}',
        private readonly bool $waitForCancellation = false,
    ) {}

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->request = $request;
        if ($this->waitForCancellation) {
            delay(10, cancellation: $cancellation);
        }
        $cancellation->throwIfRequested();

        return new Response(
            '1.1',
            $this->status,
            null,
            ['content-type' => 'application/json'],
            $this->body,
            $request,
        );
    }
}
