<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\DependencyInjection\Compiler;

use Stejac\GuardedChatBundle\Exception\ChatPlatformMissingException;
use Stejac\GuardedChatBundle\Internal\MissingPlatform;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * A fresh project has no platform service yet. Instead of failing the container, point the platform
 * alias at a stand-in and switch the chat off by configuration.
 */
final class MissingPlatformPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $platformId = $container->getParameterBag()->resolveValue($container->getParameter('guarded_chat.platform_id'));
        \assert(\is_string($platformId));

        if ($container->has($platformId)) {
            return;
        }

        $container->setDefinition(MissingPlatform::class, new Definition(MissingPlatform::class, [$platformId]));
        $container->setAlias('guarded_chat.platform', MissingPlatform::class);
        $container->setParameter('guarded_chat.platform_available', false);
        $container->log($this, ChatPlatformMissingException::message($platformId));
    }
}
