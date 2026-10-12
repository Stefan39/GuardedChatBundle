<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Knowledge;

use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Entity\ChatKnowledgeSnippet;
use Stejac\GuardedChatBundle\Enum\ChatKnowledgeSource;
use Stejac\GuardedChatBundle\Knowledge\ChatKnowledgeFormatter;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class ChatKnowledgeFormatterTest extends TestCase
{
    public function testReturnsEmptyStringWithoutSnippets(): void
    {
        self::assertSame('', $this->formatter('Acme Corp')->toPromptBlock([], 'en'));
    }

    public function testNamesTheConfiguredSubjectInTheHeader(): void
    {
        $block = $this->formatter('Acme Corp')->toPromptBlock([$this->snippet('Stack', null, 'Symfony and PHP.')], 'en');

        self::assertStringContainsString('Verified information about Acme Corp.', $block);
        self::assertStringContainsString('- Stack: Symfony and PHP.', $block);
    }

    public function testWithoutASubjectTheHeaderNamesNobody(): void
    {
        $block = $this->formatter(null)->toPromptBlock([$this->snippet('Stack', null, 'Symfony and PHP.')], 'en');

        self::assertStringContainsString('Verified information. Base your answer', $block);
        self::assertStringNotContainsString('%subject%', $block);
        self::assertStringNotContainsString('about', explode("\n", trim($block))[0]);
    }

    public function testTheHeaderFollowsTheLocale(): void
    {
        $block = $this->formatter('Acme Corp')->toPromptBlock([$this->snippet('Stack', null, 'Symfony und PHP.')], 'de');

        self::assertStringContainsString('Gesicherte Informationen über Acme Corp.', $block);
    }

    public function testRendersFaqSnippetWithItsQuestion(): void
    {
        $block = $this->formatter(null)->toPromptBlock([$this->snippet('Contact', 'How do I reach you?', 'Use the contact form.')], 'en');

        self::assertStringContainsString('(Typical question: How do I reach you?) Use the contact form.', $block);
    }

    public function testOmitsTheQuestionMarkerForPlainKnowledge(): void
    {
        $block = $this->formatter(null)->toPromptBlock([$this->snippet('Stack', null, 'Symfony and PHP.')], 'en');

        self::assertStringNotContainsString('Typical question', $block);
    }

    public function testRendersTheSourceUrlWhenPresent(): void
    {
        $block = $this->formatter(null)->toPromptBlock([$this->snippet('Blog', null, 'An article about TDD.', 'https://example.com/blog/tdd')], 'en');

        self::assertStringContainsString('[Source: https://example.com/blog/tdd]', $block);
    }

    public function testRendersEverySnippetInOrder(): void
    {
        $block = $this->formatter(null)->toPromptBlock([
            $this->snippet('First', null, 'Content one.'),
            $this->snippet('Second', null, 'Content two.'),
        ], 'en');

        self::assertGreaterThan(strpos($block, 'Content one.'), strpos($block, 'Content two.'));
    }

    private function formatter(?string $subject): ChatKnowledgeFormatter
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'de'] as $locale) {
            $translator->addResource('yaml', __DIR__.'/../../../translations/guarded_chat.'.$locale.'.yaml', $locale, 'guarded_chat');
        }

        return new ChatKnowledgeFormatter($translator, $subject);
    }

    private function snippet(string $title, ?string $question, string $content, ?string $sourceUrl = null): ChatKnowledgeSnippet
    {
        return new ChatKnowledgeSnippet($title, $question, $content, 1, $sourceUrl, ChatKnowledgeSource::Admin, new \DateTimeImmutable('2026-07-29 08:00:00'));
    }
}
