<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Prefilter;

/**
 * Cheap, zero-cost rejection of obvious jailbreak and prompt-injection attempts before any model call
 * happens. Real semantic off-topic detection is deliberately out of scope: the system prompt itself is
 * what instructs the model to decline unrelated questions.
 */
final readonly class OffTopicPrefilter
{
    /**
     * Word-boundary-anchored, so a phrase only matches as a standalone word or phrase and not as a
     * substring of an unrelated longer word (e.g. "act as" must not fire on "contact as soon as possible").
     */
    private string $blockedPhrasePattern;

    /** @param list<string> $blockedPhrases */
    public function __construct(array $blockedPhrases)
    {
        if ([] === $blockedPhrases) {
            $this->blockedPhrasePattern = '';

            return;
        }

        $escaped = array_map(static fn (string $phrase): string => preg_quote($phrase, '/'), $blockedPhrases);
        $this->blockedPhrasePattern = '/\b(?:'.implode('|', $escaped).')\b/ui';
    }

    public function isBlocked(string $message): bool
    {
        return '' !== $this->blockedPhrasePattern && 1 === preg_match($this->blockedPhrasePattern, $message);
    }
}
