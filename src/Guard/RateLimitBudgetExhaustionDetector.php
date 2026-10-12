<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Guard;

use Symfony\AI\Platform\Exception\RateLimitExceededException;

/** Default detector: an HTTP 429 from the gateway counts as budget or quota exhaustion. */
final class RateLimitBudgetExhaustionDetector implements BudgetExhaustionDetectorInterface
{
    public function isBudgetExhausted(\Throwable $failure): bool
    {
        return $failure instanceof RateLimitExceededException;
    }
}
