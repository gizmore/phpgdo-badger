<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDT;

final class GDT_BadgeTree extends GDT
{
    private array $categories = [];
    private array $badges = [];

    public function categories(array $categories): static
    {
        $this->categories = $categories;
        return $this;
    }

    public function badges(array $badges): static
    {
        $this->badges = $badges;
        return $this;
    }

    public function renderHTML(): string
    {
        $children = [];
        foreach ($this->categories as $category)
        {
            if ($category instanceof GDO_Category && $category->getID() !== null)
            {
                $children[$category->gdoVar('bc_parent') ?? ''][] = $category;
            }
        }
        $visited = [];
        $badges = [];
        foreach ($this->badges as $badge)
        {
            if ($badge instanceof GDO_Badge)
            {
                $badges[$badge->gdoVar('bb_category') ?? ''][] = $badge;
            }
        }
        return $this->renderBranch('', $children, $badges, $visited);
    }

    private function renderBranch(string $parent, array $children, array $badges, array &$visited): string
    {
        if (empty($children[$parent]) && empty($badges[$parent]))
        {
            return '';
        }
        $result = '<ul>';
        foreach ($children[$parent] ?? [] as $category)
        {
            $id = $category->getID();
            if (isset($visited[$id]))
            {
                continue;
            }
            $visited[$id] = true;
            $result .= '<li>' . html($category->gdoVar('bc_name') ?? '') . $this->renderBranch($id, $children, $badges, $visited) . '</li>';
        }
        foreach ($badges[$parent] ?? [] as $badge)
        {
            $result .= '<li>' . html($badge->gdoVar('bb_name') ?? '') . '</li>';
        }
        return $result . '</ul>';
    }
}
