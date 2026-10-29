<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use RuntimeException;

/** Internal-only data snapshot; capturing it does not call an AL provider. */
final class AIReviewInput
{
    public static function capture(string $achievementId, string $directory, bool $forUpdate = false): array
    {
        if (!ctype_digit($achievementId)) { throw new RuntimeException('Invalid review'); }
        $id = GDO::quoteS($achievementId);
        $db = Database::instance();
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $row = $db->queryRead("SELECT a.ba_id, a.ba_badge, a.ba_user, a.ba_status, a.ba_round, b.bb_name, b.bb_description, b.bb_verify_ai, b.bb_verify_ai_doc, b.bb_approve_ai FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge WHERE a.ba_id={$id}{$lock}")->fetch_assoc();
        if (!$row || $row['ba_status'] !== 'pending' || (!$row['bb_verify_ai'] && !$row['bb_verify_ai_doc']))
        {
            throw new RuntimeException('AI review unavailable');
        }
        $round = (int) $row['ba_round'];
        $submission = $db->queryRead("SELECT bat_reason, bat_metadata FROM gdo_badger_attest WHERE bat_achieve={$id} AND bat_round={$round} AND bat_to_status='pending' ORDER BY bat_id DESC LIMIT 1{$lock}")->fetch_assoc();
        if (!$submission) { throw new RuntimeException('AI review unavailable'); }
        $consent = json_decode($submission['bat_metadata'] ?? '', true);
        if (!is_array($consent) || ($consent['ai_consent'] ?? false) !== true) { throw new RuntimeException('AI consent required'); }
        $providerClass = (string) Module_Badger::instance()->getConfigVar('bb_ai_provider_class');
        $providerModel = (string) Module_Badger::instance()->getConfigVar('bb_ai_ollama_model');
        if (($consent['ai_provider_class'] ?? null) !== $providerClass || ($consent['ai_provider_model'] ?? null) !== $providerModel) { throw new RuntimeException('AI consent required'); }
        $document = null;
        $documentHash = null;
        $documentMime = null;
        if ($row['bb_verify_ai_doc'])
        {
            $evidence = $db->queryRead("SELECT be_key, be_sha256, be_mime, be_size FROM gdo_badger_evidence WHERE be_achieve={$id} AND be_round={$round} AND be_deleted=0{$lock}")->fetch_assoc();
            if (!$evidence) { throw new RuntimeException('AI review unavailable'); }
            $document = EvidenceVault::read($directory, $evidence['be_key']);
            if (strlen($document) !== (int) $evidence['be_size'] || !hash_equals($evidence['be_sha256'], hash('sha256', $document)))
            {
                throw new RuntimeException('AI review unavailable');
            }
            $documentHash = $evidence['be_sha256'];
            $documentMime = $evidence['be_mime'];
        }
        $request = [
            'achievement' => $row['ba_id'], 'badge' => $row['ba_badge'], 'recipient' => $row['ba_user'], 'round' => $round,
            'name' => $row['bb_name'], 'rubric' => $row['bb_description'], 'prompt' => $row['bb_verify_ai'],
            'document_prompt' => $row['bb_verify_ai_doc'], 'automatic' => $row['bb_approve_ai'],
            'provider_class' => $providerClass,
            'provider_model' => $providerModel,
            'message' => $submission['bat_reason'], 'document_sha256' => $documentHash, 'document_mime' => $documentMime,
        ];
        $json = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return ['fingerprint' => hash('sha256', $json), 'request' => $request, 'document' => $document];
    }
}
