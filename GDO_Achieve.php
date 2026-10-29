<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\Core\GDT_AutoInc;
use GDO\Core\GDT_Checkbox;
use GDO\Core\GDT_CreatedAt;
use GDO\Core\GDT_Enum;
use GDO\Core\GDT_Object;
use GDO\Core\GDT_UInt;
use GDO\Core\GDT_Virtual;
use GDO\Date\GDT_DateTime;
use GDO\DB\Query;
use GDO\User\GDT_ACLRelation;
use GDO\User\GDT_User;

final class GDO_Achieve extends GDO
{
    public function gdoTableName(): string { return 'gdo_badger_achieve'; }

    public function delete(bool $withHooks = true): GDO { throw new \LogicException('Achievements are retained'); }
    public function deleteWhere(string $condition, bool $withHooks = true): int { throw new \LogicException('Achievements are retained'); }
    public function deleteQuery(): Query { throw new \LogicException('Achievements are retained'); }

    public function gdoColumns(): array
    {
        $columns = [
            GDT_AutoInc::make('ba_id'),
            GDT_Object::make('ba_badge')->table(GDO_Badge::table())->notNull()->cascadeRestrict(),
            GDT_User::make('ba_user')->notNull()->cascadeRestrict(),
            GDT_Enum::make('ba_status')->enumValues('viewed', 'draft', 'pending', 'verified', 'claimed', 'rejected', 'withdrawn', 'revoked')->notNull()->initial('viewed'),
            GDT_Enum::make('ba_deletion')->enumValues('not_deleted', 'deleted_badger', 'deleted_user', 'deleted_both')->notNull()->initial('not_deleted'),
            GDT_ACLRelation::make('ba_visibility')->initial(GDT_ACLRelation::ALL),
            GDT_Checkbox::make('ba_is_staff')->notNull()->initial('0'),
            GDT_Checkbox::make('ba_equipped')->notNull()->initial('0'),
            GDT_UInt::make('ba_round')->notNull()->initial('0'),
            GDT_UInt::make('ba_staff_approvals')->notNull()->initial('0'),
            GDT_UInt::make('ba_community_approvals')->notNull()->initial('0'),
            GDT_CreatedAt::make('ba_created'),
            GDT_DateTime::make('ba_submitted'),
            GDT_DateTime::make('ba_verified'),
            GDT_DateTime::make('ba_claimed'),
            GDT_DateTime::make('ba_revoked'),
            GDT_BadgeUserUnique::make('ba_badge_user_unique'),
        ];
        foreach (['xp', 'hp', 'str', 'int'] as $stat)
        {
            $columns[] = GDT_Virtual::make('ba_total_' . $stat)
                ->gdtType(GDT_UInt::make())
                ->subquery("SELECT COALESCE(SUM(b.bb_{$stat}), 0) FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id = a.ba_badge WHERE a.ba_user = gdo_badger_achieve.ba_user AND a.ba_status = 'claimed'");
        }
        $columns[] = GDT_Virtual::make('ba_total_count')->gdtType(GDT_UInt::make())
            ->subquery("SELECT COUNT(*) FROM gdo_badger_achieve a WHERE a.ba_user = gdo_badger_achieve.ba_user AND a.ba_status = 'claimed'");
        return $columns;
    }
}
