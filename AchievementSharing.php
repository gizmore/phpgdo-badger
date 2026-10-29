<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\User\GDO_User;
use GDO\User\GDT_ACLRelation;
use RuntimeException;
use Throwable;

final class AchievementSharing
{
    public static function set(GDO_User $actor, GDO_Badge $badge, GDO_Achieve $achievement, string $visibility, bool $ownsTransaction = true): void
    {
        if (!$actor->isMember() || !$actor->getID() || !$badge->getID() || !$achievement->getID() ||
            !in_array($visibility, GDT_ACLRelation::make('visibility')->enumValues, true))
        {
            throw new RuntimeException('Invalid sharing request');
        }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin sharing transaction');
        }
        try
        {
            $badgeId = GDO::quoteS($badge->getID());
            $badgeRow = $db->queryRead("SELECT bb_id FROM gdo_badger_badge WHERE bb_id={$badgeId} FOR UPDATE")->fetch_assoc();
            $id = GDO::quoteS($achievement->getID());
            $row = $db->queryRead("SELECT ba_badge, ba_user FROM gdo_badger_achieve WHERE ba_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$badgeRow || !$row || $row['ba_badge'] !== $badge->getID() || $row['ba_user'] !== $achievement->gdoVar('ba_user'))
            {
                throw new RuntimeException('Achievement changed concurrently');
            }
            if ($row['ba_user'] !== $actor->getID() && !$actor->isStaff() && !$actor->isAdmin())
            {
                throw new RuntimeException('Permission denied');
            }
            $achievement->updateQuery()->set('ba_visibility', GDO::quoteS($visibility))->exec();
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit sharing transaction');
            }
            $achievement->uncache();
        }
        catch (Throwable $ex)
        {
            if ($ownsTransaction)
            {
                $db->transactionRollback();
            }
            throw $ex;
        }
    }
}
