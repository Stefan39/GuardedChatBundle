<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Dto;

/** One event of the streamed reply: either a piece of text or a configured action (e.g. "contact"). */
final readonly class ChatReplyEvent
{
    private function __construct(
        public ?string $text,
        public ?string $action,
    ) {
    }

    public static function text(string $text): self
    {
        if ('' === $text) {
            throw new \InvalidArgumentException('A text reply event cannot be empty.');
        }

        return new self($text, null);
    }

    public static function action(string $type): self
    {
        if ('' === $type) {
            throw new \InvalidArgumentException('An action reply event needs a type.');
        }

        return new self(null, $type);
    }
}
