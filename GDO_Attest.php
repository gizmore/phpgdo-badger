<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\Core\GDT_AutoInc;
use GDO\Core\GDT_CreatedAt;
use GDO\Core\GDT_Enum;
use GDO\Core\GDT_Object;
use GDO\Core\GDT_String;
use GDO\Core\GDT_Text;
use GDO\Core\GDT_UInt;
use GDO\DB\Query;
use GDO\User\GDT_User;

final class GDO_Attest extends GDO
{
    public function gdoTableName(): string { return 'gdo_badger_attest'; }

    public function updateQuery(): Query { throw new \LogicException('Attestations are immutable'); }
    public function update(): Query { throw new \LogicException('Attestations are immutable'); }
    public function delete(bool $withHooks = true): GDO { throw new \LogicException('Attestations are immutable'); }
    public function deleteWhere(string $condition, bool $withHooks = true): int { throw new \LogicException('Attestations are immutable'); }
    public function deleteQuery(): Query { throw new \LogicException('Attestations are immutable'); }
    public function replace(bool $withHooks = true): static { throw new \LogicException('Attestations are immutable'); }
    public function softReplace(bool $withHooks = true): GDO { throw new \LogicException('Attestations are immutable'); }

    public function gdoColumns(): array
    {
        return [
            GDT_AutoInc::make('bat_id'),
            GDT_Object::make('bat_achieve')->table(GDO_Achieve::table())->notNull()->cascadeRestrict(),
            GDT_Enum::make('bat_actor_type')->enumValues('staff', 'community', 'ai', 'system')->notNull(),
            GDT_User::make('bat_actor'),
            GDT_String::make('bat_decision')->notNull(),
            GDT_UInt::make('bat_round')->notNull()->initial('0'),
            GDT_Text::make('bat_reason'),
            GDT_Text::make('bat_metadata'),
            GDT_String::make('bat_from_status'),
            GDT_String::make('bat_to_status'),
            GDT_CreatedAt::make('bat_created'),
        ];
    }
}
