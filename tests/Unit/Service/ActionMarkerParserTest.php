<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stejac\GuardedChatBundle\Service\ActionMarkerParser;

final class ActionMarkerParserTest extends TestCase
{
    private const array MARKERS = ['contact' => '[[contact]]', 'booking' => '[[book]]'];

    /**
     * @param list<string>          $chunks
     * @param array<string, string> $markers
     *
     * @return array{string, list<string>}
     */
    private function parse(array $chunks, array $markers = self::MARKERS): array
    {
        $parser = new ActionMarkerParser($markers);
        $text = '';
        foreach ($chunks as $chunk) {
            $text .= $parser->push($chunk);
        }

        return [$text.$parser->finish(), $parser->actions()];
    }

    public function testWithoutMarkersEverythingPassesThrough(): void
    {
        self::assertSame(['Hello [[contact]]', []], $this->parse(['Hello ', '[[contact]]'], []));
    }

    public function testTerminalMarkerBecomesAnAction(): void
    {
        self::assertSame(['Write to us. ', ['contact']], $this->parse(['Write to us. ', '[[contact]]']));
    }

    public function testTerminalMarkerFollowedByNewlineIsStillTerminal(): void
    {
        self::assertSame(['Write to us.', ['contact']], $this->parse(["Write to us.[[contact]]\n"]));
    }

    public function testMarkerSplitAcrossChunksIsRecognised(): void
    {
        self::assertSame(['Write to us. ', ['contact']], $this->parse(['Write to us. [[con', 'tact]]']));
    }

    public function testMarkerInTheMiddleOfASentenceIsRemovedAndStillTriggersTheAction(): void
    {
        self::assertSame(['Visit the website to get in touch.', ['contact']], $this->parse(['Visit the website [[contact]] to get in touch.']));
    }

    public function testHeldBackPrefixThatTurnsOutToBeTextIsEmittedLater(): void
    {
        self::assertSame(['Array [ 1, 2 ]', []], $this->parse(['Array [', ' 1, 2 ]']));
    }

    public function testDanglingPrefixAtTheEndOfTheStreamIsEmittedAsText(): void
    {
        self::assertSame(['Almost [[cont', []], $this->parse(['Almost [[cont']));
    }

    public function testSeveralMarkersYieldTheirActionsInOrderOfAppearance(): void
    {
        self::assertSame(['Book or call. ', ['booking', 'contact']], $this->parse(['Book [[book]] or call. [[contact]]']));
    }

    public function testRepeatedMarkerTriggersItsActionOnlyOnce(): void
    {
        self::assertSame(['a b c', ['booking']], $this->parse(['a [[book]] b [[book]] c']));
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function chunkings(): iterable
    {
        $text = 'Gladly help. [[contact]]';
        yield 'whole' => [[$text], 'Gladly help. '];
        yield 'char by char' => [mb_str_split($text), 'Gladly help. '];
        yield 'in the marker' => [['Gladly help. [', '[', 'conta', 'ct', ']', ']'], 'Gladly help. '];
    }

    /** @param list<string> $chunks */
    #[DataProvider('chunkings')]
    public function testTheChunkingNeverChangesTheOutcome(array $chunks, string $expectedText): void
    {
        self::assertSame([$expectedText, ['contact']], $this->parse($chunks));
    }
}
