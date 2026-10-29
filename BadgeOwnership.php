<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class BadgeOwnership
{
    public static function transfer(GDO_User $actor, GDO_Badge $badge, GDO_User $recipient, bool $ownsTransaction = true): void
    {
        if (!$actor->isMember() || !$actor->getID() || !$badge->getID() || !$recipient->getID()) { throw new RuntimeException('Permission denied'); }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin()) { throw new RuntimeException('Could not begin ownership transfer'); }
        try
        {
            $id = GDO::quoteS($badge->getID());
            $row = $db->queryRead("SELECT bb_owner FROM gdo_badger_badge WHERE bb_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$row || ($row['bb_owner'] !== $actor->getID() && !$actor->isStaff() && !$actor->isAdmin()))
            {
                throw new RuntimeException('Permission denied');
            }
            $recipientId = GDO::quoteS($recipient->getID());
            $target = $db->queryRead("SELECT user_type FROM gdo_user WHERE user_id={$recipientId} FOR UPDATE")->fetch_assoc();
            if (!$target || $target['user_type'] !== 'member') { throw new RuntimeException('Recipient must be a member'); }
            if ($row['bb_owner'] !== $recipient->getID() && !$db->queryWrite("UPDATE gdo_badger_badge SET bb_owner={$recipientId} WHERE bb_id={$id}"))
            {
                throw new RuntimeException('Could not transfer badge');
            }
            if ($ownsTransaction && !$db->transactionEnd()) { throw new RuntimeException('Could not commit ownership transfer'); }
            $badge->uncache();
        }
        catch (Throwable $ex)
        {
            if ($ownsTransaction) { $db->transactionRollback(); }
            throw $ex;
        }
    }
}
