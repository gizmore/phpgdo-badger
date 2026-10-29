<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\Core\GDT_AutoInc;
use GDO\Core\GDT_CreatedAt;
use GDO\Core\GDT_EditedAt;
use GDO\Core\GDT_Object;
use GDO\Core\GDT_String;
use GDO\Core\GDT_UInt;

final class GDO_Category extends GDO
{
    public function gdoTableName(): string { return 'gdo_badger_category'; }
    public function gdoCached(): bool { return false; }
    public function renderName(): string { return html($this->gdoVar('bc_name') ?? ''); }

    public function gdoColumns(): array
    {
        return [
            GDT_AutoInc::make('bc_id'),
            GDT_Object::make('bc_parent')->table(self::table())->cascadeRestrict(),
            GDT_String::make('bc_name')->max(128)->notNull(),
            GDT_String::make('bc_slug')->max(128)->notNull(),
            GDT_UInt::make('bc_sort')->notNull()->initial('0'),
            GDT_CreatedAt::make('bc_created'),
            GDT_EditedAt::make('bc_edited'),
        ];
    }
}
