<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\Service\BadgeListing;
use GDO\Badger\GDO_Badge;
use GDO\Badger\GDT_BadgeAction;
use GDO\Badger\GDT_BadgeTags;
use GDO\Badger\GDT_BadgeIcon;
use GDO\Core\GDO;
use GDO\DB\Query;
use GDO\Table\MethodQueryTable;
use GDO\User\GDO_User;

final class Badges extends MethodQueryTable
{
    public function isUserRequired(): bool { return false; }
    public function gdoTable(): GDO { return GDO_Badge::table(); }
    public function gdoQuery(): Query { return BadgeListing::query(GDO_User::current()); }
    public function gdoHeaders(): array
    {
        $badge = GDO_Badge::table();
        return [GDT_BadgeIcon::make('bb_icon')->label('bb_icon'), $badge->gdoColumn('bb_name'), $badge->gdoColumn('bb_description'), $badge->gdoColumn('bb_country'), GDT_BadgeTags::make('badge_tags')->label('badger_tags'), GDT_BadgeAction::make('bb_id')->label('badger_request')];
    }
}
