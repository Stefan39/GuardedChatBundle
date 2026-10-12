<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Guard;

/** Default verifier: lets everyone through. The honeypot field still applies. */
final class NullHumanVerifier implements HumanVerifierInterface
{
    public function verify(string $token): bool
    {
        return true;
    }
}
