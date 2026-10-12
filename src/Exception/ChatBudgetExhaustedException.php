<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Exception;

/**
 * The gateway reports that its budget or quota is used up: no completion will come through until the
 * window resets. Distinct from a generic gateway failure because the visitor-facing answer differs.
 */
final class ChatBudgetExhaustedException extends ChatGatewayException implements GuardedChatExceptionInterface
{
}
