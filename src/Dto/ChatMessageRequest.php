<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Dto;

use Stejac\GuardedChatBundle\Exception\InvalidChatRequestException;

/** Payload of POST /chat/message. */
final readonly class ChatMessageRequest
{
    public function __construct(
        public string $message,
        public string $locale,
    ) {
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $locales
     */
    public static function fromPayload(array $payload, array $locales, string $defaultLocale, int $maxLength): self
    {
        $message = $payload['message'] ?? null;
        if (!\is_string($message) || '' === trim($message)) {
            throw new InvalidChatRequestException('"message" must be a non-empty string.');
        }
        if (mb_strlen($message) > $maxLength) {
            throw new InvalidChatRequestException(sprintf('"message" must not exceed %d characters.', $maxLength));
        }

        return new self($message, self::locale($payload, $locales, $defaultLocale));
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $locales
     */
    public static function locale(array $payload, array $locales, string $defaultLocale): string
    {
        $locale = $payload['locale'] ?? $defaultLocale;
        if (!\is_string($locale) || !\in_array($locale, $locales, true)) {
            throw new InvalidChatRequestException(sprintf('"locale" must be one of: %s.', implode(', ', $locales)));
        }

        return $locale;
    }
}
