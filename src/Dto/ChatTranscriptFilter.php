<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Dto;

/**
 * Date-range and pagination filter for reading sessions and messages. The bundle takes typed values;
 * parsing request parameters into them is the consuming app's job.
 */
final readonly class ChatTranscriptFilter
{
    public function __construct(
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public int $limit = 25,
        public int $offset = 0,
    ) {
    }
}
