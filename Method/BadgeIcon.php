<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Core\GDT;
use GDO\Core\GDT_UInt;
use GDO\Core\Method;
use GDO\File\GDO_File;
use GDO\File\Method\GetFile;
use GDO\User\GDO_User;

final class BadgeIcon extends Method
{
    public function isUserRequired(): bool { return false; }
    public function isSavingLastUrl(): bool { return false; }
    public function isTrivial(): bool { return false; }
    public function gdoParameters(): array { return [GDT_UInt::make('badge')->notNull()->min(1)]; }

    public function execute(): GDT
    {
        $id = BadgeBackground::fileId(GDO_User::current(), $this->gdoParameterVar('badge'), 'bb_icon');
        $file = $id ? GDO_File::getById($id) : null;
        if (!$file) { http_response_code(404); return $this->error('err_file_not_found', null, 404); }
        hdr('Cache-Control: private, no-store');
        return GetFile::make()->executeWithFile($file, null, true);
    }
}
