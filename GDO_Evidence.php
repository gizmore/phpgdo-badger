<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\Core\GDT_AutoInc;
use GDO\Core\GDT_Checkbox;
use GDO\Core\GDT_CreatedAt;
use GDO\Core\GDT_Object;
use GDO\Core\GDT_String;
use GDO\Core\GDT_UInt;

final class GDO_Evidence extends GDO
{
    public function gdoTableName(): string { return 'gdo_badger_evidence'; }
    public function gdoCached(): bool { return false; }

    public function gdoColumns(): array
    {
        return [
            GDT_AutoInc::make('be_id'),
            GDT_Object::make('be_achieve')->table(GDO_Achieve::table())->notNull()->cascadeRestrict(),
            GDT_UInt::make('be_round')->notNull(),
            GDT_String::make('be_key')->max(64)->notNull()->hidden(),
            GDT_String::make('be_sha256')->max(64)->notNull(),
            GDT_String::make('be_mime')->max(128)->notNull(),
            GDT_UInt::make('be_size')->notNull(),
            GDT_Checkbox::make('be_deleted')->notNull()->initial('0'),
            GDT_CreatedAt::make('be_created'),
        ];
    }
}
