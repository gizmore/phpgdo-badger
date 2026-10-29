<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\Service\AIReviewProviderFactory;
use GDO\Badger\Service\AIReviewRunner;
use GDO\Badger\Service\AIReviewQueue;
use GDO\Badger\Module_Badger;
use GDO\Cronjob\MethodCronjob;
use GDO\DB\Database;

final class RunAIReviews extends MethodCronjob
{
    public function run(): void
    {
        $module = Module_Badger::instance();
        $provider = AIReviewProviderFactory::create((string) $module->getConfigVar('bb_ai_provider_class'));
        if (!$provider) { return; }
        $directory = (string) $module->getConfigVar('bb_evidence_private_dir');
        $cursor = (int) $module->getConfigVar('bb_ai_worker_cursor');
        $rows = Database::instance()->queryRead("SELECT a.ba_id FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge WHERE a.ba_status='pending' AND ((b.bb_verify_ai IS NOT NULL AND b.bb_verify_ai <> '') OR (b.bb_verify_ai_doc IS NOT NULL AND b.bb_verify_ai_doc <> '')) ORDER BY " . AIReviewQueue::orderBy($cursor));
        $ids = (static function () use ($rows): \Generator
        {
            while ($row = $rows->fetch_assoc()) { yield $row['ba_id']; }
        })();
        AIReviewQueue::process($ids, 10,
            static fn (string $id): bool => AIReviewRunner::run($provider, $id, $directory),
            static fn (string $id) => $module->saveConfigVar('bb_ai_worker_cursor', $id),
            fn () => $this->logWarning('AL review failed for an achievement; it remains pending.'));
    }
}
