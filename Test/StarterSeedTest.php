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
        self::assertSame(['Caretaker', 'Bookworm'], array_column($groups, 'name'));
        self::assertCount(8, $groups[0]['achievements']);
        self::assertCount(2, $groups[1]['achievements']);
        self::assertSame(['Golden WC Brush', 'Page-turning Book'], array_column($groups, 'item_name'));
        foreach ($groups as $group)
        {
            foreach ($group['achievements'] as $name) { self::assertLessThanOrEqual(64, mb_strlen($name)); }
            foreach (['icon', 'item'] as $asset)
            {
                $path = dirname(__DIR__) . '/install/data/' . $group[$asset];
                self::assertFileExists($path);
                self::assertSame('image/png', mime_content_type($path));
                self::assertNotFalse(getimagesize($path));
            }
        }
    }
}
