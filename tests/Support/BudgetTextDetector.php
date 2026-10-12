<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Support;

use Stejac\GuardedChatBundle\Guard\BudgetExhaustionDetectorInterface;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;

/** The LiteLLM-style detector an app would bind: 429, or a 400 whose message mentions the budget. */
final class BudgetTextDetector implements BudgetExhaustionDetectorInterface
{
    public function isBudgetExhausted(\Throwable $failure): bool
    {
        return $failure instanceof RateLimitExceededException
            || ($failure instanceof BadRequestException && str_contains(strtolower($failure->getMessage()), 'budget'));
    }
}
