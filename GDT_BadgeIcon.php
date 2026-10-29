<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDT_UInt;

final class GDT_BadgeIcon extends GDT_UInt
{
    public function isOrderable(): bool { return false; }
    public function isSearchable(): bool { return false; }
    public function isFilterable(): bool { return false; }

    public function renderCell(): string
    {
        $badge = $this->getGDO();
        if (!$badge instanceof GDO_Badge || !$badge->gdoVar('bb_icon')) { return ''; }
        return '<img alt="" src="' . html(href('Badger', 'BadgeIcon', '&badge=' . $badge->getID())) . '">';
    }
}
