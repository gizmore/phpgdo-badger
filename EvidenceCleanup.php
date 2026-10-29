<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\Date\Time;
use GDO\DB\Database;
use RuntimeException;
use Throwable;

final class EvidenceCleanup
{
    /** Retry-safe: delete the source first; a failed database commit can be retried. */
    public static function run(string $directory, int $rejectedDelay, int $now, int $limit = 100, ?callable $onError = null): int
    {
        if ($directory === '' || $rejectedDelay < 0 || $limit < 1 || $limit > 1000) { throw new RuntimeException('Invalid cleanup configuration'); }
        $db = Database::instance();
        $module = Module_Badger::instance();
        $cursor = max(0, (int) $module->getConfigVar('bb_evidence_cleanup_cursor'));
        $cutoff = GDO::quoteS(Time::getDate($now - $rejectedDelay));
        $condition = "EXISTS (SELECT 1 FROM gdo_badger_attest t WHERE t.bat_achieve=e.be_achieve AND t.bat_round=e.be_round AND (t.bat_to_status='verified' OR (t.bat_to_status='rejected' AND t.bat_created <= {$cutoff})))";
        $results = $db->queryRead("SELECT e.be_id FROM gdo_badger_evidence e WHERE e.be_deleted=0 AND {$condition} ORDER BY CASE WHEN e.be_id > {$cursor} THEN 0 ELSE 1 END, e.be_id LIMIT {$limit}");
        $ids = [];
        while ($row = $results->fetch_assoc()) { $ids[] = $row['be_id']; }
        $count = 0;
        foreach ($ids as $evidenceId)
        {
            $removed = false;
            if (!$db->transactionBegin()) { throw new RuntimeException('Could not begin evidence cleanup'); }
            try
            {
                $id = GDO::quoteS($evidenceId);
                $row = $db->queryRead("SELECT e.be_key FROM gdo_badger_evidence e WHERE e.be_id={$id} AND e.be_deleted=0 AND {$condition} FOR UPDATE")->fetch_assoc();
                if ($row)
                {
                    EvidenceVault::remove($directory, $row['be_key']);
                    if (!$db->queryWrite("UPDATE gdo_badger_evidence SET be_deleted=1 WHERE be_id={$id} AND be_deleted=0"))
                    {
                        throw new RuntimeException('Could not record evidence cleanup');
                    }
                    $removed = true;
                }
                if (!$db->transactionEnd()) { throw new RuntimeException('Could not commit evidence cleanup'); }
                if ($removed) { $count++; }
            }
            catch (Throwable $ex)
            {
                $db->transactionRollback();
                if ($onError) { $onError(); }
                else { error_log('Badger evidence cleanup failed; it remains eligible for retry.'); }
            }
            $module->saveConfigVar('bb_evidence_cleanup_cursor', $evidenceId);
        }
        return $count;
    }
}
