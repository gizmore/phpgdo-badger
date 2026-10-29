<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDO_Badge;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;

final class EvidenceAccess
{
    public static function hasCurrentEvidence(GDO_Achieve $achievement): bool
    {
        if (!$achievement->getID()) { return false; }
        $id = GDO::quoteS($achievement->getID());
        return (bool) Database::instance()->queryRead("SELECT e.be_id FROM gdo_badger_achieve a JOIN gdo_badger_evidence e ON e.be_achieve=a.ba_id AND e.be_round=a.ba_round AND e.be_deleted=0 WHERE a.ba_id={$id} AND a.ba_status IN ('pending', 'rejected') LIMIT 1")->fetch_assoc();
    }

    /** Return private content only after checking the live request and reviewer role. */
    public static function read(GDO_User $actor, string $achievementId, string $directory): string
    {
        if (!$actor->isMember() || !$actor->getID() || !ctype_digit($achievementId)) { throw new RuntimeException('Evidence unavailable'); }
        $id = GDO::quoteS($achievementId);
        $row = Database::instance()->queryRead("SELECT a.ba_user, a.ba_status, a.ba_badge, b.bb_owner, e.be_key, e.be_sha256, e.be_size FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge JOIN gdo_badger_evidence e ON e.be_achieve=a.ba_id AND e.be_round=a.ba_round AND e.be_deleted=0 WHERE a.ba_id={$id}")->fetch_assoc();
        if (!$row || !in_array($row['ba_status'], ['pending', 'rejected'], true)) { throw new RuntimeException('Evidence unavailable'); }
        $badge = GDO_Badge::blank(['bb_id' => $row['ba_badge'], 'bb_owner' => $row['bb_owner']]);
        if ($row['ba_user'] !== $actor->getID() && !BadgerPermissions::canEditBadge($actor, $badge))
        {
            throw new RuntimeException('Evidence unavailable');
        }
        $bytes = EvidenceVault::read($directory, $row['be_key']);
        if (strlen($bytes) !== (int) $row['be_size'] || !hash_equals($row['be_sha256'], hash('sha256', $bytes)))
        {
            throw new RuntimeException('Evidence unavailable');
        }
        return $bytes;
    }
}
