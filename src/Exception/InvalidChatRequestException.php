<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Exception;

/** The request payload of a public chat endpoint is missing a field or violates a limit. */
final class InvalidChatRequestException extends \RuntimeException implements GuardedChatExceptionInterface
{
}
