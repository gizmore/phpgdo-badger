<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\DB\Result;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class AchievementRequests
{
    public static function start(GDO_User $user, GDO_Badge $badge, bool $ownsTransaction = true): GDO_Achieve
    {
        if (!$user->isMember() || !$user->getID() || !$badge->getID())
        {
            throw new RuntimeException('Cannot start this achievement');
        }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin achievement transaction');
        }
        try
        {
            $id = GDO::quoteS($badge->getID());
            $row = $db->queryRead("SELECT bb_id, bb_owner, bb_active, bb_visibility FROM gdo_badger_badge WHERE bb_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$row || $row['bb_active'] !== '1')
            {
                throw new RuntimeException('Cannot start this achievement');
            }
            $currentBadge = GDO_Badge::blank($row);
            $owner = GDO_User::blank(['user_id' => $row['bb_owner']]);
            if (!BadgerPermissions::canViewBadge($user, $owner, $currentBadge, false))
            {
                $uid = GDO::quoteS($user->getID());
                $staff = $db->queryRead("SELECT ba_is_staff FROM gdo_badger_achieve WHERE ba_badge={$id} AND ba_user={$uid} FOR UPDATE")->fetch_assoc();
                if (!$staff || $staff['ba_is_staff'] !== '1')
                {
                    throw new RuntimeException('Cannot start this achievement');
                }
            }
            $where = 'ba_badge=' . $id . ' AND ba_user=' . GDO::quoteS($user->getID());
            $result = new Result(GDO_Achieve::table(), $db->queryRead("SELECT * FROM gdo_badger_achieve WHERE {$where} FOR UPDATE"), false);
            $existing = $result->fetchObject();
            if ($existing)
            {
                if ($ownsTransaction && !$db->transactionEnd())
                {
                    throw new RuntimeException('Could not commit achievement transaction');
                }
                return $existing;
            }
            $achievement = GDO_Achieve::blank([
                'ba_badge' => $badge->getID(),
                'ba_user' => $user->getID(),
                'ba_status' => 'viewed',
                'ba_visibility' => Module_Badger::instance()->userSettingVar($user, 'bb_default_achieve_visible'),
            ])->insert();
            GDO_Attest::blank([
                'bat_achieve' => $achievement->getID(),
                'bat_actor_type' => 'system',
                'bat_actor' => $user->getID(),
                'bat_decision' => 'viewed',
                'bat_to_status' => 'viewed',
            ])->insert();
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit achievement transaction');
            }
            return $achievement;
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
