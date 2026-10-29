<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Badger\Service\BadgeTags;

use GDO\Core\GDT_String;
use GDO\DB\Query;
use GDO\Table\GDT_Filter;

final class GDT_BadgeTags extends GDT_String
{
    public function isOrderable(): bool { return false; }
    public function isSearchable(): bool { return false; }
    public function isFilterable(): bool { return true; }

    public function filterQuery(Query $query, GDT_Filter $filter): static
    {
        $vars = $filter->getVar();
        $value = is_array($vars) ? ($vars[$this->getName()] ?? null) : null;
        if (is_string($value) && ($value = trim($value)) !== '')
        {
            if (strlen($value) > 255 || !mb_check_encoding($value, 'UTF-8'))
            {
                $query->where('1=0');
                return $this;
            }
            $hex = bin2hex($value);
            $query->where("EXISTS (SELECT 1 FROM gdo_badger_tag bt WHERE bt.bt_badge=gdo_badger_badge.bb_id AND LOCATE(LOWER(CONVERT(0x{$hex} USING utf8mb4)) COLLATE utf8mb4_unicode_ci, LOWER(bt.bt_name)) > 0)");
        }
        return $this;
    }

    public function renderCell(): string
    {
        $badge = $this->getGDO();
        return $badge instanceof GDO_Badge && $badge->getID() ? html(implode(', ', BadgeTags::names($badge->getID()))) : '';
    }
}
