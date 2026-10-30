<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\DependencyInjection\Compiler\MissingPlatformPass;
use Stejac\GuardedChatBundle\Exception\ChatPlatformMissingException;
use Stejac\GuardedChatBundle\Internal\MissingPlatform;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MissingPlatformPassTest extends TestCase
{
    public function testExistingPlatformIsLeftAlone(): void
    {
        $container = $this->container();
        $container->register('my.platform', \stdClass::class);

        (new MissingPlatformPass())->process($container);

        self::assertSame('my.platform', (string) $container->getAlias('guarded_chat.platform'));
        self::assertTrue($container->getParameter('guarded_chat.platform_available'));
        self::assertFalse($container->hasDefinition(MissingPlatform::class));
    }

    public function testMissingPlatformIsReplacedAndLogged(): void
    {
        $container = $this->container();

        (new MissingPlatformPass())->process($container);

        self::assertSame(MissingPlatform::class, (string) $container->getAlias('guarded_chat.platform'));
        self::assertFalse($container->getParameter('guarded_chat.platform_available'));
        self::assertSame(['my.platform'], $container->getDefinition(MissingPlatform::class)->getArguments());
        self::assertContains(MissingPlatformPass::class.': '.ChatPlatformMissingException::message('my.platform'), $container->getCompiler()->getLog());
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('guarded_chat.platform_id', 'my.platform');
        $container->setParameter('guarded_chat.platform_available', true);
        $container->setAlias('guarded_chat.platform', 'my.platform');

        return $container;
    }
}
