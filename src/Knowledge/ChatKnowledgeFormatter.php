<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Knowledge;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders retrieved snippets into the block appended to the system prompt. The wording is translatable
 * and names the subject only when `guarded_chat.knowledge.subject` is configured.
 */
final readonly class ChatKnowledgeFormatter
{
    public function __construct(
        private TranslatorInterface $translator,
        private ?string $subject,
    ) {
    }

    /** @param list<KnowledgeSnippetInterface> $snippets */
    public function toPromptBlock(array $snippets, string $locale): string
    {
        if ([] === $snippets) {
            return '';
        }

        $block = "\n\n".(null === $this->subject
            ? $this->trans('knowledge.header_generic', [], $locale)
            : $this->trans('knowledge.header', ['%subject%' => $this->subject], $locale))."\n";

        foreach ($snippets as $snippet) {
            $block .= "\n- ".$snippet->getTitle().': ';
            if (null !== $snippet->getQuestion()) {
                $block .= $this->trans('knowledge.typical_question', ['%question%' => $snippet->getQuestion()], $locale).' ';
            }
            $block .= $snippet->getContent();
            if (null !== $snippet->getSourceUrl()) {
                $block .= ' '.$this->trans('knowledge.source', ['%url%' => $snippet->getSourceUrl()], $locale);
            }
        }

        return $block;
    }

    /** @param array<string, string> $parameters */
    private function trans(string $key, array $parameters, string $locale): string
    {
        return $this->translator->trans($key, $parameters, 'guarded_chat', $locale);
    }
}
