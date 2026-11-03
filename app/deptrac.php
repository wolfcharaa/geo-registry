<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\ClassLikeConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    $config
        ->paths('./src')
        ->cacheFile('./var/deptrac.cache')
        ->layers(
            $application = Layer::withName('Application')->collectors(
                ClassLikeConfig::create('^App\\(?:Bootstrap$|Application\\(?!(?:Health|Observability)\\))'),
            ),
            $applicationHealth = Layer::withName('Application Health')->collectors(
                ClassLikeConfig::create('^App\\Application\\Health\\'),
            ),
            $applicationObservability = Layer::withName('Application Observability')->collectors(
                ClassLikeConfig::create('^App\\Application\\Observability\\'),
            ),
            $configLayer = Layer::withName('Config')->collectors(
                ClassLikeConfig::create('^App\\Config\\'),
            ),
            $scaffoldUserInterface = Layer::withName('Scaffold UserInterface')->collectors(
                ClassLikeConfig::create('^App\\UserInterface\\'),
            ),
            $scaffoldInfrastructure = Layer::withName('Scaffold Infrastructure')->collectors(
                ClassLikeConfig::create('^App\\Infrastructure\\'),
            ),
            $moduleUserInterface = Layer::withName('Module UserInterface')->collectors(
                ClassLikeConfig::create('^App\\Module\\[^\\]+\\UserInterface\\'),
            ),
            $moduleCapability = Layer::withName('Module Capability')->collectors(
                ClassLikeConfig::create('^App\\Module\\[^\\]+\\Capability\\'),
            ),
            $moduleInfrastructure = Layer::withName('Module Infrastructure')->collectors(
                ClassLikeConfig::create('^App\\Module\\[^\\]+\\Infrastructure\\'),
            ),
            $moduleFeature = Layer::withName('Module Feature')->collectors(
                ClassLikeConfig::create('^App\\Module\\[^\\]+\\Feature\\'),
            ),
            $database = Layer::withName('Database')->collectors(
                ClassLikeConfig::create('^App\\Database\\'),
            ),
            $sharedKernel = Layer::withName('SharedKernel')->collectors(
                ClassLikeConfig::create('^App\\SharedKernel\\'),
            ),
            $messageBusCore = Layer::withName('MessageBus Core')->collectors(
                ClassLikeConfig::create('^Fedor\\MessageBus\\(?!Worker\\)'),
            ),
            $messageBusWorker = Layer::withName('MessageBus Worker')->collectors(
                ClassLikeConfig::create('^Fedor\\MessageBus\\Worker\\'),
            ),
            $thesisDic = Layer::withName('Thesis DIC')->collectors(
                ClassLikeConfig::create('^Thesis\\Dic(?:\\|$)'),
            ),
            $ampCore = Layer::withName('AMPHP Core')->collectors(
                ClassLikeConfig::create(
                    '^Amp\\(?:Cancellation|CancelledException|DeferredCancellation|Future|NullCancellation|TimeoutCancellation)$',
                ),
            ),
            $httpServer = Layer::withName('AMPHP HTTP Server')->collectors(
                ClassLikeConfig::create('^Amp\\Http\\(?:HttpStatus$|Server\\)'),
            ),
            $httpClient = Layer::withName('AMPHP HTTP Client')->collectors(
                ClassLikeConfig::create('^Amp\\Http\\Client\\'),
            ),
            $postgres = Layer::withName('AMPHP PostgreSQL')->collectors(
                ClassLikeConfig::create('^Amp\\Postgres\\'),
            ),
            $logging = Layer::withName('Logging')->collectors(
                ClassLikeConfig::create('^(?:Amp\\Log\\|Monolog\\|Psr\\Log\\)'),
            ),
            $console = Layer::withName('Symfony Console')->collectors(
                ClassLikeConfig::create('^Symfony\\Component\\Console\\'),
            ),
            $validator = Layer::withName('Symfony Validator')->collectors(
                ClassLikeConfig::create('^Symfony\\Component\\Validator\\'),
            ),
            $migrationTooling = Layer::withName('Migration Tooling')->collectors(
                ClassLikeConfig::create('^Yiisoft\\Db\\Migration\\'),
            ),
        )
        ->rulesets(
            Ruleset::forLayer($application)->accesses(
                $applicationHealth,
                $applicationObservability,
                $configLayer,
                $scaffoldUserInterface,
                $scaffoldInfrastructure,
                $moduleUserInterface,
                $moduleCapability,
                $moduleInfrastructure,
                $moduleFeature,
                $database,
                $sharedKernel,
                $messageBusCore,
                $messageBusWorker,
                $thesisDic,
                $ampCore,
                $httpServer,
                $httpClient,
                $postgres,
                $logging,
                $console,
                $validator,
                $migrationTooling,
            ),
            Ruleset::forLayer($applicationHealth)->accesses(
                $applicationObservability,
                $ampCore,
                $logging,
            ),
            Ruleset::forLayer($applicationObservability),
            Ruleset::forLayer($configLayer),
            Ruleset::forLayer($scaffoldUserInterface)->accesses(
                $applicationHealth,
                $applicationObservability,
                $configLayer,
                $scaffoldInfrastructure,
                $ampCore,
                $httpServer,
                $postgres,
                $logging,
            ),
            Ruleset::forLayer($scaffoldInfrastructure)->accesses(
                $applicationHealth,
                $applicationObservability,
                $configLayer,
                $ampCore,
                $httpClient,
                $postgres,
                $logging,
            ),
            Ruleset::forLayer($moduleUserInterface)->accesses(
                $moduleCapability,
                $sharedKernel,
                $messageBusCore,
                $ampCore,
                $httpServer,
                $logging,
                $console,
                $validator,
            ),
            Ruleset::forLayer($moduleCapability)->accesses(
                $sharedKernel,
                $messageBusCore,
                $ampCore,
            ),
            Ruleset::forLayer($moduleInfrastructure)->accesses(
                $moduleCapability,
                $sharedKernel,
                $messageBusCore,
                $ampCore,
                $httpClient,
                $postgres,
                $logging,
            ),
            Ruleset::forLayer($moduleFeature)->accesses(
                $moduleCapability,
                $moduleInfrastructure,
                $sharedKernel,
                $messageBusCore,
                $ampCore,
                $httpServer,
                $httpClient,
                $postgres,
                $logging,
                $console,
                $validator,
            ),
            Ruleset::forLayer($database)->accesses(
                $postgres,
                $migrationTooling,
            ),
            Ruleset::forLayer($sharedKernel),
            Ruleset::forLayer($messageBusCore),
            Ruleset::forLayer($messageBusWorker)->accesses($messageBusCore),
            Ruleset::forLayer($thesisDic),
            Ruleset::forLayer($ampCore),
            Ruleset::forLayer($httpServer),
            Ruleset::forLayer($httpClient),
            Ruleset::forLayer($postgres),
            Ruleset::forLayer($logging),
            Ruleset::forLayer($console),
            Ruleset::forLayer($validator),
            Ruleset::forLayer($migrationTooling),
        )
    ;
};
