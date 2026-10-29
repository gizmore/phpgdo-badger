<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\File\GDO_File;
use GDO\User\GDO_User;
use GDO\Util\FileUtil;
use RuntimeException;
use Throwable;

final class ItemStorage
{
    public static function save(GDO_User $actor, GDO_Badge $badge, string $gif, string $kind = 'item'): GDO_File
    {
        $columns = ['item' => 'bb_item', 'icon' => 'bb_icon', 'background' => 'bb_image'];
        if (!$actor->isMember() || !$actor->getID() || !$badge->getID() || !isset($columns[$kind]))
        {
            throw new RuntimeException('Permission denied');
        }
        $db = Database::instance();
        if (!$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin image transaction');
        }
        $temp = null;
        $file = null;
        $destinationCreated = false;
        $committed = false;
        try
        {
            $badgeId = GDO::quoteS($badge->getID());
            $row = $db->queryRead("SELECT bb_owner, bb_has_image FROM gdo_badger_badge WHERE bb_id={$badgeId} FOR UPDATE")->fetch_assoc();
            if (!$row || $row['bb_has_image'] !== '1')
            {
                throw new RuntimeException('Badge has no item option');
            }
            if ($row['bb_owner'] !== $actor->getID() && !$actor->isStaff() && !$actor->isAdmin())
            {
                $actorId = GDO::quoteS($actor->getID());
                $staff = $db->queryRead("SELECT ba_is_staff FROM gdo_badger_achieve WHERE ba_badge={$badgeId} AND ba_user={$actorId} FOR UPDATE")->fetch_assoc();
                if (!$staff || $staff['ba_is_staff'] !== '1')
                {
                    throw new RuntimeException('Permission denied');
                }
            }
            $temp = tempnam(sys_get_temp_dir(), 'badger-item-');
            if ($temp === false || file_put_contents($temp, $gif, LOCK_EX) !== strlen($gif))
            {
                throw new RuntimeException('Could not prepare image');
            }
            $file = GDO_File::fromPath('badge-' . $kind . '.gif', $temp);
            $file->query()->insert($file->gdoTableIdentifier())->values($file->getDirtyVars())->exec();
            $fileId = $db->insertId();
            if ($fileId < 1)
            {
                throw new RuntimeException('Could not allocate image ID');
            }
            $file->setVar('file_id', (string) $fileId);
            $file->setPersisted();
            FileUtil::createDir(GDO_File::filesDir());
            $destination = $file->getDestPath();
            $output = @fopen($destination, 'xb');
            if (!$output)
            {
                throw new RuntimeException('Could not create image destination');
            }
            $destinationCreated = true;
            try
            {
                if (fwrite($output, $gif) !== strlen($gif))
                {
                    throw new RuntimeException('Could not store image');
                }
            }
            finally
            {
                fclose($output);
            }
            unset($file->path);
            $badge->updateQuery()->set($columns[$kind], GDO::quoteS($file->getID()))->exec();
            if (!$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit image transaction');
            }
            $committed = true;
            $file->cache();
            $badge->uncache();
            return $file;
        }
        catch (Throwable $ex)
        {
            if (!$committed)
            {
                $db->transactionRollback();
            }
            if (!$committed && $destinationCreated && $file && $file->getID() && ctype_digit($file->getID()))
            {
                $destination = $file->getDestPath();
                if (is_file($destination))
                {
                    unlink($destination);
                }
            }
            throw $ex;
        }
        finally
        {
            if (is_string($temp) && is_file($temp))
            {
                unlink($temp);
            }
        }
    }
}
