<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use App\Application\Observability\CorrelationContext;
use App\Config\LogFormat;
use App\Config\LoggingConfig;
use Monolog\Formatter\JsonFormatter;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

final readonly class StdoutLoggerFactory
{
    public function __construct(private CorrelationContext $context) {}

    public function create(LoggingConfig $config, string $runtime): Logger
    {
        if (Cluster::isWorker()) {
            $handler = Cluster::createLogHandler();
        } else {
            $handler = new StreamHandler(ByteStream\getStdout());
            $handler->setFormatter(match ($config->format) {
                LogFormat::Json => new JsonFormatter(ignoreEmptyContextAndExtra: true),
                LogFormat::Console => new ConsoleFormatter(),
            });
        }

        $logger = new Logger($config->service);
        $logger->pushHandler($handler);
        $logger->pushProcessor(new CorrelationProcessor($this->context, $config->service, $runtime));
        $logger->pushProcessor(new PsrLogMessageProcessor());

        return $logger;
    }
}
