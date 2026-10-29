<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\User\GDO_User;
use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\DB\Result;
use RuntimeException;
use Throwable;

final class AchievementActions
{
    public static function approve(GDO_User $actor, GDO_Badge $badge, GDO_Achieve $achievement, bool $ownsTransaction = true): void
    {
        if (!$actor->isMember() || $badge->getID() === null || $achievement->gdoVar('ba_badge') !== $badge->getID() || $actor->getID() === null)
        {
            throw new RuntimeException('Permission denied');
        }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin approval transaction');
        }
        try
        {
            $badgeId = GDO::quoteS($badge->getID());
            $badgeResult = new Result(GDO_Badge::table(), $db->queryRead("SELECT bb_id, bb_owner, bb_verify_staff, bb_verify_cc, bb_verify_doc, bb_verify_ai, bb_verify_ai_doc, bb_approve_ai, bb_staff_approvals_needed, bb_community_approvals_needed FROM gdo_badger_badge WHERE bb_id={$badgeId} FOR UPDATE"), false);
            $badgeRow = $badgeResult->fetchAssoc();
            if (!$badgeRow)
            {
                throw new RuntimeException('Badge not found');
            }
            $badge = GDO_Badge::blank($badgeRow);
            $actorId = GDO::quoteS($actor->getID());
            if ($badge->gdoVar('bb_owner') !== $actor->getID() && !$actor->isStaff() && !$actor->isAdmin())
            {
                $staff = $db->queryRead("SELECT ba_is_staff FROM gdo_badger_achieve WHERE ba_badge={$badgeId} AND ba_user={$actorId} FOR UPDATE")->fetch_assoc();
                if (!$staff || $staff['ba_is_staff'] !== '1')
                {
                    throw new RuntimeException('Permission denied');
                }
            }
            if ($badge->gdoVar('bb_verify_staff') !== '1')
            {
                throw new RuntimeException('Staff verification is not enabled');
            }
            $id = GDO::quoteS($achievement->getID());
            $result = new Result(GDO_Achieve::table(), $db->queryRead("SELECT ba_badge, ba_user, ba_status, ba_round, ba_community_approvals FROM gdo_badger_achieve WHERE ba_id={$id} FOR UPDATE"), false);
            $row = $result->fetchAssoc();
            if (!$row || $row['ba_badge'] !== $badge->getID() || $row['ba_user'] !== $achievement->gdoVar('ba_user'))
            {
                throw new RuntimeException('Achievement changed concurrently');
            }
            if ($row['ba_status'] !== 'pending')
            {
                throw new RuntimeException('Achievement is not pending');
            }
            $round = (int)$row['ba_round'];
            if ((int)$achievement->gdoVar('ba_round') !== $round)
            {
                throw new RuntimeException('Achievement changed concurrently');
            }
            $where = "bat_achieve={$id} AND bat_round={$round} AND bat_actor_type='staff' AND bat_decision='approve'";
            $otherVote = $db->queryRead("SELECT bat_id FROM gdo_badger_attest WHERE bat_achieve={$id} AND bat_round={$round} AND bat_actor_type='community' AND bat_decision='approve' AND bat_actor={$actorId} FOR UPDATE")->fetch_assoc();
            if ($otherVote)
            {
                throw new RuntimeException('Actor already approved in another role');
            }
            $votes = $db->queryRead("SELECT bat_actor FROM gdo_badger_attest WHERE {$where} FOR UPDATE");
            $count = 0;
            while ($vote = $votes->fetch_assoc())
            {
                if ($vote['bat_actor'] === $actor->getID())
                {
                    throw new RuntimeException('Staff member already approved this round');
                }
                $count++;
            }
            GDO_Attest::blank([
                'bat_achieve' => $achievement->getID(), 'bat_actor_type' => 'staff',
                'bat_actor' => $actor->getID(), 'bat_decision' => 'approve', 'bat_round' => (string)$round,
            ])->insert();
            $count++;
            $achievement->updateQuery()->set('ba_staff_approvals', (string)$count)->exec();
            if ($count >= (int)$badge->gdoVar('bb_staff_approvals_needed') &&
                ($badge->gdoVar('bb_verify_cc') !== '1' || (int) $row['ba_community_approvals'] >= (int) $badge->gdoVar('bb_community_approvals_needed')) &&
                ($badge->gdoVar('bb_verify_doc') !== '1' || EvidenceApproval::approved($achievement->getID(), $round)) &&
                ((!$badge->gdoVar('bb_verify_ai') && !$badge->gdoVar('bb_verify_ai_doc')) || AIReviewRecommendation::approved($achievement->getID(), $round)))
            {
                $achievement->setVar('ba_round', (string)$round);
                AchievementTransition::apply($achievement, 'verified', 'staff', $actor, null, false);
            }
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit approval transaction');
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
        $achievement->uncache();
    }

