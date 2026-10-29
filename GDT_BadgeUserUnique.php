<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDT;
use GDO\Core\WithName;

final class GDT_BadgeUserUnique extends GDT
{
    use WithName;

    public function isVirtual(): bool { return true; }
    public function isPrimary(): bool { return false; }
    public function gdoColumnNames(): array { return [$this->getName()]; }
    public function gdoColumnDefine(): string { return 'UNIQUE (ba_badge, ba_user)'; }
}
