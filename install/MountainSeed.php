<?php
declare(strict_types=1);
namespace GDO\Badger\install;

/** Seed definitions are independent of the eventual hierarchy representation. */
final class MountainSeed
{
    public static function definitions(): array
    {
        $badges = [[
            'key' => 'mountaineer',
            'bb_name' => 'Bergsteiger',
            'bb_description' => 'Entdecke die Berge und werde Bergsteiger.',
            'bb_has_image' => '1',
            'bb_item_name' => 'Eispickel',
        ]];
        foreach ([1000, 2000, 3000, 4000] as $metres)
        {
            $badges[] = [
                'key' => 'mountaineer_' . $metres,
                'bb_name' => 'Bergsteiger ' . $metres . ' m',
                'bb_description' => 'Erreiche beim Bergsteigen eine Höhe von ' . $metres . ' Metern.',
                'bb_has_image' => '0',
            ];
        }
        return $badges;
    }
}
