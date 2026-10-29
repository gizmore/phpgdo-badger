<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Core\GDO;
use GDO\Core\GDT;
use GDO\Core\GDT_UInt;
use GDO\Core\Method;
use GDO\DB\Database;
use GDO\File\GDO_File;
use GDO\File\Method\GetFile;
use GDO\User\GDO_User;

final class AwardItem extends Method
{
    public function isGuestAllowed(): bool { return false; }
    public function isSavingLastUrl(): bool { return false; }
    public function isTrivial(): bool { return false; }
    public function gdoParameters(): array { return [GDT_UInt::make('achievement')->notNull()->min(1)]; }

    public function execute(): GDT
    {
        $actor = GDO_User::current();
        if (!$actor->isMember()) { http_response_code(404); return $this->error('err_file_not_found', null, 404); }
        $user = $actor->getID();
        $id = GDO::quoteS($this->gdoParameterVar('achievement'));
        $uid = GDO::quoteS($user ?? '0');
        $row = Database::instance()->queryRead("SELECT b.bb_item FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge WHERE a.ba_id={$id} AND a.ba_user={$uid} AND a.ba_status IN ('verified','claimed')")->fetch_assoc();
        $file = !empty($row['bb_item']) ? GDO_File::getById($row['bb_item']) : null;
        if (!$file) { http_response_code(404); return $this->error('err_file_not_found', null, 404); }
        hdr('Cache-Control: private, no-store');
        return GetFile::make()->executeWithFile($file, null, true);
    }
}
