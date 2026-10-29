<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\EvidenceCleanup;
use GDO\Badger\Module_Badger;
use GDO\Cronjob\MethodCronjob;

final class CleanupEvidence extends MethodCronjob
{
    public function runAt(): string { return $this->runHourly(); }

    public function run(): void
    {
        $module = Module_Badger::instance();
        $directory = (string) $module->getConfigVar('bb_evidence_private_dir');
        if ($directory === '') { return; }
        $count = EvidenceCleanup::run($directory, (int) $module->getConfigVar('bb_rejected_document_delete_after'), time(), 100,
            fn () => $this->logWarning('Badger evidence cleanup failed; it remains eligible for retry.'));
        if ($count) { $this->log("Removed {$count} expired evidence documents."); }
    }
}
