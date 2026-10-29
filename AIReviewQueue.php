<?php
declare(strict_types=1);
namespace GDO\Badger;

final class AIReviewQueue
{
    /** Visit IDs after the last attempt first, then wrap to the beginning. */
    public static function orderBy(int $cursor): string
    {
        $cursor = max(0, $cursor);
        return "CASE WHEN a.ba_id > {$cursor} THEN 0 ELSE 1 END, a.ba_id";
    }

    /** Advance only after a provider attempt; skipped snapshots do not consume the batch. */
    public static function process(iterable $ids, int $limit, callable $attempt, callable $saveCursor, callable $onError): int
    {
        $called = 0;
        foreach ($ids as $id)
        {
            if ($called >= $limit) { break; }
            try
            {
                $attempted = $attempt((string) $id);
            }
            catch (\Throwable $ex)
            {
                $attempted = true;
                $onError();
            }
            if ($attempted)
            {
                $called++;
                $saveCursor((string) $id);
            }
        }
        return $called;
    }
}
