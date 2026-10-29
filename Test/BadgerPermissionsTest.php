<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\BadgerPermissions;
use GDO\Badger\AchievementActions;
use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDO_Badge;
use GDO\User\GDO_User;
use PHPUnit\Framework\TestCase;

final class BadgerPermissionsTest extends TestCase
{
    public function testRoleMatrix(): void
    {
        foreach (['submit', 'withdraw', 'claim', 'share'] as $action)
        {
            self::assertTrue(BadgerPermissions::allows($action, true, false, false, false));
            self::assertFalse(BadgerPermissions::allows($action, false, true, false, false));
        }
        self::assertFalse(BadgerPermissions::allows('claim', false, false, false, true));
        foreach (['approve', 'reject', 'revoke', 'edit'] as $action)
        {
            self::assertFalse(BadgerPermissions::allows($action, true, false, false, false));
            self::assertTrue(BadgerPermissions::allows($action, false, true, false, false));
            self::assertTrue(BadgerPermissions::allows($action, false, false, true, false));
            self::assertTrue(BadgerPermissions::allows($action, false, false, false, true));
        }
        foreach (['staff', 'transfer'] as $action)
        {
            self::assertFalse(BadgerPermissions::allows($action, false, true, false, false));
            self::assertTrue(BadgerPermissions::allows($action, false, false, true, false));
            self::assertTrue(BadgerPermissions::allows($action, false, false, false, true));
        }
        self::assertFalse(BadgerPermissions::allows('unknown', true, true, true, true));
    }

    public function testRejectsMismatchedBadgeBeforeDatabaseAccess(): void
    {
        $actor = GDO_User::blank(['user_id' => '10']);
        $badge = GDO_Badge::blank(['bb_id' => '1']);
        $achievement = GDO_Achieve::blank(['ba_badge' => '2', 'ba_status' => 'pending']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Permission denied');
        AchievementActions::transition($actor, $badge, $achievement, 'rejected');
    }

    public function testApprovalRejectsMismatchedBadge(): void
    {
        $actor = GDO_User::blank(['user_id' => '10']);
        $badge = GDO_Badge::blank(['bb_id' => '1']);
        $achievement = GDO_Achieve::blank(['ba_badge' => '2', 'ba_status' => 'pending']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Permission denied');
        AchievementActions::approve($actor, $badge, $achievement);
    }

    public function testGuestCannotUseRetainedOwnerOrRecipientIdentity(): void
    {
        $guest = GDO_User::blank(['user_id' => '10', 'user_type' => 'guest']);
        $badge = GDO_Badge::blank(['bb_id' => '1', 'bb_owner' => '10']);
        $achievement = GDO_Achieve::blank(['ba_id' => '2', 'ba_badge' => '1', 'ba_user' => '10', 'ba_status' => 'pending']);
        self::assertFalse(BadgerPermissions::canEditBadge($guest, $badge));
        self::assertFalse(BadgerPermissions::forAchievement('submit', $guest, $badge, $achievement));
        $owner = GDO_User::blank(['user_id' => '10', 'user_type' => 'member']);
        $badge->setVar('bb_visibility', 'acl_noone');
        $achievement->setVar('ba_visibility', 'acl_noone');
        self::assertFalse(BadgerPermissions::canViewBadge($guest, $owner, $badge));
        self::assertFalse(BadgerPermissions::canView($guest, $owner, $badge, $achievement));
        try
        {
            AchievementActions::approve($guest, $badge, $achievement);
            self::fail('A guest cannot approve as a former owner');
        }
        catch (\RuntimeException $ex) { self::assertSame('Permission denied', $ex->getMessage()); }
        try
        {
            \GDO\Badger\AchievementDeletion::mark($guest, $badge, $achievement, true);
            self::fail('A guest cannot delete a former recipient record');
        }
        catch (\RuntimeException $ex) { self::assertSame('Permission denied', $ex->getMessage()); }
        try
        {
            \GDO\Badger\AchievementSharing::set($guest, $badge, $achievement, 'acl_all');
            self::fail('A guest cannot change former recipient sharing');
        }
        catch (\RuntimeException $ex) { self::assertSame('Invalid sharing request', $ex->getMessage()); }
        foreach ([
            static fn () => \GDO\Badger\EvidenceAttachments::attach($guest, $badge, $achievement, '', '', 1, [], null),
            static fn () => \GDO\Badger\EvidenceApproval::approve($guest, $achievement, ''),
            static fn () => \GDO\Badger\AIReviewHumanApproval::accept($guest, $achievement, ''),
            static fn () => \GDO\Badger\ItemStorage::save($guest, $badge, ''),
            static fn () => \GDO\Badger\BadgeStaff::set($guest, $badge, $owner, true),
            static fn () => \GDO\Badger\BadgeOwnership::transfer($guest, $badge, $owner),
        ] as $operation)
        {
            try { $operation(); self::fail('A guest must not use a retained privileged identity'); }
            catch (\RuntimeException $ex) { self::assertSame('Permission denied', $ex->getMessage()); }
        }
    }
}
