<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\Service\AchievementLifecycle;
use PHPUnit\Framework\TestCase;

final class AchievementLifecycleTest extends TestCase
{
    public function testTransitionsAndRewards(): void
    {
        self::assertTrue(AchievementLifecycle::allows('pending', 'verified'));
        self::assertTrue(AchievementLifecycle::allows('verified', 'claimed'));
        self::assertTrue(AchievementLifecycle::allows('claimed', 'revoked'));
        self::assertTrue(AchievementLifecycle::allows('revoked', 'verified'));
        self::assertFalse(AchievementLifecycle::allows('pending', 'claimed'));
        self::assertFalse(AchievementLifecycle::allows('revoked', 'claimed'));
        self::assertFalse(AchievementLifecycle::countsRewards('verified'));
        self::assertTrue(AchievementLifecycle::countsRewards('claimed'));
        self::assertFalse(AchievementLifecycle::countsRewards('revoked'));
    }

    public function testIndependentDeletionStates(): void
    {
        self::assertSame('deleted_user', AchievementLifecycle::deletionState('not_deleted', true));
        self::assertSame('deleted_badger', AchievementLifecycle::deletionState('not_deleted', false));
        self::assertSame('deleted_both', AchievementLifecycle::deletionState('deleted_user', false));
        self::assertSame('deleted_both', AchievementLifecycle::deletionState('deleted_badger', true));
        self::assertSame('deleted_user', AchievementLifecycle::deletionState('deleted_user', true));
        self::assertSame('deleted_both', AchievementLifecycle::deletionState('deleted_both', false));
    }

    public function testUnknownDeletionStateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AchievementLifecycle::deletionState('unknown', true);
    }
}