    public static function transition(GDO_User $actor, GDO_Badge $badge, GDO_Achieve $achievement, string $to, ?string $reason = null, bool $ownsTransaction = true, bool $aiConsent = false, ?string $aiConsentFingerprint = null): void
    {
        $action = match ($to) {
            'draft', 'pending' => 'submit',
            'withdrawn' => 'withdraw',
            'claimed' => 'claim',
            'rejected' => 'reject',
            'revoked' => 'revoke',
            'verified' => 'revoke',
            default => throw new RuntimeException('Transition requires a verification workflow'),
        };
        if (!$actor->isMember() || !$actor->getID() || !$badge->getID() || !$achievement->getID() || $achievement->gdoVar('ba_badge') !== $badge->getID())
        {
            throw new RuntimeException('Permission denied');
        }
        if ($to === 'verified' && ($achievement->gdoVar('ba_status') !== 'revoked' || !trim($reason ?? '')))
        {
            throw new RuntimeException('Staff correction requires a reason');
        }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin achievement transaction');
        }
        try
        {
            $badgeId = GDO::quoteS($badge->getID());
            $badgeRow = $db->queryRead("SELECT bb_owner, bb_verify_staff, bb_verify_cc, bb_verify_doc, bb_verify_ai, bb_verify_ai_doc FROM gdo_badger_badge WHERE bb_id={$badgeId} FOR UPDATE")->fetch_assoc();
            $achievementId = GDO::quoteS($achievement->getID());
            $row = $db->queryRead("SELECT ba_badge, ba_user FROM gdo_badger_achieve WHERE ba_id={$achievementId} FOR UPDATE")->fetch_assoc();
            if (!$badgeRow || !$row || $row['ba_badge'] !== $badge->getID() || $row['ba_user'] !== $achievement->gdoVar('ba_user'))
            {
                throw new RuntimeException('Achievement changed concurrently');
            }
            $staff = false;
            if (in_array($action, ['reject', 'revoke'], true))
            {
                $actorId = GDO::quoteS($actor->getID());
                $staffRow = $db->queryRead("SELECT ba_is_staff FROM gdo_badger_achieve WHERE ba_badge={$badgeId} AND ba_user={$actorId} FOR UPDATE")->fetch_assoc();
                $staff = $staffRow && $staffRow['ba_is_staff'] === '1';
            }
            if (!BadgerPermissions::allows($action, $row['ba_user'] === $actor->getID(), $staff,
                $badgeRow['bb_owner'] === $actor->getID(), $actor->isStaff() || $actor->isAdmin()))
            {
                throw new RuntimeException('Permission denied');
            }
            if ($to === 'pending' && $badgeRow['bb_verify_doc'] === '1')
            {
                $round = (int) $achievement->gdoVar('ba_round') + 1;
                $evidence = $db->queryRead("SELECT be_id FROM gdo_badger_evidence WHERE be_achieve={$achievementId} AND be_round={$round} AND be_deleted=0 FOR UPDATE")->fetch_assoc();
                if (!$evidence)
                {
                    throw new RuntimeException('Evidence required');
                }
            }
            $aiConfiguration = null;
            if ($to === 'pending')
            {
                $aiConfiguration = AIConsent::configuration();
                if (($badgeRow['bb_verify_ai'] || $badgeRow['bb_verify_ai_doc']) && (!$aiConsent || $row['ba_user'] !== $actor->getID() ||
                    !$aiConsentFingerprint || !hash_equals(AIConsent::fingerprint($aiConfiguration), $aiConsentFingerprint)))
                {
                    throw new RuntimeException('AI consent required');
                }
                BadgeRateLimit::check($actor, 'claim', (int) Module_Badger::instance()->getConfigVar('bb_claims_per_hour'), time());
            }
            $actorType = in_array($action, ['reject', 'revoke'], true) ? 'staff' : 'system';
            $metadata = $to === 'pending' && $aiConsent ? json_encode([
                'ai_consent' => true,
                'ai_provider_class' => $aiConfiguration['class'],
                'ai_provider_model' => $aiConfiguration['model'],
            ], JSON_THROW_ON_ERROR) : null;
            AchievementTransition::apply($achievement, $to, $actorType, $actor, $reason, false, $metadata);
            if ($to === 'pending' && $badgeRow['bb_verify_staff'] !== '1' && $badgeRow['bb_verify_cc'] !== '1' &&
                $badgeRow['bb_verify_doc'] !== '1' && !$badgeRow['bb_verify_ai'] && !$badgeRow['bb_verify_ai_doc'])
            {
                $achievement->setVar('ba_status', 'pending')->setVar('ba_round', (string) ((int) $achievement->gdoVar('ba_round') + 1));
                AchievementTransition::apply($achievement, 'verified', 'system', null, null, false);
            }
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit achievement transaction');
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
