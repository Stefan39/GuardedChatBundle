<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Security;

use Psr\Clock\ClockInterface;
use Stejac\GuardedChatBundle\Exception\InvalidChatSessionTokenException;
use Symfony\Component\Uid\Uuid;

/**
 * Signs and verifies the anonymous visitor's chat session token: a fixed-format
 * `base64url(payload).base64url(hmac)` string. The signature makes the payload tamper-proof, but
 * all mutable state (message count, history) lives in the {@see \Stejac\GuardedChatBundle\Entity\ChatSession} row
 * the token's `sid` claim points at — the token itself is never re-issued mid-session.
 */
final readonly class ChatSessionTokenSigner
{
    public function __construct(
        private string $secret,
        private ClockInterface $clock,
    ) {
    }

    public function sign(Uuid $sessionId, int $ttlSeconds): string
    {
        $now = $this->clock->now();
        $payload = json_encode([
            'sid' => $sessionId->toRfc4122(),
            'exp' => $now->getTimestamp() + $ttlSeconds,
        ], JSON_THROW_ON_ERROR);

        $encodedPayload = self::base64UrlEncode($payload);

        return $encodedPayload.'.'.self::base64UrlEncode($this->hmac($encodedPayload));
    }

    public function verify(string $token): Uuid
    {
        $parts = explode('.', $token);
        if (2 !== count($parts)) {
            throw new InvalidChatSessionTokenException('Malformed chat session token.');
        }
        [$encodedPayload, $signature] = $parts;

        $expected = self::base64UrlEncode($this->hmac($encodedPayload));
        if (!hash_equals($expected, $signature)) {
            throw new InvalidChatSessionTokenException('Invalid chat session token signature.');
        }

        $decoded = json_decode(self::base64UrlDecode($encodedPayload), true);
        if (!is_array($decoded) || !isset($decoded['sid'], $decoded['exp']) || !is_string($decoded['sid']) || !is_int($decoded['exp'])) {
            throw new InvalidChatSessionTokenException('Malformed chat session token payload.');
        }

        if ($this->clock->now()->getTimestamp() > $decoded['exp']) {
            throw new InvalidChatSessionTokenException('Chat session token expired.');
        }

        try {
            return Uuid::fromString($decoded['sid']);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidChatSessionTokenException('Chat session token has an invalid session id.', 0, $e);
        }
    }

    private function hmac(string $encodedPayload): string
    {
        return hash_hmac('sha256', $encodedPayload, $this->secret, true);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
