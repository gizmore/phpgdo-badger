<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\User\GDT_ACLRelation;
use GDO\User\GDO_User;

final class BadgerPermissions
{
    public static function canEditBadge(GDO_User $user, GDO_Badge $badge): bool
    {
        $userId = $user->getID();
        $badgeId = $badge->getID();
        return $user->isMember() && $userId !== null && $badgeId !== null &&
            ($badge->gdoVar('bb_owner') === $userId || $user->isStaff() || $user->isAdmin() || self::isBadgeStaff($badgeId, $userId));
    }

    public static function canViewBadge(GDO_User $viewer, GDO_User $owner, GDO_Badge $badge, bool $includeStaff = true): bool
    {
        if ($owner->getID() === null || $badge->gdoVar('bb_owner') !== $owner->getID())
        {
            return false;
        }
        if ($viewer->isMember() && $viewer->getID() !== null && $viewer->getID() === $owner->getID())
        {
            return true;
        }
        if ($viewer->isMember() && $includeStaff && $viewer->getID() !== null && $badge->getID() !== null && self::isBadgeStaff($badge->getID(), $viewer->getID()))
        {
            return true;
        }
        if ($viewer->isMember() && ($viewer->isStaff() || $viewer->isAdmin()))
        {
            return true;
        }
        $reason = '';
        return GDT_ACLRelation::make('bb_visibility')->var($badge->gdoVar('bb_visibility'))
            ->hasAccess($viewer, $owner, $reason);
    }

    public static function forAchievement(string $action, GDO_User $actor, GDO_Badge $badge, GDO_Achieve $achievement): bool
    {
        $actorId = $actor->getID();
        if (!$actor->isMember() || $actorId === null || $badge->getID() === null || $achievement->gdoVar('ba_badge') !== $badge->getID())
        {
            return false;
        }
        return self::allows(
            $action,
            $achievement->gdoVar('ba_user') === $actorId,
            self::isBadgeStaff($badge->getID(), $actorId),
            $badge->gdoVar('bb_owner') === $actorId,
            $actor->isStaff() || $actor->isAdmin(),
        );
    }

    public static function isBadgeStaff(string $badgeId, string $userId): bool
    {
        return GDO_Achieve::table()->countWhere('ba_badge=' . GDO::quoteS($badgeId) .
            ' AND ba_user=' . GDO::quoteS($userId) . ' AND ba_is_staff=1') > 0;
    }

    public static function canView(GDO_User $viewer, GDO_User $recipient, GDO_Badge $badge, GDO_Achieve $achievement, bool $includeBadgeStaff = true): bool
    {
        $viewerId = $viewer->getID();
        $badgeId = $badge->getID();
        if ($badgeId === null || $achievement->gdoVar('ba_badge') !== $badgeId ||
            $recipient->getID() === null || $achievement->gdoVar('ba_user') !== $recipient->getID())
        {
            return false;
        }
        if ($viewer->isMember() && $viewerId !== null && ($viewerId === $recipient->getID() ||
            $viewerId === $badge->gdoVar('bb_owner') || ($includeBadgeStaff && self::isBadgeStaff($badgeId, $viewerId))))
        {
            return true;
        }
        if ($viewer->isMember() && ($viewer->isStaff() || $viewer->isAdmin()))
        {
            return true;
        }
        if (in_array($achievement->gdoVar('ba_deletion'), ['deleted_user', 'deleted_both'], true))
        {
            return false;
        }
        $reason = '';
        return GDT_ACLRelation::make('ba_visibility')->var($achievement->gdoVar('ba_visibility'))
            ->hasAccess($viewer, $recipient, $reason);
    }

    public static function allows(string $action, bool $recipient, bool $badgeStaff, bool $owner, bool $siteStaff): bool
    {
        return match ($action) {
            'submit', 'withdraw', 'share' => $recipient || $siteStaff,
            'claim' => $recipient,
            'approve', 'reject', 'revoke', 'edit' => $badgeStaff || $owner || $siteStaff,
            'staff', 'transfer' => $owner || $siteStaff,
            default => false,
        };
    }
}
