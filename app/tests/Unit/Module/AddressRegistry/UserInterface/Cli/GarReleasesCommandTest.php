<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\Releases\GarReleaseMetadata;
use App\Module\AddressRegistry\Capability\Releases\GarReleaseMetadataReader;
use App\Module\AddressRegistry\UserInterface\Cli\GarReleasesCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarReleasesCommandTest
{
    public function testItLimitsTheOptionalAllReleaseJsonOutput(): void
    {
        $reader = new FixedGarReleaseMetadataReader([
            new GarReleaseMetadata(12, 'Версия 12', '2026-11-05', 'https://files.test/12.zip', null),
            new GarReleaseMetadata(11, 'Версия 11', '2026-10-29', 'https://files.test/11.zip', null),
        ]);
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new GarReleasesCommand($reader));
        $output = new BufferedOutput();

        $exitCode = $application->run(new ArrayInput([
            'command' => 'gar:releases',
            '--all' => true,
            '--limit' => '1',
            '--timeout' => '7',
            '--url' => 'https://metadata.test/releases',
            '--json' => true,
            '--no-ansi' => true,
        ]), $output);

        Assert::same($exitCode, 0);
        Assert::true($reader->allRequested);
        Assert::same($reader->url, 'https://metadata.test/releases');
        Assert::same($reader->timeout, 7);
        $payload = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
        Assert::true(is_array($payload));
        Assert::same(count($payload), 1);
        $release = $payload[0] ?? null;
        Assert::true(is_array($release));
        Assert::same($release['version_id'] ?? null, 12);
    }
}

final class FixedGarReleaseMetadataReader implements GarReleaseMetadataReader
{
    public bool $allRequested = false;
    public ?string $url = null;
    public ?int $timeout = null;

    /** @param list<GarReleaseMetadata> $releases */
    public function __construct(private readonly array $releases) {}

    public function endpoint(bool $all): string
    {
        return 'https://metadata.test/' . ($all ? 'all' : 'latest');
    }

    public function latest(?string $url, int $timeoutSeconds): GarReleaseMetadata
    {
        $this->url = $url;
        $this->timeout = $timeoutSeconds;
        $release = $this->releases[0] ?? null;
        Assert::notNull($release);

        return $release;
    }

    public function all(?string $url, int $timeoutSeconds): array
    {
        $this->allRequested = true;
        $this->url = $url;
        $this->timeout = $timeoutSeconds;

        return $this->releases;
    }
}
