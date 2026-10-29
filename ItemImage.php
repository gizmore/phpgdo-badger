<?php
declare(strict_types=1);
namespace GDO\Badger;

use InvalidArgumentException;
use RuntimeException;

final class ItemImage
{
    public static function toGif(string $bytes, int $maxWidth, int $maxHeight, int $maxBytes): string
    {
        if ($maxWidth < 1 || $maxHeight < 1 || $maxBytes < 1 || strlen($bytes) > $maxBytes)
        {
            throw new InvalidArgumentException('Image exceeds upload limits');
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_GIF, IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) ||
            $info[0] < 1 || $info[1] < 1 || $info[0] > $maxWidth || $info[1] > $maxHeight)
        {
            throw new InvalidArgumentException('Invalid image format or dimensions');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source)
        {
            throw new InvalidArgumentException('Image cannot be decoded');
        }
        if ($info[2] === IMAGETYPE_GIF)
        {
            return $bytes;
        }
        $output = imagecreatetruecolor($info[0], $info[1]);
        imagealphablending($output, false);
        imagesavealpha($output, true);
        imagecopy($output, $source, 0, 0, 0, 0, $info[0], $info[1]);
        imagetruecolortopalette($output, false, 255);
        $transparent = imagecolorallocate($output, 0, 0, 0);
        imagecolortransparent($output, $transparent);
        for ($y = 0; $y < $info[1]; $y++)
        {
            for ($x = 0; $x < $info[0]; $x++)
            {
                $rgba = imagecolorsforindex($source, imagecolorat($source, $x, $y));
                if ($rgba['alpha'] >= 64)
                {
                    imagesetpixel($output, $x, $y, $transparent);
                }
            }
        }
        ob_start();
        $ok = imagegif($output);
        $gif = ob_get_clean();
        if (!$ok || !is_string($gif))
        {
            throw new RuntimeException('GIF conversion failed');
        }
        return $gif;
    }
}
