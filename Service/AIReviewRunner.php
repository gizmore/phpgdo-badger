<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Core\GDO;
use GDO\DB\Database;
use RuntimeException;

final class AIReviewRunner
{
    public static function run(AIReviewProvider $provider, string $achievementId, string $directory): bool
    {
        $input = AIReviewInput::capture($achievementId, $directory);
        $id = GDO::quoteS($achievementId);
        $round = (int) $input['request']['round'];
        $rows = Database::instance()->queryRead("SELECT bat_metadata FROM gdo_badger_attest WHERE bat_achieve={$id} AND bat_round={$round} AND bat_actor_type='ai' AND bat_decision LIKE 'recommendation_%'");
        while ($row = $rows->fetch_assoc())
        {
            $metadata = json_decode($row['bat_metadata'] ?? '', true);
            if (is_array($metadata) && ($metadata['snapshot_sha256'] ?? null) === $input['fingerprint'] && ($metadata['provider'] ?? null) === $provider->name())
            {
                return false;
            }
        }
        $allowed = array_flip(['name', 'rubric', 'prompt', 'document_prompt', 'message', 'document_mime', 'provider_class', 'provider_model']);
        $providerRequest = array_intersect_key($input['request'], $allowed);
        $result = $provider->review($providerRequest, $input['document']);
        if (!is_string($result['model'] ?? null) || !is_string($result['decision'] ?? null) || !is_string($result['rationale'] ?? null))
        {
            throw new RuntimeException('Invalid AI provider response');
        }
        AIReviewRecommendation::record($achievementId, $directory, $input['fingerprint'], $provider->name(),
            $result['model'], $result['decision'], $result['rationale']);
        return true;
    }
}
