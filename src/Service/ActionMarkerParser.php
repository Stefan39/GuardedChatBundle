<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Service;

/**
 * Turns configured action markers (e.g. `[[contact]]`) in a streamed reply into actions, one parser
 * per reply. A marker can arrive split across chunks, so a possible marker prefix at the end of a
 * chunk is held back until the next chunk shows what it was.
 *
 * Models do not reliably keep a marker terminal as instructed, and a leaked marker in the middle of
 * a sentence is worse than an extra action, so a marker anywhere still triggers its action.
 */
final class ActionMarkerParser
{
    private string $tail = '';

    /** @var list<string> */
    private array $actions = [];

    /** @param array<string, string> $markers action type => marker text */
    public function __construct(
        private readonly array $markers,
    ) {
    }

    /** @return string the text that is safe to show now, possibly empty */
    public function push(string $delta): string
    {
        if ([] === $this->markers) {
            return $delta;
        }

        [$text, $this->tail] = $this->splitTail($this->tail.$delta);

        return $this->stripMarkers($text);
    }

    /** @return string text that was held back and turned out not to be a marker, possibly empty */
    public function finish(): string
    {
        $tail = $this->tail;
        $this->tail = '';

        foreach ($this->markers as $type => $marker) {
            if ($marker === rtrim($tail)) {
                $this->addAction((string) $type);

                return '';
            }
        }

        return $tail;
    }

    /** @return list<string> the action types seen so far, each once, in order of appearance */
    public function actions(): array
    {
        return $this->actions;
    }

    /** @return array{string, string} */
    private function splitTail(string $text): array
    {
        // A complete marker plus trailing whitespace at the very end: models routinely close a reply
        // with a newline, and without this the terminal marker would leak into the visible text.
        foreach ($this->markers as $marker) {
            if (1 === preg_match('/'.preg_quote($marker, '/').'\s*$/', $text, $matches, \PREG_OFFSET_CAPTURE)) {
                return [substr($text, 0, (int) $matches[0][1]), $matches[0][0]];
            }
        }

        $bestLength = 0;
        foreach ($this->markers as $marker) {
            for ($length = min(\strlen($text), \strlen($marker) - 1); $length > $bestLength; --$length) {
                if (str_ends_with($text, substr($marker, 0, $length))) {
                    $bestLength = $length;

                    break;
                }
            }
        }

        return 0 === $bestLength ? [$text, ''] : [substr($text, 0, -$bestLength), substr($text, -$bestLength)];
    }

    private function stripMarkers(string $text): string
    {
        foreach ($this->markers as $type => $marker) {
            if (!str_contains($text, $marker)) {
                continue;
            }

            $this->addAction((string) $type);
            // Between two words the marker plus its padding collapses to one space, so
            // "Website [[contact]] visit" does not end up with a double gap.
            $text = (string) preg_replace('/(?<=\S)[ \t]*'.preg_quote($marker, '/').'[ \t]*(?=\S)/u', ' ', $text);
            $text = str_replace($marker, '', $text);
        }

        return $text;
    }

    private function addAction(string $type): void
    {
        if (!\in_array($type, $this->actions, true)) {
            $this->actions[] = $type;
        }
    }
}
