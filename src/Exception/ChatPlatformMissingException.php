<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Exception;

/** The Symfony AI platform service named in `guarded_chat.platform` does not exist. */
class ChatPlatformMissingException extends \LogicException implements GuardedChatExceptionInterface
{
    public static function forPlatform(string $platformId): self
    {
        return new self(self::message($platformId));
    }

    public static function message(string $platformId): string
    {
        return sprintf('The Symfony AI platform service "%s" set in guarded_chat.platform does not exist, so the chat is switched off. Configure a platform (see symfony/ai-bundle) and set guarded_chat.platform to its service id.', $platformId);
    }
}
