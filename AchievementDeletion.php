<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class AchievementDeletion
{
    public static function mark(GDO_User $actor, GDO_Badge $badge, GDO_Achieve $achievement, bool $byRecipient, bool $ownsTransaction = true): void
    {
        if (!$actor->isMember() || $achievement->gdoVar('ba_badge') !== $badge->getID() || !$actor->getID())
        {
            throw new RuntimeException('Permission denied');
        }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin deletion transaction');
        }
        try
        {
            $badgeId = GDO::quoteS($badge->getID());
            $badgeRow = $db->queryRead("SELECT bb_owner FROM gdo_badger_badge WHERE bb_id={$badgeId} FOR UPDATE")->fetch_assoc();
            $id = GDO::quoteS($achievement->getID());
            $row = $db->queryRead("SELECT ba_badge, ba_user, ba_deletion FROM gdo_badger_achieve WHERE ba_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$badgeRow || !$row || $row['ba_badge'] !== $badge->getID() || $row['ba_user'] !== $achievement->gdoVar('ba_user') || $row['ba_deletion'] !== $achievement->gdoVar('ba_deletion'))
            {
                throw new RuntimeException('Achievement changed concurrently');
            }
            if ($byRecipient)
            {
                $allowed = $row['ba_user'] === $actor->getID();
            }
            else
            {
                $actorId = GDO::quoteS($actor->getID());
                $staff = $db->queryRead("SELECT ba_is_staff FROM gdo_badger_achieve WHERE ba_badge={$badgeId} AND ba_user={$actorId} FOR UPDATE")->fetch_assoc();
                $allowed = $badgeRow['bb_owner'] === $actor->getID() || $actor->isStaff() || $actor->isAdmin() || ($staff && $staff['ba_is_staff'] === '1');
            }
            if (!$allowed)
            {
                throw new RuntimeException('Permission denied');
            }
            $from = $row['ba_deletion'];
            $to = AchievementLifecycle::deletionState($from, $byRecipient);
            if ($from !== $to)
            {
                $query = $achievement->updateQuery()->set('ba_deletion', GDO::quoteS($to))->where('ba_deletion=' . GDO::quoteS($from));
                if (!$query->exec() || $db->affectedRows() !== 1)
                {
                    throw new RuntimeException('Achievement changed concurrently');
                }
            }
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit deletion transaction');
            }
        }
        catch (Throwable $ex)
        {
            if ($ownsTransaction)
            {
                $db->transactionRollback();
            }
            throw $ex;
        }
        $achievement->uncache();
    }
}
