<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Twig;

use Stejac\GuardedChatBundle\Service\ChatAvailability;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `chat_enabled()` tells a template whether to render the widget, with the same two-stage check the
 * session endpoint applies. {@see ChatAvailability} is a Twig runtime, so the runtime switch is only
 * built when a template actually calls the function.
 */
final class GuardedChatExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('chat_enabled', [ChatAvailability::class, 'isEnabled']),
        ];
    }
}
