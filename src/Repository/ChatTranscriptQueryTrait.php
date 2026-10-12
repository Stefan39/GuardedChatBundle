<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Repository;

use Doctrine\ORM\QueryBuilder;
use Stejac\GuardedChatBundle\Dto\ChatTranscriptFilter;

/**
 * Shared query building for reading sessions and messages: date-range filter and pagination.
 * The using repositories build the query builder themselves and differ only in alias, sort direction and (for messages) an extra join.
 */
trait ChatTranscriptQueryTrait
{
    /**
     * Applies the date-range filter shared by the list and count queries. The end of the range is
     * exclusive on the day *after* `to`, not inclusive on `to` itself: a `<=` comparison would drop
     * every row created on the target day.
     */
    private function applyTranscriptFilter(QueryBuilder $qb, ChatTranscriptFilter $filter, string $alias): QueryBuilder
    {
        if (null !== $filter->from) {
            $qb->andWhere("$alias.createdAt >= :from")->setParameter('from', $filter->from);
        }
        if (null !== $filter->to) {
            $qb->andWhere("$alias.createdAt < :toExclusive")->setParameter('toExclusive', $filter->to->modify('+1 day'));
        }

        return $qb;
    }

    /**
     * Orders and paginates a filtered listing query. Ties on `createdAt` are broken by `id`: rows
     * written by the same handler call (a question and its reply) can share a timestamp, and without
     * the tie-break pagination across such a pair returns duplicate or skipped rows.
     */
    private function paginateTranscript(QueryBuilder $qb, ChatTranscriptFilter $filter, string $alias, string $orderDirection): QueryBuilder
    {
        return $qb
            ->orderBy("$alias.createdAt", $orderDirection)
            ->addOrderBy("$alias.id", $orderDirection)
            ->setFirstResult($filter->offset)
            ->setMaxResults($filter->limit);
    }
}
