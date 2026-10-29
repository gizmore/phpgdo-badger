<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Badger\GDO_ReviewNotice;
use GDO\Badger\Module_Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\PM\GDO_PM;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class ReviewNotices
{
    /** Caller holds the badge lock and owns the surrounding transaction. */
    public static function enqueueRejection(string $attestationId, string $badgeId): void
    {
        $id = GDO::quoteS($badgeId);
        $db = Database::instance();
        $badge = $db->queryRead("SELECT bb_owner FROM gdo_badger_badge WHERE bb_id={$id} FOR UPDATE")->fetch_assoc();
        if (!$badge || !$badge['bb_owner']) { throw new RuntimeException('Badge owner unavailable'); }
        $recipients = [$badge['bb_owner'] => true];
        $staff = $db->queryRead("SELECT ba_user FROM gdo_badger_achieve WHERE ba_badge={$id} AND ba_is_staff=1 FOR UPDATE");
        while ($row = $staff->fetch_assoc()) { if ($row['ba_user']) { $recipients[$row['ba_user']] = true; } }
        foreach (array_keys($recipients) as $recipient)
        {
            GDO_ReviewNotice::blank(['bn_attest' => $attestationId, 'bn_user' => (string) $recipient])->insert();
        }
    }

    /** Sender is injectable for tests; it must write only within this transaction. */
    public static function deliver(int $limit = 100, ?callable $sender = null, ?callable $onError = null): int
    {
        if ($limit < 1 || $limit > 1000) { throw new RuntimeException('Invalid notice limit'); }
        $db = Database::instance();
        $module = Module_Badger::instance();
        $cursor = (string) $module->getConfigVar('bb_notice_cursor');
        if (!preg_match('/\\A([0-9]{1,20}):([0-9]{1,20})\\z/D', $cursor, $parts)) { $parts = ['', '0', '0']; }
        $attestCursor = GDO::quoteS($parts[1]);
        $userCursor = GDO::quoteS($parts[2]);
        $rows = $db->queryRead("SELECT bn_attest, bn_user FROM gdo_badger_review_notice WHERE bn_delivered=0 ORDER BY CASE WHEN bn_attest > {$attestCursor} OR (bn_attest = {$attestCursor} AND bn_user > {$userCursor}) THEN 0 ELSE 1 END, bn_attest, bn_user LIMIT {$limit}");
        $pending = [];
        while ($row = $rows->fetch_assoc()) { $pending[] = $row; }
        $count = 0;
        foreach ($pending as $notice)
        {
            $sent = false;
            if (!$db->transactionBegin()) { throw new RuntimeException('Could not begin notice delivery'); }
            try
            {
                $attestId = GDO::quoteS($notice['bn_attest']);
                $userId = GDO::quoteS($notice['bn_user']);
                $row = $db->queryRead("SELECT n.bn_user, a.bat_achieve, x.ba_badge FROM gdo_badger_review_notice n JOIN gdo_badger_attest a ON a.bat_id=n.bn_attest JOIN gdo_badger_achieve x ON x.ba_id=a.bat_achieve WHERE n.bn_attest={$attestId} AND n.bn_user={$userId} AND n.bn_delivered=0 FOR UPDATE")->fetch_assoc();
                if ($row)
                {
                    $bid = GDO::quoteS($row['ba_badge']);
                    $badge = $db->queryRead("SELECT bb_owner FROM gdo_badger_badge WHERE bb_id={$bid} FOR UPDATE")->fetch_assoc();
                    $staff = $db->queryRead("SELECT ba_is_staff FROM gdo_badger_achieve WHERE ba_badge={$bid} AND ba_user={$userId} FOR UPDATE")->fetch_assoc();
                    $recipientAccount = $db->queryRead("SELECT user_type FROM gdo_user WHERE user_id={$userId} FOR UPDATE")->fetch_assoc();
                    if ($recipientAccount && $recipientAccount['user_type'] === 'member' && $badge &&
                        ($badge['bb_owner'] === $row['bn_user'] || ($staff && $staff['ba_is_staff'] === '1')))
                    {
                        if ($sender)
                        {
                            $sender($row['bn_user'], $row['bat_achieve']);
                        }
                        else
                        {
                            $recipient = GDO_User::findById($row['bn_user']);
                            GDO_PM::deliver(GDO_User::system(), $recipient, t('badger_notice_title'),
                                t('badger_notice_body', [href('Badger', 'Achieve', '&achievement=' . $row['bat_achieve'])]), false);
                        }
                        $sent = true;
                    }
                    if (!$db->queryWrite("UPDATE gdo_badger_review_notice SET bn_delivered=1 WHERE bn_attest={$attestId} AND bn_user={$userId} AND bn_delivered=0"))
                    {
                        throw new RuntimeException('Could not record notice delivery');
                    }
                }
                if (!$db->transactionEnd()) { throw new RuntimeException('Could not commit notice delivery'); }
                if ($sent) { $count++; }
            }
            catch (Throwable $ex)
            {
                $db->transactionRollback();
                if ($onError) { $onError(); }
                else { error_log('Badger review notice delivery failed; it remains pending.'); }
            }
            $module->saveConfigVar('bb_notice_cursor', $notice['bn_attest'] . ':' . $notice['bn_user']);
        }
        return $count;
    }
}
