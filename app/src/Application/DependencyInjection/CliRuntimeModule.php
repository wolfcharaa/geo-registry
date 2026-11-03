<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use App\Application\Runtime\CliRuntime;
use App\Config\ConsoleConfig;
use App\Config\EnvironmentSnapshot;
use Symfony\Component\Console\Application;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

/** @implements Module<Ref<CliRuntime>> */
final readonly class CliRuntimeModule implements Module
{
    public function __construct(private EnvironmentSnapshot $environment) {}

    /** @return Ref<CliRuntime> */
    public function configure(Dic $dic): Ref
    {
        $config = ConsoleConfig::fromEnvironment($this->environment);
        $application = $dic->object(
            Application::class,
            static fn(): Application => new Application($config->name, $config->version),
        );

        return $dic
            ->object(CliRuntime::class)
            ->arg('application', $application);
    }
}
