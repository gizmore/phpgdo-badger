<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\Service\ItemImage;
use GDO\Badger\GDT_ItemUpload;
use GDO\Form\GDT_Form;
use PHPUnit\Framework\TestCase;

final class ItemImageTest extends TestCase
{
    private function transparentPng(): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $red = imagecolorallocatealpha($image, 255, 0, 0, 0);
        $black = imagecolorallocatealpha($image, 0, 0, 0, 0);
        imagefill($image, 0, 0, $clear);
        imagesetpixel($image, 1, 1, $red);
        imagesetpixel($image, 1, 0, $black);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        return $bytes;
    }

    public function testConvertsPngAndPreservesTransparentPixel(): void
    {
        $gif = ItemImage::toGif($this->transparentPng(), 2, 2, 10000);
        self::assertSame(IMAGETYPE_GIF, getimagesizefromstring($gif)[2]);
        $image = imagecreatefromstring($gif);
        $transparent = imagecolortransparent($image);
        self::assertGreaterThanOrEqual(0, $transparent);
        self::assertSame($transparent, imagecolorat($image, 0, 0));
        self::assertNotSame($transparent, imagecolorat($image, 1, 1));
        self::assertNotSame($transparent, imagecolorat($image, 1, 0));
        self::assertSame($gif, ItemImage::toGif($gif, 2, 2, 10000));
    }

    public function testRejectsImageOutsideLimits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItemImage::toGif($this->transparentPng(), 1, 2, 10000);
    }

    public function testRejectsMalformedData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItemImage::toGif('not an image', 2, 2, 10000);
    }

    public function testConvertsJpegAndWebpToGif(): void
    {
        $source = imagecreatetruecolor(3, 2);
        $blue = imagecolorallocate($source, 20, 80, 200);
        imagefill($source, 0, 0, $blue);
        try
        {
            foreach (['imagejpeg', 'imagewebp'] as $encoder)
            {
                if (!function_exists($encoder)) { continue; }
                ob_start();
                try
                {
                    self::assertTrue($encoder($source));
                    $encoded = (string) ob_get_contents();
                }
                finally { ob_end_clean(); }
                $gif = ItemImage::toGif($encoded, 3, 2, 10000);
                $info = getimagesizefromstring($gif);
                self::assertSame(IMAGETYPE_GIF, $info[2]);
                self::assertSame([3, 2], [$info[0], $info[1]]);
                self::assertNotFalse(imagecreatefromstring($gif));
            }
        }
        finally { imagedestroy($source); }
    }

    public function testRejectsOversizedSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItemImage::toGif($this->transparentPng(), 2, 2, 1);
    }

    public function testMultipartIsOptInInBothFormTemplates(): void
    {
        $field = GDT_Form::make();
        self::assertSame('', $field->htmlEnctype());
        $field->multipart();
        foreach ([GDO_PATH . 'GDO/Form/tpl/form_html.php', GDO_PATH . 'GDO/Bootstrap5Theme/thm/bs5/Form/tpl/form_html.php'] as $template)
        {
            ob_start();
            include $template;
            $html = ob_get_clean();
            self::assertStringContainsString('enctype="multipart/form-data"', $html);
        }
        $field->multipart(false);
        self::assertSame('', $field->htmlEnctype());
    }

    public function testUploadFieldRejectsFileIdsAndNonHttpPaths(): void
    {
        $field = GDT_ItemUpload::make('item_upload')->inputs(['item_upload' => '123']);
        self::assertNull($field->getVar());
        self::assertFalse($field->validate('123'));
        $_FILES['item_upload'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__];
        try
        {
            self::assertNull($field->getVar());
            self::assertFalse($field->validate('uploaded'));
        }
        finally
        {
            unset($_FILES['item_upload']);
        }
    }
}
