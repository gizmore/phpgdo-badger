<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\Tags\GDT_TagName;
use RuntimeException;

final class BadgeTags
{
    public static function parse(string $input): array
    {
        $tags = [];
        foreach (explode(',', $input) as $part)
        {
            $name = trim($part);
            if ($name === '') { continue; }
            if (!GDT_TagName::make('tag')->validate($name)) { throw new RuntimeException('Invalid badge tag'); }
            $tags[mb_strtolower($name, 'UTF-8')] = $name;
        }
        if (count($tags) > 8) { throw new RuntimeException('Too many badge tags'); }
        return array_values($tags);
    }

    public static function names(string $badgeId): array
    {
        $id = GDO::quoteS($badgeId);
        $rows = Database::instance()->queryRead("SELECT bt_name FROM gdo_badger_tag WHERE bt_badge={$id} ORDER BY bt_name");
        $tags = [];
        while ($row = $rows->fetch_assoc()) { $tags[] = $row['bt_name']; }
        return $tags;
    }

    /** Caller locks the badge and owns the transaction. */
    public static function replace(string $badgeId, array $tags): void
    {
        $id = GDO::quoteS($badgeId);
        $db = Database::instance();
        if (!$db->queryWrite("DELETE FROM gdo_badger_tag WHERE bt_badge={$id}")) { throw new RuntimeException('Could not update tags'); }
        foreach ($tags as $tag)
        {
            if (!$db->queryWrite('INSERT INTO gdo_badger_tag (bt_badge, bt_name) VALUES (' . $id . ', ' . GDO::quoteS($tag) . ')'))
            {
                throw new RuntimeException('Could not update tags');
            }
        }
    }
}
