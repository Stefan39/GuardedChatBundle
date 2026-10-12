<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Guard;

use Symfony\Component\HttpFoundation\Request;

/** Default resolver: {@see Request::getClientIp()}, which honours the app's trusted proxy configuration. */
final class RequestClientIdentifierResolver implements ClientIdentifierResolverInterface
{
    public function resolve(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }
}
