<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Badger\GDO_Category;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\DB\Result;
use RuntimeException;
use Throwable;

final class CategoryTree
{
    public static function create(string $name, string $slug, ?GDO_Category $parent): GDO_Category
    {
        $name = trim($name);
        $slug = trim($slug);
        if ($name === '' || $slug === '')
        {
            throw new RuntimeException('A category needs a name and slug');
        }
        return GDO_Category::blank([
            'bc_name' => $name,
            'bc_slug' => $slug,
            'bc_parent' => $parent?->getID(),
        ])->insert();
    }

    public static function setParent(string $categoryId, ?string $parentId, bool $ownsTransaction = true): void
    {
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin category transaction');
        }
        try
        {
            $rows = (new Result(GDO_Category::table(), $db->queryRead('SELECT bc_id, bc_parent FROM gdo_badger_category ORDER BY bc_id FOR UPDATE'), false))->fetchAllAssoc() ?? [];
            $parents = [];
            foreach ($rows as $row)
            {
                $parents[$row['bc_id']] = $row['bc_parent'];
            }
            if (!array_key_exists($categoryId, $parents) || ($parentId !== null && !array_key_exists($parentId, $parents)))
            {
                throw new RuntimeException('Unknown category');
            }
            $seen = [];
            for ($cursor = $parentId; $cursor !== null; $cursor = $parents[$cursor] ?? null)
            {
                if (!array_key_exists($cursor, $parents))
                {
                    throw new RuntimeException('Broken category parent');
                }
                if ($cursor === $categoryId || isset($seen[$cursor]))
                {
                    throw new RuntimeException('Category cycle');
                }
                $seen[$cursor] = true;
            }
            $db->queryWrite('UPDATE gdo_badger_category SET bc_parent=' . GDO::quoteS($parentId) . ' WHERE bc_id=' . GDO::quoteS($categoryId));
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit category transaction');
            }
        }
        catch (Throwable $ex)
        {
            if ($ownsTransaction)
            {
                $db->transactionRollback();
            }
            throw $ex;
        }
    }
}
