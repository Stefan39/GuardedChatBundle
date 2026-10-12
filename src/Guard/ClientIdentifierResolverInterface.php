<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Guard;

use Symfony\Component\HttpFoundation\Request;

/** Names the client a rate limit counts against (usually the IP address behind any proxy). */
interface ClientIdentifierResolverInterface
{
    public function resolve(Request $request): string;
}
