<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDT_AchievementAction;
use GDO\Core\GDO;
use GDO\DB\Query;
use GDO\Table\MethodQueryTable;
use GDO\User\GDO_User;

final class ReviewQueue extends MethodQueryTable
{
    public function isUserRequired(): bool { return true; }
    public function isGuestAllowed(): bool { return false; }
    public function gdoTable(): GDO { return GDO_Achieve::table(); }

    public function gdoQuery(): Query
    {
        $user = GDO_User::current();
        $query = GDO_Achieve::table()->select()->where("ba_status='pending'")
            ->where("ba_deletion NOT IN ('deleted_badger', 'deleted_both')");
        if (!$user->isStaff() && !$user->isAdmin())
        {
            $id = GDO::quoteS($user->getID());
            $query->where("EXISTS (SELECT 1 FROM gdo_badger_badge b WHERE b.bb_id=gdo_badger_achieve.ba_badge AND (b.bb_owner={$id} OR EXISTS (SELECT 1 FROM gdo_badger_achieve staff WHERE staff.ba_badge=b.bb_id AND staff.ba_user={$id} AND staff.ba_is_staff=1)))");
        }
        return $query;
    }

    public function gdoHeaders(): array
    {
        $table = GDO_Achieve::table();
        return [$table->gdoColumn('ba_badge'), $table->gdoColumn('ba_user'), GDT_AchievementAction::make('ba_id')->label('badger_open')];
    }
}
