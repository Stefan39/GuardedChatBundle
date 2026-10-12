<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Exception;

/** Thrown by the prefilter to stop a request before it reaches the model. */
final class BlockedInputException extends \RuntimeException implements GuardedChatExceptionInterface
{
}
