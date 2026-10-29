<?php
declare(strict_types=1);
namespace GDO\Badger\install;

use GDO\Badger\GDO_Badge;
use GDO\Badger\GDO_Category;
use GDO\File\GDO_File;
use GDO\User\GDO_User;
use RuntimeException;

final class BadgerInstallBadges
{
    public static function install(GDO_User $owner): void
    {
        if (!$owner->getID()) { throw new RuntimeException('Missing Badger seed owner'); }
        $groups = StarterSeed::groups();
        foreach ($groups as $group)
        {
            if (GDO_Category::getBy('bc_slug', $group['slug']))
            {
                throw new RuntimeException('Badger seed category already exists');
            }
            foreach (['icon', 'item'] as $asset)
            {
                $path = __DIR__ . '/data/' . $group[$asset];
                if (!is_file($path) || !is_readable($path) || !getimagesize($path))
                {
                    throw new RuntimeException('Missing or invalid Badger seed image');
                }
            }
        }
        foreach ($groups as $group)
        {
            $category = GDO_Category::blank(['bc_name' => $group['name'], 'bc_slug' => $group['slug']])->insert();
            $icon = GDO_File::fromPath($group['icon'], __DIR__ . '/data/' . $group['icon'])->insert();
            $item = GDO_File::fromPath($group['item'], __DIR__ . '/data/' . $group['item'])->insert();
            $base = ['bb_owner' => $owner->getID(), 'bb_category' => $category->getID(), 'bb_icon' => $icon->getID()];
            GDO_Badge::blank($base + [
                'bb_name' => $group['name'], 'bb_description' => $group['description'],
                'bb_item' => $item->getID(), 'bb_item_name' => $group['item_name'],
                'bb_has_image' => '1', 'bb_item_x' => '0', 'bb_item_y' => '0',
                'bb_item_w' => '128', 'bb_item_h' => '128',
            ])->insert();
            foreach ($group['achievements'] as $name)
            {
                GDO_Badge::blank($base + ['bb_name' => $name, 'bb_description' => $name])->insert();
            }
        }
    }
}

