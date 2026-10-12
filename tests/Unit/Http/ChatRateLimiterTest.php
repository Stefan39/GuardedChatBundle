<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Guard\ClientIdentifierResolverInterface;
use Stejac\GuardedChatBundle\Guard\RequestClientIdentifierResolver;
use Stejac\GuardedChatBundle\Http\ChatRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ChatRateLimiterTest extends TestCase
{
    private const string MESSAGE = 'Too many requests.';

    // RFC 5737 TEST-NET-1/TEST-NET-2: reserved for documentation, never assigned to a real host.
    private const string CLIENT_IP = '192.0.2.1';
    private const string OTHER_CLIENT_IP = '198.51.100.1';

    private function limiterFactory(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
    }

    private function request(string $ip): Request
    {
        return Request::create('/', 'POST', server: ['REMOTE_ADDR' => $ip]);
    }

    public function testRequestsWithinTheLimitPass(): void
    {
        $limiter = new ChatRateLimiter(new RequestClientIdentifierResolver());
        $factory = $this->limiterFactory(2);

        self::assertNull($limiter->reject($factory, $this->request(self::CLIENT_IP), self::MESSAGE));
        self::assertNull($limiter->reject($factory, $this->request(self::CLIENT_IP), self::MESSAGE));
    }

    public function testTheRequestAboveTheLimitIsRejectedWithTheStableErrorCode(): void
    {
        $limiter = new ChatRateLimiter(new RequestClientIdentifierResolver());
        $factory = $this->limiterFactory(1);

        $limiter->reject($factory, $this->request(self::CLIENT_IP), self::MESSAGE);
        $response = $limiter->reject($factory, $this->request(self::CLIENT_IP), self::MESSAGE);

        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame(['error' => 'rate_limited', 'message' => self::MESSAGE], json_decode((string) $response->getContent(), true));
    }

    public function testLimitsAreCountedPerClient(): void
    {
        $limiter = new ChatRateLimiter(new RequestClientIdentifierResolver());
        $factory = $this->limiterFactory(1);

        $limiter->reject($factory, $this->request(self::CLIENT_IP), self::MESSAGE);

        self::assertNull($limiter->reject($factory, $this->request(self::OTHER_CLIENT_IP), self::MESSAGE));
    }

    public function testTheClientIdentifierIsPluggable(): void
    {
        $byHeader = new class implements ClientIdentifierResolverInterface {
            public function resolve(Request $request): string
            {
                return (string) $request->headers->get('X-Client');
            }
        };
        $limiter = new ChatRateLimiter($byHeader);
        $factory = $this->limiterFactory(1);
        $first = Request::create('/', 'POST', server: ['HTTP_X_CLIENT' => 'a']);
        $second = Request::create('/', 'POST', server: ['HTTP_X_CLIENT' => 'b']);

        $limiter->reject($factory, $first, self::MESSAGE);

        self::assertNotNull($limiter->reject($factory, $first, self::MESSAGE));
        self::assertNull($limiter->reject($factory, $second, self::MESSAGE));
    }
}
