<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDO_Attest;
use GDO\Badger\GDO_Badge;
use GDO\Badger\Module_Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class CommunityApproval
{
    public static function eligible(GDO_User $actor, GDO_Badge $badge, GDO_Achieve $target): bool
    {
        $id = $actor->getID();
        if (!$actor->isMember() || !$id || !$badge->getID() || $id === $target->gdoVar('ba_user') || $id === $badge->gdoVar('bb_owner') ||
            $badge->gdoVar('bb_verify_cc') !== '1' || BadgerPermissions::isBadgeStaff($badge->getID(), $id))
        {
            return false;
        }
        return GDO_Achieve::table()->countWhere('ba_badge=' . GDO::quoteS($badge->getID()) . ' AND ba_user=' . GDO::quoteS($id) . " AND ba_status='claimed'") > 0;
    }

    public static function approve(GDO_User $actor, GDO_Badge $badge, GDO_Achieve $target, bool $ownsTransaction = true): void
    {
        if (!$actor->isMember() || !$actor->getID() || !$badge->getID() || !$target->getID() || $target->gdoVar('ba_badge') !== $badge->getID())
        {
            throw new RuntimeException('Permission denied');
        }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin community approval');
        }
        try
        {
            $bid = GDO::quoteS($badge->getID());
            $badgeRow = $db->queryRead("SELECT bb_id, bb_owner, bb_verify_staff, bb_verify_cc, bb_verify_doc, bb_verify_ai, bb_verify_ai_doc, bb_staff_approvals_needed, bb_community_approvals_needed FROM gdo_badger_badge WHERE bb_id={$bid} FOR UPDATE")->fetch_assoc();
            $id = GDO::quoteS($target->getID());
            $row = $db->queryRead("SELECT ba_id, ba_badge, ba_user, ba_status, ba_round, ba_staff_approvals, ba_deletion, ba_visibility FROM gdo_badger_achieve WHERE ba_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$badgeRow || !$row || $row['ba_badge'] !== $badge->getID() || $row['ba_user'] !== $target->gdoVar('ba_user') ||
                $row['ba_status'] !== 'pending' || (int) $row['ba_round'] !== (int) $target->gdoVar('ba_round'))
            {
                throw new RuntimeException('Achievement changed concurrently');
            }
            $aid = GDO::quoteS($actor->getID());
            $holder = $db->queryRead("SELECT ba_status, ba_is_staff FROM gdo_badger_achieve WHERE ba_badge={$bid} AND ba_user={$aid} FOR UPDATE")->fetch_assoc();
            $currentBadge = GDO_Badge::entity($badgeRow);
            $currentTarget = GDO_Achieve::entity($row);
            $recipient = GDO_User::blank(['user_id' => $row['ba_user']]);
            if ($badgeRow['bb_verify_cc'] !== '1' || !$holder || $holder['ba_status'] !== 'claimed' || $holder['ba_is_staff'] === '1' ||
                $actor->getID() === $row['ba_user'] || $actor->getID() === $badgeRow['bb_owner'] ||
                !BadgerPermissions::canView($actor, $recipient, $currentBadge, $currentTarget, false))
            {
                throw new RuntimeException('Permission denied');
            }
            $round = (int) $row['ba_round'];
            BadgeRateLimit::check($actor, 'community', (int) Module_Badger::instance()->getConfigVar('bb_community_votes_per_hour'), time());
            $otherVote = $db->queryRead("SELECT bat_id FROM gdo_badger_attest WHERE bat_achieve={$id} AND bat_round={$round} AND bat_actor_type='staff' AND bat_decision='approve' AND bat_actor={$aid} FOR UPDATE")->fetch_assoc();
            if ($otherVote)
            {
                throw new RuntimeException('Actor already approved in another role');
            }
            $votes = $db->queryRead("SELECT bat_actor FROM gdo_badger_attest WHERE bat_achieve={$id} AND bat_round={$round} AND bat_actor_type='community' AND bat_decision='approve' FOR UPDATE");
            $count = 0;
            while ($vote = $votes->fetch_assoc())
            {
                if ($vote['bat_actor'] === $actor->getID())
                {
                    throw new RuntimeException('Community member already approved this round');
                }
                $count++;
            }
            GDO_Attest::blank(['bat_achieve' => $target->getID(), 'bat_actor_type' => 'community', 'bat_actor' => $actor->getID(), 'bat_decision' => 'approve', 'bat_round' => (string) $round])->insert();
            $count++;
            $target->updateQuery()->set('ba_community_approvals', (string) $count)->exec();
            if ($count >= (int) $badgeRow['bb_community_approvals_needed'] &&
                ($badgeRow['bb_verify_staff'] !== '1' || (int) $row['ba_staff_approvals'] >= (int) $badgeRow['bb_staff_approvals_needed']) &&
                ($badgeRow['bb_verify_doc'] !== '1' || EvidenceApproval::approved($target->getID(), $round)) &&
                ((!$badgeRow['bb_verify_ai'] && !$badgeRow['bb_verify_ai_doc']) || AIReviewRecommendation::approved($target->getID(), $round)))
            {
                AchievementTransition::apply($target, 'verified', 'community', $actor, null, false);
            }
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit community approval');
            }
            $target->uncache();
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
