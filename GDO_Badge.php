<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\Core\GDT_AutoInc;
use GDO\Core\GDT_Checkbox;
use GDO\Core\GDT_Int;
use GDO\Core\GDT_Object;
use GDO\Core\GDT_String;
use GDO\Core\GDT_Text;
use GDO\Core\GDT_UInt;
use GDO\File\GDT_ImageFile;
use GDO\UI\GDT_Width;
use GDO\UI\GDT_Height;
use GDO\User\GDT_User;
use GDO\User\GDT_ACLRelation;

final class GDO_Badge extends GDO
{
    public function gdoTableName(): string { return 'gdo_badger_badge'; }
    public function renderName(): string { return html($this->gdoVar('bb_name') ?? ''); }

    public function gdoColumns(): array
    {
        return [
            GDT_AutoInc::make('bb_id'),
            GDT_User::make('bb_owner')->notNull()->cascadeRestrict(),
            GDT_String::make('bb_name')->max(64)->notNull(),
            GDT_Text::make('bb_description')->label('bb_description'),
            GDT_Object::make('bb_category')->table(GDO_Category::table())->cascadeRestrict(),
            GDT_ACLRelation::make('bb_visibility')->initial(GDT_ACLRelation::ALL),
            GDT_ImageFile::make('bb_image')->cascadeNull(),
            GDT_ImageFile::make('bb_icon')->cascadeNull(),
            GDT_ImageFile::make('bb_item')->cascadeNull(),
            GDT_Checkbox::make('bb_has_image')->notNull()->initial('0'),
            GDT_String::make('bb_item_name'),
            GDT_Int::make('bb_item_x'),
            GDT_Int::make('bb_item_y'),
            GDT_Width::make('bb_item_w'),
            GDT_Height::make('bb_item_h'),
            GDT_UInt::make('bb_xp')->notNull()->initial('1'),
            GDT_UInt::make('bb_hp')->notNull()->initial('0'),
            GDT_UInt::make('bb_str')->notNull()->initial('0'),
            GDT_UInt::make('bb_int')->notNull()->initial('0'),
            GDT_Checkbox::make('bb_verify_staff')->notNull()->initial('1'),
            GDT_Checkbox::make('bb_verify_cc')->notNull()->initial('0'),
            GDT_Checkbox::make('bb_verify_doc')->notNull()->initial('0'),
            GDT_Text::make('bb_verify_ai'),
            GDT_Text::make('bb_verify_ai_doc'),
            GDT_Checkbox::make('bb_approve_ai')->notNull()->initial('0'),
            GDT_UInt::make('bb_staff_approvals_needed')->notNull()->initial('1')->min(1),
            GDT_UInt::make('bb_community_approvals_needed')->notNull()->initial('1')->min(1),
            GDT_Checkbox::make('bb_active')->notNull()->initial('1'),
        ];
    }
}
