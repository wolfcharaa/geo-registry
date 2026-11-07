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
            $thesisConsole = Layer::withName('Thesis Symfony Console')->collectors(
                ClassLikeConfig::create('^Thesis\\SymfonyConsoleModule(?:\\|$)'),
            ),
            $ampCore = Layer::withName('AMPHP Core')->collectors(
                ClassLikeConfig::create(
                    '^Amp\\(?:Cancellation|CancelledException|CompositeCancellation|DeferredCancellation|Future|NullCancellation|TimeoutCancellation)$',
                ),
            ),
            $ampCache = Layer::withName('AMPHP Cache')->collectors(
                ClassLikeConfig::create('^Amp\\Cache\\'),
            ),
            $ampRuntime = Layer::withName('AMPHP Runtime')->collectors(
                ClassLikeConfig::create('^Amp\\(?:Cluster\\|Parallel\\|Socket\\|Sync\\)'),
                ClassLikeConfig::create('^Revolt\\EventLoop(?:\\|$)'),
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
                ClassLikeConfig::create('^Yiisoft\\(?:Db\\|Injector\\)'),
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
                $thesisConsole,
                $ampCore,
                $ampRuntime,
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
                $moduleUserInterface,
                $moduleFeature,
                $ampCore,
                $ampRuntime,
                $httpServer,
                $postgres,
                $logging,
            ),
            Ruleset::forLayer($scaffoldInfrastructure)->accesses(
                $applicationHealth,
                $applicationObservability,
                $configLayer,
                $ampCore,
                $ampRuntime,
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
                $scaffoldInfrastructure,
                $moduleUserInterface,
                $moduleCapability,
                $moduleInfrastructure,
                $sharedKernel,
                $messageBusCore,
                $ampCore,
                $ampCache,
                $httpServer,
                $httpClient,
                $postgres,
                $logging,
                $console,
                $validator,
            ),
            Ruleset::forLayer($database)->accesses(
                $configLayer,
                $postgres,
                $migrationTooling,
            ),
            Ruleset::forLayer($sharedKernel),
            Ruleset::forLayer($messageBusCore),
            Ruleset::forLayer($messageBusWorker)->accesses($messageBusCore),
            Ruleset::forLayer($thesisDic),
            Ruleset::forLayer($thesisConsole)->accesses($thesisDic, $console),
            Ruleset::forLayer($ampCore),
            Ruleset::forLayer($ampCache)->accesses($ampCore, $ampRuntime),
            Ruleset::forLayer($ampRuntime),
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
