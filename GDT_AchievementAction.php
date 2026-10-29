<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDT_UInt;
use GDO\UI\GDT_Link;

final class GDT_AchievementAction extends GDT_UInt
{
    public function isOrderable(): bool { return false; }
    public function isSearchable(): bool { return false; }
    public function isFilterable(): bool { return false; }

    public function renderCell(): string
    {
        $achievement = $this->getGDO();
        return $achievement instanceof GDO_Achieve
            ? GDT_Link::make()->href(href('Badger', 'Achieve', '&achievement=' . $achievement->getID()))->text('badger_open')->render()
            : '';
    }
}
