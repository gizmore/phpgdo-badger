<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\Core\GDT_Object;
use GDO\Tags\GDT_TagName;

final class GDO_BadgeTag extends GDO
{
    public function gdoTableName(): string { return 'gdo_badger_tag'; }
    public function gdoCached(): bool { return false; }
    public function gdoColumns(): array
    {
        return [GDT_Object::make('bt_badge')->table(GDO_Badge::table())->primary()->cascadeRestrict(),
            GDT_TagName::make('bt_name')->primary()->notNull()];
    }
}
