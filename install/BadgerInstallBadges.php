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
            $assets = [$group['icon'], $group['item']];
            foreach ($group['achievements'] as $achievement)
            {
                if (is_array($achievement))
                {
                    if (isset($achievement['icon'])) { $assets[] = $achievement['icon']; }
                    if (isset($achievement['item'])) { $assets[] = $achievement['item']; }
                }
            }
            foreach (array_unique($assets) as $asset)
            {
                $path = __DIR__ . '/data/' . $asset;
                if (!is_file($path) || !is_readable($path) || !getimagesize($path))
                {
                    throw new RuntimeException('Missing or invalid Badger seed image');
                }
            }
        }
        foreach ($groups as $group)
        {
            $category = GDO_Category::blank(['bc_name' => $group['name'], 'bc_slug' => $group['slug']])->insert();
            $files = [];
            $fileId = static function (string $name) use (&$files): string {
                if (!isset($files[$name]))
                {
                    $files[$name] = GDO_File::fromPath($name, __DIR__ . '/data/' . $name)->insert()->getID();
                }
                return $files[$name];
            };
            $base = ['bb_owner' => $owner->getID(), 'bb_category' => $category->getID()];
            GDO_Badge::blank($base + [
                'bb_name' => $group['name'], 'bb_description' => $group['description'],
                'bb_icon' => $fileId($group['icon']), 'bb_item' => $fileId($group['item']), 'bb_item_name' => $group['item_name'],
                'bb_has_image' => '1', 'bb_item_x' => '0', 'bb_item_y' => '0',
                'bb_item_w' => '128', 'bb_item_h' => '128',
            ])->insert();
            foreach ($group['achievements'] as $achievement)
            {
                $entry = is_array($achievement) ? $achievement : ['name' => $achievement];
                $values = $base + ['bb_name' => $entry['name'], 'bb_description' => $entry['name'],
                    'bb_icon' => $fileId($entry['icon'] ?? $group['icon'])];
                if (isset($entry['item']))
                {
                    $values += ['bb_item' => $fileId($entry['item']), 'bb_item_name' => $entry['item_name'],
                        'bb_has_image' => '1', 'bb_item_x' => '0', 'bb_item_y' => '0',
                        'bb_item_w' => '128', 'bb_item_h' => '128'];
                }
                GDO_Badge::blank($values)->insert();
            }
        }
    }
}

