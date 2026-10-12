<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Exception;

/** The model gateway could not deliver a completion (transport, HTTP or stream failure). */
class ChatGatewayException extends \RuntimeException implements GuardedChatExceptionInterface
{
}
