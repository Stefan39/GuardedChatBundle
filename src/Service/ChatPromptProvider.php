<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Service;

use Stejac\GuardedChatBundle\Repository\ChatPromptRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolves the system prompt for the assistant: the active stored version, or the configured default
 * when none has been saved yet, followed by the instructions that must hold for every version.
 */
final readonly class ChatPromptProvider
{
    /**
     * @param list<string>          $pinnedInstructions appended to every prompt version (form of address, output format, ...)
     * @param array<string, string> $actionInstructions action type => instruction telling the model when to emit the marker
     */
    public function __construct(
        private ChatPromptRepository $prompts,
        private TranslatorInterface $translator,
        private string $defaultPrompt,
        private bool $aiTransparency,
        private array $pinnedInstructions,
        private array $actionInstructions,
    ) {
    }

    public function current(string $locale): string
    {
        $prompt = $this->prompts->findActive()?->getContent() ?? $this->defaultPrompt;

        // Appended here, not stored with the prompt, so the rules hold for every version including
        // ones created later through an admin UI.
        $instructions = $this->aiTransparency
            ? [$this->translator->trans('prompt.ai_transparency', [], 'guarded_chat', $locale)]
            : [];

        foreach ([...$instructions, ...$this->pinnedInstructions, ...array_values($this->actionInstructions)] as $instruction) {
            $prompt .= "\n\n".$instruction;
        }

        return $prompt;
    }
}
