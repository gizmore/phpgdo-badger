<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\GDO_Badge;
use GDO\Badger\GDT_BadgeEditAction;
use GDO\Core\GDO;
use GDO\Table\MethodQueryTable;

final class BadgeCrud extends MethodQueryTable
{
    public function getPermission(): ?string { return 'staff'; }
    public function isGuestAllowed(): bool { return false; }
    public function gdoTable(): GDO { return GDO_Badge::table(); }
    public function gdoHeaders(): array
    {
        $table = GDO_Badge::table();
        return [$table->gdoColumn('bb_name'), $table->gdoColumn('bb_owner'), $table->gdoColumn('bb_active'), GDT_BadgeEditAction::make('bb_id')->label('badger_edit')];
    }
}
