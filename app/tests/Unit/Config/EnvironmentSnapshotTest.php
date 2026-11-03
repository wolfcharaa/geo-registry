<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Config\EnvironmentSnapshot;
use InvalidArgumentException;
use LogicException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class EnvironmentSnapshotTest
{
    public function testItParsesValuesFromOneImmutableInput(): void
    {
        $environment = new EnvironmentSnapshot([
            'NAME' => ' value ',
            'EMPTY' => '  ',
            'COUNT' => '12',
            'TIMEOUT' => '2.5',
        ]);

        Assert::same($environment->string('NAME', 'default'), 'value');
        Assert::same($environment->string('MISSING', 'default'), 'default');
        Assert::same($environment->optionalString('EMPTY'), null);
        Assert::same($environment->positiveInteger('COUNT', 1), 12);
        Assert::same($environment->positiveFloat('TIMEOUT', 1), 2.5);
    }

    public function testItRedactsSecretsAndConnectionStrings(): void
    {
        $environment = new EnvironmentSnapshot([
            'APP_NAME' => 'geo-registry',
            'DATABASE_DSN' => 'host=db password=secret',
            'API_TOKEN' => 'token-value',
        ]);

        Assert::same($environment->redactedValues(), [
            'APP_NAME' => 'geo-registry',
            'DATABASE_DSN' => '[redacted]',
            'API_TOKEN' => '[redacted]',
        ]);
    }

    public function testItRejectsInvalidIntegersWithoutRepeatingTheSecretValue(): void
    {
        try {
            (new EnvironmentSnapshot(['DATABASE_POOL_SIZE' => 'not-an-integer']))
                ->positiveInteger('DATABASE_POOL_SIZE', 10);
        } catch (InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'DATABASE_POOL_SIZE must be a positive integer.');
            Assert::false(str_contains($exception->getMessage(), 'not-an-integer'));

            return;
        }

        throw new LogicException('Expected invalid environment value to be rejected.');
    }
}
