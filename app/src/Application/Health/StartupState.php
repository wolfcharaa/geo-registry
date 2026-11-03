<?php

declare(strict_types=1);

namespace App\Application\Health;

final class StartupState
{
    private bool $complete = false;

    public function markComplete(): void
    {
        $this->complete = true;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
