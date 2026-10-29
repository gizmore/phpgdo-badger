<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class EvidenceApproval
{
    /** Call with the achievement row locked. */
    public static function approved(string $achievementId, int $round): bool
    {
        $id = GDO::quoteS($achievementId);
        return (bool) Database::instance()->queryRead("SELECT bat_id FROM gdo_badger_attest WHERE bat_achieve={$id} AND bat_round={$round} AND bat_actor_type='staff' AND bat_decision='document_approve' FOR UPDATE")->fetch_assoc();
    }

    public static function approve(GDO_User $actor, GDO_Achieve $target, string $directory, bool $ownsTransaction = true): void
    {
        if (!$actor->isMember() || !$actor->getID() || !$target->getID()) { throw new RuntimeException('Permission denied'); }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin()) { throw new RuntimeException('Could not begin document approval'); }
        try
        {
            $bid = GDO::quoteS($target->gdoVar('ba_badge'));
            $badge = $db->queryRead("SELECT bb_owner, bb_verify_doc, bb_verify_staff, bb_verify_cc, bb_verify_ai, bb_verify_ai_doc, bb_staff_approvals_needed, bb_community_approvals_needed FROM gdo_badger_badge WHERE bb_id={$bid} FOR UPDATE")->fetch_assoc();
            $id = GDO::quoteS($target->getID());
            $row = $db->queryRead("SELECT ba_badge, ba_user, ba_status, ba_round, ba_staff_approvals, ba_community_approvals FROM gdo_badger_achieve WHERE ba_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$badge || !$row || $row['ba_badge'] !== $target->gdoVar('ba_badge') || $row['ba_user'] !== $target->gdoVar('ba_user') ||
                $row['ba_status'] !== 'pending' || (int) $row['ba_round'] !== (int) $target->gdoVar('ba_round') || $badge['bb_verify_doc'] !== '1')
            {
                throw new RuntimeException('Achievement changed concurrently');
            }
            $actorId = GDO::quoteS($actor->getID());
            $staff = $db->queryRead("SELECT ba_is_staff FROM gdo_badger_achieve WHERE ba_badge={$bid} AND ba_user={$actorId} FOR UPDATE")->fetch_assoc();
            if ($badge['bb_owner'] !== $actor->getID() && !$actor->isStaff() && !$actor->isAdmin() && (!$staff || $staff['ba_is_staff'] !== '1'))
            {
                throw new RuntimeException('Permission denied');
            }
            $round = (int) $row['ba_round'];
            if (self::approved($target->getID(), $round)) { throw new RuntimeException('Document already approved'); }
            $evidence = $db->queryRead("SELECT be_id, be_key, be_sha256, be_size FROM gdo_badger_evidence WHERE be_achieve={$id} AND be_round={$round} AND be_deleted=0 FOR UPDATE")->fetch_assoc();
            if (!$evidence) { throw new RuntimeException('Evidence unavailable'); }
            $bytes = EvidenceVault::read($directory, $evidence['be_key']);
            if (strlen($bytes) !== (int) $evidence['be_size'] || !hash_equals($evidence['be_sha256'], hash('sha256', $bytes)))
            {
                throw new RuntimeException('Evidence unavailable');
            }
            GDO_Attest::blank(['bat_achieve' => $target->getID(), 'bat_actor_type' => 'staff', 'bat_actor' => $actor->getID(),
                'bat_decision' => 'document_approve', 'bat_round' => (string) $round,
                'bat_reason' => 'evidence:' . $evidence['be_id'] . ' sha256:' . $evidence['be_sha256']])->insert();
            if (($badge['bb_verify_staff'] !== '1' || (int) $row['ba_staff_approvals'] >= (int) $badge['bb_staff_approvals_needed']) &&
                ($badge['bb_verify_cc'] !== '1' || (int) $row['ba_community_approvals'] >= (int) $badge['bb_community_approvals_needed']) &&
                ((!$badge['bb_verify_ai'] && !$badge['bb_verify_ai_doc']) || AIReviewRecommendation::approved($target->getID(), $round)))
            {
                AchievementTransition::apply($target, 'verified', 'staff', $actor, null, false);
            }
            if ($ownsTransaction && !$db->transactionEnd()) { throw new RuntimeException('Could not commit document approval'); }
            $target->uncache();
        }
        catch (Throwable $ex)
        {
            if ($ownsTransaction) { $db->transactionRollback(); }
            throw $ex;
        }
    }
}
