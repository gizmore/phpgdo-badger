<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class EvidenceAttachments
{
    public static function attach(GDO_User $actor, GDO_Badge $badge, GDO_Achieve $target, string $directory, string $bytes, int $maxBytes, array $mimes, ?callable $scanner): GDO_Evidence
    {
        if (!$actor->isMember() || !$actor->getID() || !$badge->getID() || !$target->getID() || $target->gdoVar('ba_badge') !== $badge->getID())
        {
            throw new RuntimeException('Permission denied');
        }
        $stored = EvidenceVault::store($directory, $bytes, $maxBytes, $mimes, $scanner);
        $committed = false;
        $db = Database::instance();
        try
        {
            if (!$db->transactionBegin())
            {
                throw new RuntimeException('Could not begin evidence transaction');
            }
            $bid = GDO::quoteS($badge->getID());
            $badgeRow = $db->queryRead("SELECT bb_verify_doc FROM gdo_badger_badge WHERE bb_id={$bid} FOR UPDATE")->fetch_assoc();
            $id = GDO::quoteS($target->getID());
            $row = $db->queryRead("SELECT ba_badge, ba_user, ba_status, ba_round FROM gdo_badger_achieve WHERE ba_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$badgeRow || $badgeRow['bb_verify_doc'] !== '1' || !$row || $row['ba_badge'] !== $badge->getID() ||
                $row['ba_user'] !== $actor->getID() || $row['ba_user'] !== $target->gdoVar('ba_user') ||
                !in_array($row['ba_status'], ['viewed', 'draft', 'rejected', 'withdrawn'], true))
            {
                throw new RuntimeException('Cannot attach evidence');
            }
            $round = (int) $row['ba_round'] + 1;
            $existing = $db->queryRead("SELECT be_id FROM gdo_badger_evidence WHERE be_achieve={$id} AND be_round={$round} AND be_deleted=0 FOR UPDATE")->fetch_assoc();
            if ($existing)
            {
                throw new RuntimeException('Evidence already attached for this round');
            }
            $evidence = GDO_Evidence::blank([
                'be_achieve' => $target->getID(), 'be_round' => (string) $round,
                'be_key' => $stored['key'], 'be_sha256' => $stored['sha256'],
                'be_mime' => $stored['mime'], 'be_size' => (string) $stored['size'],
            ])->insert();
            if (!$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit evidence transaction');
            }
            $committed = true;
            return $evidence;
        }
        catch (Throwable $ex)
        {
            if (!$committed)
            {
                $db->transactionRollback();
                EvidenceVault::remove($directory, $stored['key']);
            }
            throw $ex;
        }
    }
}
