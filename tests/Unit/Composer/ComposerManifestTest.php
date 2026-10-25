<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Composer;

use PHPUnit\Framework\TestCase;

final class ComposerManifestTest extends TestCase
{
    public function testCarriesTheSymfonyUxKeywordSoFlexKeepsTheControllersJsonEntry(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__.'/../../../composer.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);
        self::assertIsArray($manifest['keywords'] ?? null);
        self::assertContains('symfony-ux', $manifest['keywords']);
    }
}
