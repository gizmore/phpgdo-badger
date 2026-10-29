<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\install\StarterSeed;
use PHPUnit\Framework\TestCase;

final class StarterSeedTest extends TestCase
{
    public function testStarterGroupsAndAssets(): void
    {
        $groups = StarterSeed::groups();
        self::assertSame(['Caretaker', 'Bookworm', 'CBA'], array_column($groups, 'name'));
        self::assertCount(8, $groups[0]['achievements']);
        self::assertCount(2, $groups[1]['achievements']);
        self::assertSame(['Spell your name backwards.', 'Learn midpoint M and halfway down.',
            'Reverse a three-letter word.', 'Learn from Z halfway down.', 'Memorize remaining errors.',
            'Recite the alphabet backwards.'],
            array_column($groups[2]['achievements'], 'name'));
        self::assertSame('Learn spelling backwards. Crazily useful in math and IT.', $groups[2]['description']);
        self::assertSame(['Golden WC Brush', 'Page-turning Book', 'Möbius Compass'], array_column($groups, 'item_name'));
        self::assertSame('Tesseract Lantern', $groups[2]['achievements'][4]['item_name']);
        foreach ($groups as $group)
        {
            $assets = [$group['icon'], $group['item']];
            foreach ($group['achievements'] as $achievement)
            {
                $entry = is_array($achievement) ? $achievement : ['name' => $achievement];
                self::assertLessThanOrEqual(64, mb_strlen($entry['name']));
                if (isset($entry['icon'])) { $assets[] = $entry['icon']; }
                if (isset($entry['item'])) { $assets[] = $entry['item']; }
            }
            foreach (array_unique($assets) as $asset)
            {
                $path = dirname(__DIR__) . '/install/data/' . $asset;
                self::assertFileExists($path);
                self::assertSame('image/png', mime_content_type($path));
                self::assertNotFalse(getimagesize($path));
            }
        }
    }
}
