<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\User\GDO_User;

final class ProfileAchievements
{
    private static function visible(array $row, GDO_User $viewer, GDO_User $target): bool
    {
        if ($row['ba_status'] !== 'claimed' || in_array($row['ba_deletion'], ['deleted_user', 'deleted_both'], true))
        {
            return false;
        }
        $achievement = GDO_Achieve::entity($row);
        $badge = GDO_Badge::entity($row);
        return BadgerPermissions::canView($viewer, $target, $badge, $achievement);
    }

    private static function columns(): string
    {
        return 'a.ba_id,a.ba_badge,a.ba_user,a.ba_status,a.ba_deletion,a.ba_visibility,b.bb_id,b.bb_owner,b.bb_name,b.bb_icon';
    }

    public static function render(GDO_User $viewer, GDO_User $target): string
    {
        if (!$target->getID())
        {
            return '';
        }
        $uid = GDO::quoteS($target->getID());
        $rows = Database::instance()->queryRead('SELECT ' . self::columns() . " FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge WHERE a.ba_user={$uid} AND a.ba_status='claimed' ORDER BY a.ba_id");
        $content = '';
        while ($row = $rows->fetch_assoc())
        {
            if (!self::visible($row, $viewer, $target))
            {
                continue;
            }
            $name = html($row['bb_name'] ?? '');
            $icon = $row['bb_icon'] ? '<img alt="" src="' . html(href('Badger', 'ProfileIcon', '&achievement=' . $row['ba_id'])) . '">' : '';
            $content .= '<li>' . $icon . $name . '</li>';
        }
        return $content === '' ? '' : '<section class="badger-profile"><h3>' . html(t('badger_profile_achievements')) . '</h3><ul>' . $content . '</ul></section>';
    }

    public static function iconId(GDO_User $viewer, string $achievementId): ?string
    {
        $id = GDO::quoteS($achievementId);
        $row = Database::instance()->queryRead('SELECT ' . self::columns() . " FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge WHERE a.ba_id={$id}")->fetch_assoc();
        if (!$row || !$row['bb_icon'])
        {
            return null;
        }
        $target = GDO_User::blank(['user_id' => $row['ba_user']]);
        return self::visible($row, $viewer, $target) ? $row['bb_icon'] : null;
    }
}
