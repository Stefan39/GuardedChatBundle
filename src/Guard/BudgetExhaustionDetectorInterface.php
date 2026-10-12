<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Guard;

/**
 * Decides whether a gateway failure means "budget or quota used up". How a gateway reports that
 * differs (LiteLLM answers 400/429 with a message mentioning the budget), so the app can replace it.
 */
interface BudgetExhaustionDetectorInterface
{
    public function isBudgetExhausted(\Throwable $failure): bool;
}
