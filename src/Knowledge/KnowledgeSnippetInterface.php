<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Knowledge;

/** One piece of curated knowledge the assistant may draw on, whatever store it comes from. */
interface KnowledgeSnippetInterface
{
    /** Stable identifier; recorded with each answer so it is traceable which knowledge fed it. */
    public function getReferenceId(): string;

    public function getTitle(): string;

    /** The typical question this answers (FAQ entries), or null for plain knowledge. */
    public function getQuestion(): ?string;

    public function getContent(): string;

    public function getSourceUrl(): ?string;
}
