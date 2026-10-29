<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\GDO_Ranking;
use GDO\Core\GDO;
use GDO\DB\Query;
use GDO\Table\MethodQueryTable;

final class Rankings extends MethodQueryTable
{
    public function isUserRequired(): bool { return false; }
    public function isPaginated(): bool { return false; }
    public function isOrdered(): bool { return false; }
    public function isSearched(): bool { return false; }
    public function isFiltered(): bool { return false; }
    public function gdoTable(): GDO { return GDO_Ranking::table(); }

    public function gdoQuery(): Query
    {
        return GDO_Ranking::table()->query()
            ->select('a.ba_user, COUNT(*) AS ba_total_count, SUM(b.bb_xp) AS ba_total_xp, SUM(b.bb_hp) AS ba_total_hp, SUM(b.bb_str) AS ba_total_str, SUM(b.bb_int) AS ba_total_int')
            ->from('gdo_badger_achieve a')
            ->join('JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge')
            ->join("JOIN gdo_user u ON u.user_id=a.ba_user AND u.user_type='member'")
            ->where("a.ba_status='claimed'")
            ->group('a.ba_user')->order('ba_total_xp DESC')->uncached();
    }

    public function gdoHeaders(): array
    {
        $table = GDO_Ranking::table();
        return array_map(fn (string $name) => $table->gdoColumn($name),
            ['ba_user', 'ba_total_count', 'ba_total_xp', 'ba_total_hp', 'ba_total_str', 'ba_total_int']);
    }
}
