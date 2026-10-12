<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Dto\ChatMessageRequest;
use Stejac\GuardedChatBundle\Dto\ChatReplyEvent;
use Stejac\GuardedChatBundle\Dto\ChatSessionRequest;
use Stejac\GuardedChatBundle\Exception\InvalidChatRequestException;

final class RequestPayloadTest extends TestCase
{
    public function testMessageRequestUsesTheDefaultLocale(): void
    {
        $request = ChatMessageRequest::fromPayload(['message' => 'Hello'], ['de', 'en'], 'en', 500);

        self::assertSame('Hello', $request->message);
        self::assertSame('en', $request->locale);
    }

    public function testMessageRequestAcceptsAKnownLocaleAndTheExactMaximumLength(): void
    {
        $request = ChatMessageRequest::fromPayload(['message' => str_repeat('ä', 10), 'locale' => 'de'], ['de', 'en'], 'en', 10);

        self::assertSame('de', $request->locale);
    }

    public function testMessageLengthIsCountedInCharactersNotBytes(): void
    {
        $this->expectException(InvalidChatRequestException::class);

        ChatMessageRequest::fromPayload(['message' => str_repeat('ä', 11)], ['en'], 'en', 10);
    }

    public function testSessionRequestDefaultsToAnEmptyTokenAndHoneypot(): void
    {
        $request = ChatSessionRequest::fromPayload([], ['de', 'en'], 'de');

        self::assertSame('', $request->verificationToken);
        self::assertSame('', $request->website);
        self::assertSame('de', $request->locale);
    }

    public function testSessionRequestReadsTheFields(): void
    {
        $request = ChatSessionRequest::fromPayload(['verificationToken' => 't', 'website' => 'x', 'locale' => 'en'], ['de', 'en'], 'de');

        self::assertSame('t', $request->verificationToken);
        self::assertSame('x', $request->website);
        self::assertSame('en', $request->locale);
    }

    public function testReplyEventsRefuseEmptyContent(): void
    {
        self::assertSame('hi', ChatReplyEvent::text('hi')->text);
        self::assertSame('contact', ChatReplyEvent::action('contact')->action);

        $this->expectException(\InvalidArgumentException::class);
        ChatReplyEvent::text('');
    }

    public function testActionEventsNeedAType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ChatReplyEvent::action('');
    }
}
