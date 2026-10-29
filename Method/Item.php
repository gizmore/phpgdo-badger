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

final class Item extends Method
{
    public function isUserRequired(): bool { return false; }
    public function isSavingLastUrl(): bool { return false; }
    public function isTrivial(): bool { return false; }
    public function gdoParameters(): array { return [GDT_UInt::make('achievement')->notNull()->min(1)]; }

    public function execute(): GDT
    {
        $fileId = self::fileId($this->gdoParameterVar('achievement'));
        $file = $fileId ? GDO_File::getById($fileId) : null;
        if (!$file)
        {
            return $this->error('err_file_not_found', null, 404);
        }
        hdr('Cache-Control: no-store');
        return GetFile::make()->executeWithFile($file, null, true);
    }

    public static function fileId(string $achievementId): ?string
    {
        $id = GDO::quoteS($achievementId);
        $row = Database::instance()->queryRead("SELECT b.bb_item FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge WHERE a.ba_id={$id} AND a.ba_status='claimed' AND a.ba_equipped=1 AND b.bb_item IS NOT NULL")->fetch_assoc();
        return $row['bb_item'] ?? null;
    }
}
