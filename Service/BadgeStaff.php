<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDO_Attest;
use GDO\Badger\GDO_Badge;
use GDO\Badger\Module_Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\DB\Result;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class BadgeStaff
{
    public static function set(GDO_User $actor, GDO_Badge $badge, GDO_User $user, bool $enabled, bool $ownsTransaction = true): void
    {
        if (!$actor->isMember() || $actor->getID() === null || $badge->getID() === null || $user->getID() === null)
        {
            throw new RuntimeException('Permission denied');
        }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin staff transaction');
        }
        try
        {
            $id = GDO::quoteS($badge->getID());
            $row = $db->queryRead("SELECT bb_owner FROM gdo_badger_badge WHERE bb_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$row || ($row['bb_owner'] !== $actor->getID() && !$actor->isStaff() && !$actor->isAdmin()))
            {
                throw new RuntimeException('Permission denied');
            }
            if ($enabled)
            {
                $userId = GDO::quoteS($user->getID());
                $target = $db->queryRead("SELECT user_type FROM gdo_user WHERE user_id={$userId} FOR UPDATE")->fetch_assoc();
                if (!$target || $target['user_type'] !== 'member') { throw new RuntimeException('Staff must be a member'); }
            }
            $where = 'ba_badge=' . $id . ' AND ba_user=' . GDO::quoteS($user->getID());
            $result = new Result(GDO_Achieve::table(), $db->queryRead("SELECT * FROM gdo_badger_achieve WHERE {$where} FOR UPDATE"), false);
            $achievement = $result->fetchObject();
            if ($achievement)
            {
                $achievement->saveVar('ba_is_staff', $enabled ? '1' : '0');
            }
            elseif ($enabled)
            {
                $achievement = GDO_Achieve::blank([
                    'ba_badge' => $badge->getID(),
                    'ba_user' => $user->getID(),
                    'ba_status' => 'viewed',
                    'ba_is_staff' => '1',
                    'ba_visibility' => Module_Badger::instance()->userSettingVar($user, 'bb_default_achieve_visible'),
                ])->insert();
                GDO_Attest::blank([
                    'bat_achieve' => $achievement->getID(),
                    'bat_actor_type' => 'staff',
                    'bat_actor' => $actor->getID(),
                    'bat_decision' => 'viewed',
                    'bat_to_status' => 'viewed',
                ])->insert();
            }
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit staff transaction');
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
    }
}
