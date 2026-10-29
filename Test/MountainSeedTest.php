<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

require_once dirname(__DIR__) . '/install/MountainSeed.php';

use GDO\Badger\install\MountainSeed;
use PHPUnit\Framework\TestCase;

final class MountainSeedTest extends TestCase
{
    public function testRootAndFourAltitudeMilestones(): void
    {
        $badges = MountainSeed::definitions();
        self::assertCount(5, $badges);
        self::assertSame(['mountaineer', 'mountaineer_1000', 'mountaineer_2000', 'mountaineer_3000', 'mountaineer_4000'], array_column($badges, 'key'));
        self::assertSame('1', $badges[0]['bb_has_image']);
        self::assertSame('Eispickel', $badges[0]['bb_item_name']);
        self::assertSame(['0', '0', '0', '0'], array_column(array_slice($badges, 1), 'bb_has_image'));
    }
}
