<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class AIReviewHumanApproval
{
    public static function accept(GDO_User $actor, GDO_Achieve $target, string $directory, bool $ownsTransaction = true): void
    {
        if (!$actor->isMember() || !$actor->getID() || !$target->getID() || !$target->gdoVar('ba_badge')) { throw new RuntimeException('Permission denied'); }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin()) { throw new RuntimeException('Could not begin AI acceptance'); }
        try
        {
            $id = GDO::quoteS($target->getID());
            $bid = GDO::quoteS($target->gdoVar('ba_badge'));
            $badge = $db->queryRead("SELECT bb_owner, bb_verify_staff, bb_verify_cc, bb_verify_doc, bb_approve_ai, bb_staff_approvals_needed, bb_community_approvals_needed FROM gdo_badger_badge WHERE bb_id={$bid} FOR UPDATE")->fetch_assoc();
            $row = $db->queryRead("SELECT ba_badge, ba_user, ba_status, ba_round, ba_staff_approvals, ba_community_approvals FROM gdo_badger_achieve WHERE ba_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$badge || !$row || $row['ba_badge'] !== $target->gdoVar('ba_badge') || $row['ba_user'] !== $target->gdoVar('ba_user') ||
                $row['ba_status'] !== 'pending' || (int) $row['ba_round'] !== (int) $target->gdoVar('ba_round') || $badge['bb_approve_ai'] === '1')
            {
                throw new RuntimeException('AI review unavailable');
            }
            $uid = GDO::quoteS($actor->getID());
            $staff = $db->queryRead("SELECT ba_is_staff FROM gdo_badger_achieve WHERE ba_badge={$bid} AND ba_user={$uid} FOR UPDATE")->fetch_assoc();
            if ($badge['bb_owner'] !== $actor->getID() && !$actor->isStaff() && !$actor->isAdmin() && (!$staff || $staff['ba_is_staff'] !== '1'))
            {
                throw new RuntimeException('Permission denied');
            }
            $input = AIReviewInput::capture($target->getID(), $directory, true);
            $round = (int) $row['ba_round'];
            $found = false;
            $reviews = $db->queryRead("SELECT bat_decision, bat_metadata FROM gdo_badger_attest WHERE bat_achieve={$id} AND bat_round={$round} AND ((bat_actor_type='ai' AND bat_decision='recommendation_approve') OR (bat_actor_type='staff' AND bat_decision='accept_ai')) FOR UPDATE");
            while ($review = $reviews->fetch_assoc())
            {
                $meta = json_decode($review['bat_metadata'] ?? '', true);
                if (is_array($meta) && ($meta['snapshot_sha256'] ?? null) === $input['fingerprint'])
                {
                    if ($review['bat_decision'] === 'accept_ai') { throw new RuntimeException('AI review already accepted'); }
                    $found = true;
                }
            }
            if (!$found) { throw new RuntimeException('AI recommendation unavailable'); }
            GDO_Attest::blank(['bat_achieve' => $target->getID(), 'bat_actor_type' => 'staff', 'bat_actor' => $actor->getID(),
                'bat_decision' => 'accept_ai', 'bat_round' => (string) $round,
                'bat_metadata' => json_encode(['snapshot_sha256' => $input['fingerprint']], JSON_THROW_ON_ERROR)])->insert();
            if (($badge['bb_verify_staff'] !== '1' || (int) $row['ba_staff_approvals'] >= (int) $badge['bb_staff_approvals_needed']) &&
                ($badge['bb_verify_cc'] !== '1' || (int) $row['ba_community_approvals'] >= (int) $badge['bb_community_approvals_needed']) &&
                ($badge['bb_verify_doc'] !== '1' || EvidenceApproval::approved($target->getID(), $round)))
            {
                AchievementTransition::apply($target, 'verified', 'staff', $actor, null, false);
            }
            if ($ownsTransaction && !$db->transactionEnd()) { throw new RuntimeException('Could not commit AI acceptance'); }
            $target->uncache();
        }
        catch (Throwable $ex)
        {
            if ($ownsTransaction) { $db->transactionRollback(); }
            throw $ex;
        }
    }
}
