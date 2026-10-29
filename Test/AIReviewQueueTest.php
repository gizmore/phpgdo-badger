<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\AIReviewQueue;
use PHPUnit\Framework\TestCase;

final class AIReviewQueueTest extends TestCase
{
    public function testFailuresAdvanceCursorAndSkippedSnapshotsDoNotUseBudget(): void
    {
        $cursor = '0';
        $attempted = [];
        $errors = 0;
        $attempt = static function (string $id) use (&$attempted): bool {
            $attempted[] = $id;
            if ($id === '0') { return false; }
            throw new \RuntimeException('simulated provider error');
        };
        $save = static function (string $id) use (&$cursor): void { $cursor = $id; };
        $error = static function () use (&$errors): void { $errors++; };
        self::assertSame(10, AIReviewQueue::process(range(0, 12), 10, $attempt, $save, $error));
        self::assertSame('10', $cursor);
        self::assertSame(10, $errors);
        self::assertNotContains('11', $attempted);
        $attempted = [];
        self::assertSame(2, AIReviewQueue::process([11, 12], 10, $attempt, $save, $error));
        self::assertSame(['11', '12'], $attempted);
        self::assertSame('12', $cursor);
    }
}
