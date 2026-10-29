<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Badger\GDO_Badge;

use GDO\Core\GDO;
use GDO\DB\Query;
use GDO\User\GDO_User;

final class BadgeListing
{
    public static function query(GDO_User $user): Query
    {
        $query = GDO_Badge::table()->select()->where('bb_active=1');
        if ($user->isMember() && ($user->isStaff() || $user->isAdmin()))
        {
            return $query;
        }
        $conditions = ["bb_visibility='acl_all'"];
        $id = $user->getID();
        if ($id !== null)
        {
            $quoted = GDO::quoteS($id);
            if ($user->isMember())
            {
                $conditions[] = "bb_owner={$quoted}";
                $conditions[] = "EXISTS (SELECT 1 FROM gdo_badger_achieve staff WHERE staff.ba_badge=gdo_badger_badge.bb_id AND staff.ba_user={$quoted} AND staff.ba_is_staff=1)";
            }
            if ($user->isUser())
            {
                $conditions[] = "bb_visibility='acl_guests'";
            }
            if ($user->isMember())
            {
                $conditions[] = "bb_visibility='acl_members'";
            }
            if (module_enabled('Friends'))
            {
                $conditions[] = "(bb_visibility='acl_friends' AND EXISTS (SELECT 1 FROM gdo_friendship WHERE friend_user={$quoted} AND friend_friend=bb_owner))";
            }
        }
        return $query->where(implode(' OR ', $conditions));
    }
}
