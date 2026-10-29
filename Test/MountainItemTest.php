<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

require_once dirname(__DIR__) . '/install/MountainItem.php';

use GDO\Badger\install\MountainItem;
use GDO\Badger\ItemImage;
use PHPUnit\Framework\TestCase;

final class MountainItemTest extends TestCase
{
    public function testTransparentGif(): void
    {
        $gif = MountainItem::gif();
        $info = getimagesizefromstring($gif);
        self::assertIsArray($info);
        self::assertSame(32, $info[0]);
        self::assertSame(32, $info[1]);
        self::assertSame(IMAGETYPE_GIF, $info[2]);
        $image = imagecreatefromstring($gif);
        self::assertNotFalse($image);
        self::assertGreaterThanOrEqual(0, imagecolortransparent($image));
        $transparent = imagecolortransparent($image);
        $opaquePixels = 0;
        for ($y = 0; $y < 32; $y++)
        {
            for ($x = 0; $x < 32; $x++)
            {
                if (imagecolorat($image, $x, $y) !== $transparent) { $opaquePixels++; }
            }
        }
        self::assertGreaterThan(0, $opaquePixels);
        self::assertLessThan(32 * 32, $opaquePixels);
        self::assertSame($gif, ItemImage::toGif($gif, 512, 512, 2097152));
        imagedestroy($image);
    }
}
