<?php
declare(strict_types=1);
namespace GDO\Badger\install;

use RuntimeException;

/** A small transparent ice axe for the mountaineering badge. */
final class MountainItem
{
    public static function gif(): string
    {
        $image = imagecreatetruecolor(32, 32);
        if ($image === false) { throw new RuntimeException('Could not create mountaineering item'); }
        try
        {
            $transparent = imagecolorallocate($image, 255, 0, 255);
            imagefill($image, 0, 0, $transparent);
            imagecolortransparent($image, $transparent);
            $metal = imagecolorallocate($image, 194, 205, 213);
            $dark = imagecolorallocate($image, 56, 66, 74);
            $handle = imagecolorallocate($image, 201, 76, 36);
            imagesetthickness($image, 4);
            imageline($image, 10, 27, 23, 6, $dark);
            imagesetthickness($image, 2);
            imageline($image, 10, 27, 23, 6, $handle);
            imagesetthickness($image, 3);
            imageline($image, 16, 5, 27, 9, $metal);
            imageline($image, 16, 5, 12, 8, $metal);
            imageline($image, 27, 9, 29, 13, $metal);
            ob_start();
            try
            {
                if (!imagegif($image)) { throw new RuntimeException('Could not encode mountaineering item'); }
                return (string) ob_get_contents();
            }
            finally { ob_end_clean(); }
        }
        finally { imagedestroy($image); }
    }
}
