<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\Service\BadgerPermissions;
use GDO\Badger\GDO_Badge;
use GDO\Core\GDO;
use GDO\Core\GDT;
use GDO\Core\GDT_UInt;
use GDO\Core\Method;
use GDO\DB\Database;
use GDO\File\GDO_File;
use GDO\File\Method\GetFile;
use GDO\User\GDO_User;

final class BadgeBackground extends Method
{
    public function isUserRequired(): bool { return false; }
    public function isSavingLastUrl(): bool { return false; }
    public function isTrivial(): bool { return false; }
    public function gdoParameters(): array { return [GDT_UInt::make('badge')->notNull()->min(1)]; }

    public static function fileId(GDO_User $viewer, string $badgeId, string $column = 'bb_image'): ?string
    {
        if (!in_array($column, ['bb_image', 'bb_icon'], true)) { return null; }
        $id = GDO::quoteS($badgeId);
        $row = Database::instance()->queryRead("SELECT bb_id, bb_owner, bb_visibility, {$column} FROM gdo_badger_badge WHERE bb_id={$id}")->fetch_assoc();
        if (!$row || !$row[$column] || !$row['bb_owner'])
        {
            return null;
        }
        $owner = GDO_User::blank(['user_id' => $row['bb_owner']]);
        return BadgerPermissions::canViewBadge($viewer, $owner, GDO_Badge::entity($row)) ? $row[$column] : null;
    }

    public function execute(): GDT
    {
        $id = self::fileId(GDO_User::current(), $this->gdoParameterVar('badge'));
        $file = $id ? GDO_File::getById($id) : null;
        if (!$file)
        {
            http_response_code(404);
            return $this->error('err_file_not_found', null, 404);
        }
        hdr('Cache-Control: private, no-store');
        return GetFile::make()->executeWithFile($file, null, true);
    }
}
