<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class ItemEquipment
{
    public static function renderLayers(GDO_User $user, int $canvasWidth, int $canvasHeight): string
    {
        if (!$user->getID() || $canvasWidth < 1 || $canvasHeight < 1)
        {
            return '';
        }
        $uid = GDO::quoteS($user->getID());
        $rows = Database::instance()->queryRead("SELECT a.ba_id, b.bb_item_name, b.bb_item_x, b.bb_item_y, b.bb_item_w, b.bb_item_h FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge WHERE a.ba_user={$uid} AND a.ba_status='claimed' AND a.ba_equipped=1 AND b.bb_item IS NOT NULL ORDER BY a.ba_id");
        $layers = '';
        while ($row = $rows->fetch_assoc())
        {
            $x = (int) ($row['bb_item_x'] ?? 0) * 100 / $canvasWidth;
            $y = (int) ($row['bb_item_y'] ?? 0) * 100 / $canvasHeight;
            $width = $row['bb_item_w'] === null ? 100 : (int) $row['bb_item_w'] * 100 / $canvasWidth;
            $height = $row['bb_item_h'] === null ? 100 : (int) $row['bb_item_h'] * 100 / $canvasHeight;
            $style = sprintf('position:absolute;left:%.4F%%;top:%.4F%%;width:%.4F%%;height:%.4F%%;pointer-events:none', $x, $y, $width, $height);
            $url = href('Badger', 'Item', '&achievement=' . $row['ba_id']);
            $layers .= '<img class="badger-avatar-item" src="' . html($url) . '" alt="' . html($row['bb_item_name'] ?? '') . '" style="' . $style . '">';
        }
        if ($layers === '')
        {
            return '';
        }
        return '<style>@keyframes badger-avatar-reveal{from{transform:scale(1.15)}to{transform:scale(1)}}' .
            '@keyframes badger-item-reveal{from{opacity:0;transform:scale(.85)}to{opacity:1;transform:scale(1)}}' .
            '.gdo-avatar-layers{animation:badger-avatar-reveal .5s ease-out}.badger-avatar-item{animation:badger-item-reveal .7s ease-out}' .
            '@media(prefers-reduced-motion:reduce){.gdo-avatar-layers,.badger-avatar-item{animation:none}}</style>' . $layers;
    }

    public static function choices(GDO_User $user): array
    {
        if (!$user->getID())
        {
            return [];
        }
        $uid = GDO::quoteS($user->getID());
        $rows = Database::instance()->queryRead("SELECT a.ba_id, a.ba_equipped, b.bb_name FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge WHERE a.ba_user={$uid} AND a.ba_status='claimed' AND b.bb_item IS NOT NULL ORDER BY b.bb_name, a.ba_id");
        $choices = [];
        while ($row = $rows->fetch_assoc())
        {
            $choices[$row['ba_id']] = html($row['bb_name'] ?? '') . ($row['ba_equipped'] === '1' ? ' ✓' : '');
        }
        return $choices;
    }

    public static function set(GDO_User $user, string $achievementId, bool $equip, int $base, bool $ownsTransaction = true): void
    {
        if (!$user->getID() || !ctype_digit($user->getID()) || !ctype_digit($achievementId) || $base < 0)
        {
            throw new RuntimeException('Invalid equipment request');
        }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin equipment transaction');
        }
        try
        {
            $uid = GDO::quoteS($user->getID());
            if (!$db->queryRead("SELECT user_id FROM gdo_user WHERE user_id={$uid} FOR UPDATE")->fetch_assoc())
            {
                throw new RuntimeException('Account not found');
            }
            $config = $db->queryRead("SELECT uset_var FROM gdo_usersetting WHERE uset_user={$uid} AND uset_name='bb_add_max_equip' FOR UPDATE")->fetch_assoc();
            $extra = $config['uset_var'] ?? '0';
            $digits = is_string($extra) ? ltrim($extra, '0') : '';
            $maximum = (string) (PHP_INT_MAX - $base);
            if (!is_string($extra) || !ctype_digit($extra) || strlen($digits) > strlen($maximum) ||
                (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0))
            {
                throw new RuntimeException('Invalid equipment limit');
            }
            $limit = $base + (int) $extra;
            $rows = $db->queryRead("SELECT a.ba_id, a.ba_status, a.ba_equipped, b.bb_item FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge WHERE a.ba_user={$uid} FOR UPDATE");
            $target = null;
            $count = 0;
            while ($row = $rows->fetch_assoc())
            {
                if ($row['ba_status'] === 'claimed' && $row['ba_equipped'] === '1')
                {
                    $count++;
                }
                if ($row['ba_id'] === $achievementId)
                {
                    $target = $row;
                }
            }
            if (!$target || ($equip && ($target['ba_status'] !== 'claimed' || !$target['bb_item'])))
            {
                throw new RuntimeException('Item is not available');
            }
            if ($equip && $target['ba_equipped'] !== '1' && $count >= $limit)
            {
                throw new RuntimeException('Equipment limit reached');
            }
            $id = GDO::quoteS($achievementId);
            $db->queryWrite('UPDATE gdo_badger_achieve SET ba_equipped=' . ($equip ? '1' : '0') . " WHERE ba_id={$id} AND ba_user={$uid}");
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit equipment transaction');
            }
            GDO_Achieve::getById($achievementId)?->uncache();
        }
        catch (Throwable $ex)
        {
            if ($ownsTransaction)
            {
                $db->transactionRollback();
            }
            throw $ex;
        }
    }
}
