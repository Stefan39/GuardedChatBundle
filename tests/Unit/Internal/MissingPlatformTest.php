<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Exception\ChatPlatformMissingException;
use Stejac\GuardedChatBundle\Internal\MissingPlatform;

final class MissingPlatformTest extends TestCase
{
    public function testInvokeThrows(): void
    {
        $this->expectException(ChatPlatformMissingException::class);
        $this->expectExceptionMessage('The Symfony AI platform service "ai.platform.generic.default" set in guarded_chat.platform does not exist, so the chat is switched off.');

        (new MissingPlatform('ai.platform.generic.default'))->invoke('model', 'Hello');
    }

    public function testGetModelCatalogThrows(): void
    {
        $this->expectException(ChatPlatformMissingException::class);
        $this->expectExceptionMessage('"ai.platform.generic.default"');

        (new MissingPlatform('ai.platform.generic.default'))->getModelCatalog();
    }
}
