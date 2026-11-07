<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserInterface\Http;

use App\UserInterface\Http\HttpWorkerRecyclePolicy;
use App\UserInterface\Http\HttpWorkerRecycleReason;
use Testo\Assert;
use Testo\Test;

#[Test]
final class HttpWorkerRecyclePolicyTest
{
    public function testItRequestsRecycleAfterTheRequestBudgetIsExhausted(): void
    {
        $policy = new HttpWorkerRecyclePolicy(maxRequests: 2, maxResidentMemoryBytes: 0);

        Assert::same($policy->observe(1), null);
        Assert::same($policy->observe(1), HttpWorkerRecycleReason::MaxRequests);
        Assert::same($policy->handledRequests(), 2);
    }

    public function testMemoryBudgetTakesPrecedenceOverTheRequestBudget(): void
    {
        $policy = new HttpWorkerRecyclePolicy(maxRequests: 1, maxResidentMemoryBytes: 1024);

        Assert::same($policy->observe(1024), HttpWorkerRecycleReason::MaxResidentMemory);
    }

    public function testZeroBudgetsDisableRecycling(): void
    {
        $policy = new HttpWorkerRecyclePolicy(maxRequests: 0, maxResidentMemoryBytes: 0);

        Assert::same($policy->observe(PHP_INT_MAX), null);
        Assert::same($policy->handledRequests(), 1);
    }
}
