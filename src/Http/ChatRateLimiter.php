<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Http;

use Stejac\GuardedChatBundle\Guard\ClientIdentifierResolverInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Per-client rate-limit guard for the public chat. Carries a human-readable message because the chat
 * widget renders the `message` field of an error response directly into the conversation. The caller
 * translates the message with the visitor's locale; this class stays locale-agnostic.
 */
final readonly class ChatRateLimiter
{
    public function __construct(
        private ClientIdentifierResolverInterface $clientResolver,
    ) {
    }

    public function reject(RateLimiterFactory $limiter, Request $request, string $message): ?JsonResponse
    {
        if ($limiter->create($this->clientResolver->resolve($request))->consume()->isAccepted()) {
            return null;
        }

        return new JsonResponse(['error' => 'rate_limited', 'message' => $message], Response::HTTP_TOO_MANY_REQUESTS);
    }
}
