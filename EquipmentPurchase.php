<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\Core\GDO_Module;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class EquipmentPurchase
{
    public static function buy(GDO_User $user, int $price, int $welcome, bool $ownsTransaction = true): void
    {
        if (!$user->isMember() || !$user->getID() || $price < 0 || $welcome < 0) { throw new RuntimeException('Invalid purchase'); }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin()) { throw new RuntimeException('Could not begin slot purchase'); }
        try
        {
            $id = GDO::quoteS($user->getID());
            $account = $db->queryRead("SELECT user_type FROM gdo_user WHERE user_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$account || $account['user_type'] !== 'member') { throw new RuntimeException('Invalid purchase'); }
            BadgeCredits::charge($user, $price, $welcome, false);
            $row = $db->queryRead("SELECT uset_var FROM gdo_usersetting WHERE uset_user={$id} AND uset_name='bb_add_max_equip' FOR UPDATE")->fetch_assoc();
            $value = $row['uset_var'] ?? '0';
            $digits = is_string($value) ? ltrim($value, '0') : '';
            if (!is_string($value) || !ctype_digit($value) || strlen($digits) > 10 ||
                (strlen($digits) === 10 && strcmp($digits, '4294967294') > 0))
            {
                throw new RuntimeException('Invalid equipment limit');
            }
            $new = (string) ((int) $value + 1);
            if ($row)
            {
                $ok = $db->queryWrite("UPDATE gdo_usersetting SET uset_var='{$new}' WHERE uset_user={$id} AND uset_name='bb_add_max_equip'");
            }
            else
            {
                $ok = $db->queryWrite("INSERT INTO gdo_usersetting (uset_user, uset_name, uset_var) VALUES ({$id}, 'bb_add_max_equip', '{$new}')");
            }
            if (!$ok) { throw new RuntimeException('Could not update equipment limit'); }
            if ($ownsTransaction && !$db->transactionEnd()) { throw new RuntimeException('Could not commit slot purchase'); }
            $user->tempUnset(GDO_Module::SETTINGS_KEY);
        }
        catch (Throwable $ex)
        {
            if ($ownsTransaction) { $db->transactionRollback(); }
            $user->tempUnset(GDO_Module::SETTINGS_KEY);
            throw $ex;
        }
    }
}
