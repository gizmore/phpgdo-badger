<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDT_AchievementAction;
use GDO\Core\GDO;
use GDO\DB\Query;
use GDO\Table\MethodQueryTable;
use GDO\User\GDO_User;

final class MyAchievements extends MethodQueryTable
{
    public function isUserRequired(): bool { return true; }
    public function isGuestAllowed(): bool { return false; }
    public function gdoTable(): GDO { return GDO_Achieve::table(); }
    public function gdoQuery(): Query
    {
        $userId = GDO_User::current()->getID();
        return GDO_Achieve::table()->select()->where('ba_user=' . GDO::quoteS($userId))
            ->where("ba_deletion NOT IN ('deleted_user', 'deleted_both')");
    }
    public function gdoHeaders(): array
    {
        $table = GDO_Achieve::table();
        return [$table->gdoColumn('ba_badge'), $table->gdoColumn('ba_status'), GDT_AchievementAction::make('ba_id')->label('badger_open')];
    }
}
