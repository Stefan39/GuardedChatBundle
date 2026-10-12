<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Exception;

/** Any failure inside the chat pipeline that is not a known, named case (e.g. a persistence error). */
final class UnexpectedChatFailureException extends \RuntimeException implements GuardedChatExceptionInterface
{
}
