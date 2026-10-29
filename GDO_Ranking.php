<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\Core\GDT_UInt;
use GDO\User\GDT_User;

final class GDO_Ranking extends GDO
{
    public function gdoTableName(): string { return 'gdo_badger_achieve'; }
    public function gdoCached(): bool { return false; }

    public function gdoColumns(): array
    {
        return [
            GDT_User::make('ba_user')->primary(),
            GDT_UInt::make('ba_total_count'),
            GDT_UInt::make('ba_total_xp'),
            GDT_UInt::make('ba_total_hp'),
            GDT_UInt::make('ba_total_str'),
            GDT_UInt::make('ba_total_int'),
        ];
    }
}
