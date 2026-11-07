<?php

declare(strict_types=1);

namespace App\Application\Console\Command;

use InvalidArgumentException;

final readonly class HttpLoadCheckOptions
{
    public function __construct(
        public string $url,
        public int $requests,
        public int $concurrency,
        public float $timeoutSeconds,
        public int $expectedStatus,
        public int $maxBodyBytes,
    ) {
        $parts = parse_url($this->url);
        if (
            filter_var($this->url, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || !isset($parts['host'], $parts['scheme'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException(
                'URL must be an absolute HTTP(S) URL without embedded credentials or a fragment.',
            );
        }
        if ($this->requests < 1 || $this->requests > 1_000_000) {
            throw new InvalidArgumentException('Option --requests must be between 1 and 1000000.');
        }
        if ($this->concurrency < 1 || $this->concurrency > 1024 || $this->concurrency > $this->requests) {
            throw new InvalidArgumentException(
                'Option --concurrency must be between 1 and 1024 and not exceed --requests.',
            );
        }
        if (!is_finite($this->timeoutSeconds) || $this->timeoutSeconds <= 0 || $this->timeoutSeconds > 300) {
            throw new InvalidArgumentException('Option --timeout must be greater than 0 and at most 300 seconds.');
        }
        if ($this->expectedStatus < 100 || $this->expectedStatus > 599) {
            throw new InvalidArgumentException('Option --expected-status must be between 100 and 599.');
        }
        if ($this->maxBodyBytes < 1 || $this->maxBodyBytes > 16 * 1024 * 1024) {
            throw new InvalidArgumentException('Option --max-body-bytes must be between 1 and 16777216.');
        }
    }
}
