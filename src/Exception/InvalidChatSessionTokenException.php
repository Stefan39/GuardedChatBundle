<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Exception;

/** The chat session token is malformed, forged or expired. */
final class InvalidChatSessionTokenException extends \RuntimeException implements GuardedChatExceptionInterface
{
}
