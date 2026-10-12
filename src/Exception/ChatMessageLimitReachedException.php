<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Exception;

/** The session already holds the maximum number of visitor messages. */
final class ChatMessageLimitReachedException extends \RuntimeException implements GuardedChatExceptionInterface
{
}
