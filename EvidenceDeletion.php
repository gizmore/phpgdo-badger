<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class EvidenceDeletion
{
    public static function removeRejected(GDO_User $actor, GDO_Achieve $target, string $directory, bool $ownsTransaction = true): void
    {
        if (!$actor->isMember() || !$actor->getID() || !$target->getID() || !$target->gdoVar('ba_badge')) { throw new RuntimeException('Evidence unavailable'); }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin()) { throw new RuntimeException('Could not begin evidence deletion'); }
        try
        {
            $badgeId = GDO::quoteS($target->gdoVar('ba_badge'));
            $id = GDO::quoteS($target->getID());
            $badge = $db->queryRead("SELECT bb_owner FROM gdo_badger_badge WHERE bb_id={$badgeId} FOR UPDATE")->fetch_assoc();
            $row = $db->queryRead("SELECT ba_badge, ba_user, ba_status, ba_round FROM gdo_badger_achieve WHERE ba_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$badge || !$row || $row['ba_badge'] !== $target->gdoVar('ba_badge') || $row['ba_user'] !== $target->gdoVar('ba_user') ||
                $row['ba_status'] !== 'rejected' || (int) $row['ba_round'] !== (int) $target->gdoVar('ba_round'))
            {
                throw new RuntimeException('Evidence unavailable');
            }
            $actorId = GDO::quoteS($actor->getID());
            $staff = $db->queryRead("SELECT ba_is_staff FROM gdo_badger_achieve WHERE ba_badge={$badgeId} AND ba_user={$actorId} FOR UPDATE")->fetch_assoc();
            if ($badge['bb_owner'] !== $actor->getID() && !$actor->isStaff() && !$actor->isAdmin() && (!$staff || $staff['ba_is_staff'] !== '1'))
            {
                throw new RuntimeException('Evidence unavailable');
            }
            $round = (int) $row['ba_round'];
            $evidence = $db->queryRead("SELECT be_id, be_key FROM gdo_badger_evidence WHERE be_achieve={$id} AND be_round={$round} AND be_deleted=0 FOR UPDATE")->fetch_assoc();
            if (!$evidence) { throw new RuntimeException('Evidence unavailable'); }
            EvidenceVault::remove($directory, $evidence['be_key']);
            $evidenceId = GDO::quoteS($evidence['be_id']);
            if (!$db->queryWrite("UPDATE gdo_badger_evidence SET be_deleted=1 WHERE be_id={$evidenceId} AND be_deleted=0"))
            {
                throw new RuntimeException('Could not record evidence deletion');
            }
            if ($ownsTransaction && !$db->transactionEnd()) { throw new RuntimeException('Could not commit evidence deletion'); }
        }
        catch (Throwable $ex)
        {
            if ($ownsTransaction) { $db->transactionRollback(); }
            throw $ex;
        }
    }
}
