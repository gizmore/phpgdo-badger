<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDT_UInt;
use GDO\UI\GDT_Link;

final class GDT_BadgeAction extends GDT_UInt
{
    public function isOrderable(): bool { return false; }
    public function isSearchable(): bool { return false; }
    public function isFilterable(): bool { return false; }

    public function renderCell(): string
    {
        $badge = $this->getGDO();
        return $badge instanceof GDO_Badge
            ? GDT_Link::make()->href(href('Badger', 'Achieve', '&badge=' . $badge->getID()))->text('badger_request')->render()
            : '';
    }
}
