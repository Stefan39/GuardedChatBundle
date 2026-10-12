<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Exception\InvalidChatSessionTokenException;
use Stejac\GuardedChatBundle\Security\ChatSessionTokenSigner;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ChatSessionTokenSignerTest extends TestCase
{
    public function testSignThenVerifyRoundTripsTheSessionId(): void
    {
        $clock = new MockClock('2026-07-26T10:00:00+00:00');
        $signer = new ChatSessionTokenSigner('test-secret', $clock);
        $sessionId = Uuid::v4();

        $token = $signer->sign($sessionId, 7200);

        self::assertTrue($sessionId->equals($signer->verify($token)));
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $clock = new MockClock('2026-07-26T10:00:00+00:00');
        $signer = new ChatSessionTokenSigner('test-secret', $clock);
        $token = $signer->sign(Uuid::v4(), 7200);
        [$payload] = explode('.', $token);

        $this->expectException(InvalidChatSessionTokenException::class);
        $signer->verify($payload.'.tampered-signature');
    }

    public function testSignatureFromADifferentSecretIsRejected(): void
    {
        $clock = new MockClock('2026-07-26T10:00:00+00:00');
        $token = (new ChatSessionTokenSigner('secret-a', $clock))->sign(Uuid::v4(), 7200);

        $this->expectException(InvalidChatSessionTokenException::class);
        (new ChatSessionTokenSigner('secret-b', $clock))->verify($token);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $clock = new MockClock('2026-07-26T10:00:00+00:00');
        $signer = new ChatSessionTokenSigner('test-secret', $clock);
        $token = $signer->sign(Uuid::v4(), 7200);

        $clock->modify('+2 hours +1 second');

        $this->expectException(InvalidChatSessionTokenException::class);
        $signer->verify($token);
    }

    public function testMalformedTokenIsRejected(): void
    {
        $signer = new ChatSessionTokenSigner('test-secret', new MockClock());

        $this->expectException(InvalidChatSessionTokenException::class);
        $signer->verify('not-a-valid-token');
    }

    public function testCorrectlySignedButIncompletePayloadIsRejected(): void
    {
        $signer = new ChatSessionTokenSigner('test-secret', new MockClock());

        $this->expectException(InvalidChatSessionTokenException::class);
        $signer->verify($this->signRawPayload('{"sid":"missing-the-exp-claim"}', 'test-secret'));
    }

    public function testCorrectlySignedButInvalidSessionIdIsRejected(): void
    {
        $clock = new MockClock('2026-07-26T10:00:00+00:00');
        $signer = new ChatSessionTokenSigner('test-secret', $clock);
        $payload = (string) json_encode(['sid' => 'not-a-uuid', 'exp' => $clock->now()->getTimestamp() + 7200]);

        $this->expectException(InvalidChatSessionTokenException::class);
        $signer->verify($this->signRawPayload($payload, 'test-secret'));
    }

    private function signRawPayload(string $jsonPayload, string $secret): string
    {
        $encodedPayload = rtrim(strtr(base64_encode($jsonPayload), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encodedPayload, $secret, true)), '+/', '-_'), '=');

        return $encodedPayload.'.'.$signature;
    }
}
