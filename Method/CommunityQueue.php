<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\BadgerPermissions;
use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDO_Badge;
use GDO\Badger\GDT_AchievementAction;
use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\DB\Query;
use GDO\Table\MethodQueryTable;
use GDO\User\GDO_User;

final class CommunityQueue extends MethodQueryTable
{
    public function isUserRequired(): bool { return true; }
    public function isGuestAllowed(): bool { return false; }
    public function gdoTable(): GDO { return GDO_Achieve::table(); }

    public function gdoQuery(): Query
    {
        $viewer = GDO_User::current();
        if (!$viewer->getID())
        {
            return GDO_Achieve::table()->select()->where('1=0');
        }
        $uid = GDO::quoteS($viewer->getID());
        $sql = "SELECT a.ba_id,a.ba_badge,a.ba_user,a.ba_status,a.ba_deletion,a.ba_visibility,b.bb_id,b.bb_owner FROM gdo_badger_achieve a " .
            "JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge JOIN gdo_badger_achieve holder ON holder.ba_badge=a.ba_badge AND holder.ba_user={$uid} " .
            "WHERE a.ba_status='pending' AND b.bb_verify_cc=1 AND holder.ba_status='claimed' AND holder.ba_is_staff=0 AND a.ba_user<>{$uid} AND b.bb_owner<>{$uid} " .
            "AND NOT EXISTS (SELECT 1 FROM gdo_badger_attest v WHERE v.bat_achieve=a.ba_id AND v.bat_round=a.ba_round AND v.bat_actor={$uid} AND v.bat_decision='approve' AND v.bat_actor_type IN ('staff','community'))";
        $rows = Database::instance()->queryRead($sql);
        $conditions = [];
        while ($row = $rows->fetch_assoc())
        {
            $recipient = GDO_User::blank(['user_id' => $row['ba_user']]);
            if (BadgerPermissions::canView($viewer, $recipient, GDO_Badge::entity($row), GDO_Achieve::entity($row), false))
            {
                $conditions[] = '(ba_id=' . (int) $row['ba_id'] . ' AND ba_user=' . GDO::quoteS($row['ba_user']) .
                    ' AND ba_visibility=' . GDO::quoteS($row['ba_visibility']) . ' AND ba_deletion=' . GDO::quoteS($row['ba_deletion']) . ')';
            }
        }
        $query = GDO_Achieve::table()->select()->where($conditions ? implode(' OR ', $conditions) : '1=0');
        $query->where("ba_status='pending'");
        $query->where("EXISTS (SELECT 1 FROM gdo_badger_badge b JOIN gdo_badger_achieve h ON h.ba_badge=b.bb_id AND h.ba_user={$uid} WHERE b.bb_id=gdo_badger_achieve.ba_badge AND b.bb_verify_cc=1 AND b.bb_owner<>{$uid} AND h.ba_status='claimed' AND h.ba_is_staff=0)");
        $query->where("NOT EXISTS (SELECT 1 FROM gdo_badger_attest v WHERE v.bat_achieve=gdo_badger_achieve.ba_id AND v.bat_round=gdo_badger_achieve.ba_round AND v.bat_actor={$uid} AND v.bat_decision='approve' AND v.bat_actor_type IN ('staff','community'))");
        return $query;
    }

    public function gdoHeaders(): array
    {
        $table = GDO_Achieve::table();
        return [$table->gdoColumn('ba_badge'), $table->gdoColumn('ba_user'), GDT_AchievementAction::make('ba_id')->label('badger_open')];
    }
}
