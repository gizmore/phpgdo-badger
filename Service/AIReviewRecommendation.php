<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDO_Attest;
use GDO\Badger\Module_Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Internal server-side entry point for a configured provider adapter. */
final class AIReviewRecommendation
{
    /** Called inside the badge/achievement transaction. */
    public static function approved(string $achievementId, int $round): bool
    {
        try
        {
            $directory = (string) Module_Badger::instance()->getConfigVar('bb_evidence_private_dir');
            $current = AIReviewInput::capture($achievementId, $directory, true);
            if ($current['request']['round'] !== $round) { return false; }
            $id = GDO::quoteS($achievementId);
            $rows = Database::instance()->queryRead("SELECT bat_actor_type, bat_decision, bat_metadata FROM gdo_badger_attest WHERE bat_achieve={$id} AND bat_round={$round} AND ((bat_actor_type='ai' AND bat_decision='recommendation_approve') OR (bat_actor_type='staff' AND bat_decision='accept_ai')) FOR UPDATE");
            while ($row = $rows->fetch_assoc())
            {
                $metadata = json_decode($row['bat_metadata'] ?? '', true);
                if (is_array($metadata) && ($metadata['snapshot_sha256'] ?? null) === $current['fingerprint'] &&
                    ($row['bat_decision'] === 'accept_ai' || $current['request']['automatic'] === '1')) { return true; }
            }
            return false;
        }
        catch (RuntimeException $ex) { return false; }
    }

    public static function record(string $achievementId, string $directory, string $fingerprint, string $provider, string $model, string $decision, string $rationale): void
    {
        if (!ctype_digit($achievementId) || !preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) ||
            !in_array($decision, ['approve', 'reject', 'inconclusive'], true) || trim($provider) === '' || trim($model) === '' ||
            strlen($provider) > 100 || strlen($model) > 200 || trim($rationale) === '' || strlen($rationale) > 4000)
        {
            throw new InvalidArgumentException('Invalid AI recommendation');
        }
        $db = Database::instance();
        if (!$db->transactionBegin()) { throw new RuntimeException('Could not begin AI recommendation'); }
        try
        {
            $id = GDO::quoteS($achievementId);
            $lookup = $db->queryRead("SELECT ba_badge FROM gdo_badger_achieve WHERE ba_id={$id}")->fetch_assoc();
            if (!$lookup) { throw new RuntimeException('AI review unavailable'); }
            $bid = GDO::quoteS($lookup['ba_badge']);
            if (!$db->queryRead("SELECT bb_id FROM gdo_badger_badge WHERE bb_id={$bid} FOR UPDATE")->fetch_assoc() ||
                !$db->queryRead("SELECT ba_id FROM gdo_badger_achieve WHERE ba_id={$id} AND ba_badge={$bid} FOR UPDATE")->fetch_assoc())
            {
                throw new RuntimeException('AI review unavailable');
            }
            $current = AIReviewInput::capture($achievementId, $directory, true);
            if (!hash_equals($current['fingerprint'], $fingerprint)) { throw new RuntimeException('AI review changed concurrently'); }
            $request = $current['request'];
            $round = (int) $request['round'];
            $dec = GDO::quoteS('recommendation_' . $decision);
            $previous = $db->queryRead("SELECT bat_metadata FROM gdo_badger_attest WHERE bat_achieve={$id} AND bat_round={$round} AND bat_actor_type='ai' AND bat_decision={$dec} FOR UPDATE");
            while ($row = $previous->fetch_assoc())
            {
                $existing = json_decode($row['bat_metadata'] ?? '', true);
                if (is_array($existing) && ($existing['snapshot_sha256'] ?? null) === $fingerprint &&
                    ($existing['provider'] ?? null) === $provider && ($existing['model'] ?? null) === $model)
                {
                    if (!$db->transactionEnd()) { throw new RuntimeException('Could not commit AI recommendation'); }
                    return;
                }
            }
            $metadata = json_encode([
                'provider' => $provider, 'model' => $model, 'result' => $decision, 'snapshot_sha256' => $fingerprint,
                'prompt_sha256' => hash('sha256', (string) $request['prompt']),
                'document_prompt_sha256' => hash('sha256', (string) $request['document_prompt']),
                'document_sha256' => $request['document_sha256'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $attestation = GDO_Attest::blank(['bat_achieve' => $achievementId, 'bat_actor_type' => 'ai', 'bat_decision' => 'recommendation_' . $decision,
                'bat_round' => (string) $request['round'], 'bat_reason' => $rationale, 'bat_metadata' => $metadata])->insert();
            if ($decision === 'reject' && $request['automatic'] === '1')
            {
                $target = GDO_Achieve::blank(['ba_id' => $achievementId, 'ba_badge' => $lookup['ba_badge'], 'ba_user' => $request['recipient'],
                    'ba_status' => 'pending', 'ba_round' => (string) $round]);
                AchievementTransition::apply($target, 'rejected', 'ai', null, $rationale, false);
                ReviewNotices::enqueueRejection($attestation->getID(), $lookup['ba_badge']);
            }
            if ($decision === 'approve' && $request['automatic'] === '1')
            {
                $state = $db->queryRead("SELECT a.ba_user, a.ba_status, a.ba_round, a.ba_staff_approvals, a.ba_community_approvals, b.bb_verify_staff, b.bb_verify_cc, b.bb_verify_doc, b.bb_staff_approvals_needed, b.bb_community_approvals_needed FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge WHERE a.ba_id={$id} FOR UPDATE")->fetch_assoc();
                if ($state && ($state['bb_verify_staff'] !== '1' || (int) $state['ba_staff_approvals'] >= (int) $state['bb_staff_approvals_needed']) &&
                    ($state['bb_verify_cc'] !== '1' || (int) $state['ba_community_approvals'] >= (int) $state['bb_community_approvals_needed']) &&
                    ($state['bb_verify_doc'] !== '1' || EvidenceApproval::approved($achievementId, $round)))
                {
                    $target = GDO_Achieve::blank(['ba_id' => $achievementId, 'ba_badge' => $lookup['ba_badge'], 'ba_user' => $state['ba_user'],
                        'ba_status' => $state['ba_status'], 'ba_round' => (string) $round]);
                    AchievementTransition::apply($target, 'verified', 'ai', null, null, false);
                }
            }
            if (!$db->transactionEnd()) { throw new RuntimeException('Could not commit AI recommendation'); }
        }
        catch (Throwable $ex)
        {
            $db->transactionRollback();
            throw $ex;
        }
    }
}
