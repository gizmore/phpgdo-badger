<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\Service\ReviewNotices;
use GDO\Cronjob\MethodCronjob;

final class DeliverReviewNotices extends MethodCronjob
{
    public function run(): void
    {
        $count = ReviewNotices::deliver(100, null, fn () => $this->logWarning('Badger review notice delivery failed; it remains pending.'));
        if ($count) { $this->log("Delivered {$count} Badger review notices."); }
    }
}
