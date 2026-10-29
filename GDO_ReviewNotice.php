<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\Core\GDT_Checkbox;
use GDO\Core\GDT_CreatedAt;
use GDO\Core\GDT_Object;
use GDO\User\GDT_User;

final class GDO_ReviewNotice extends GDO
{
    public function gdoTableName(): string { return 'gdo_badger_review_notice'; }
    public function gdoCached(): bool { return false; }
    public function gdoColumns(): array
    {
        return [GDT_Object::make('bn_attest')->table(GDO_Attest::table())->primary()->cascadeRestrict(),
            GDT_User::make('bn_user')->primary()->cascadeRestrict(),
            GDT_Checkbox::make('bn_delivered')->notNull()->initial('0'), GDT_CreatedAt::make('bn_created')];
    }
}
