<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Dto;

use Stejac\GuardedChatBundle\Exception\InvalidChatRequestException;

/**
 * Payload of POST /chat/session. `website` is a honeypot: it is hidden from humans in the widget
 * markup, so any non-empty value means an automated submitter filled the form.
 */
final readonly class ChatSessionRequest
{
    public function __construct(
        public string $verificationToken = '',
        public string $website = '',
        public string $locale = 'en',
    ) {
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $locales
     */
    public static function fromPayload(array $payload, array $locales, string $defaultLocale): self
    {
        return new self(
            self::optionalString($payload, 'verificationToken'),
            self::optionalString($payload, 'website'),
            ChatMessageRequest::locale($payload, $locales, $defaultLocale),
        );
    }

    /** @param array<mixed> $payload */
    private static function optionalString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';
        if (!\is_string($value)) {
            throw new InvalidChatRequestException(sprintf('"%s" must be a string.', $key));
        }

        return $value;
    }
}
