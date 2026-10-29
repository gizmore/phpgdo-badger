<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO_Module;
use GDO\DB\Database;
use GDO\User\GDO_User;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class BadgeCredits
{
    public static function charge(GDO_User $user, int $cost, int $welcome, bool $ownsTransaction = true): int
    {
        $id = $user->getID();
        if (!$id || !ctype_digit($id) || $cost < 0 || $welcome < 0)
        {
            throw new InvalidArgumentException('Invalid credit charge');
        }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin credit transaction');
        }
        try
        {
            $account = $db->queryRead("SELECT user_id FROM gdo_user WHERE user_id={$id} FOR UPDATE")->fetch_assoc();
            if (!$account)
            {
                throw new RuntimeException('Account does not exist');
            }
            $rows = $db->queryRead("SELECT uset_name, uset_var FROM gdo_usersetting WHERE uset_user={$id} AND uset_name IN ('credits','bb_welcome_granted') FOR UPDATE");
            $settings = [];
            while ($row = $rows->fetch_assoc())
            {
                $settings[$row['uset_name']] = $row['uset_var'];
            }
            $raw = $settings['credits'] ?? '0';
            $number = is_string($raw) ? ltrim($raw, '0') : '';
            $maximum = (string) PHP_INT_MAX;
            if (!is_string($raw) || !ctype_digit($raw) || strlen($number) > strlen($maximum) ||
                (strlen($number) === strlen($maximum) && strcmp($number, $maximum) > 0))
            {
                throw new RuntimeException('Invalid credit balance');
            }
            $balance = (int) $raw;
            $grant = !isset($settings['bb_welcome_granted']);
            if ($grant && $welcome > PHP_INT_MAX - $balance)
            {
                throw new RuntimeException('Credit balance exceeds integer range');
            }
            $balance += $grant ? $welcome : 0;
            if ($balance < $cost)
            {
                throw new RuntimeException('Insufficient credits');
            }
            $balance -= $cost;
            if (array_key_exists('credits', $settings))
            {
                $db->queryWrite("UPDATE gdo_usersetting SET uset_var='{$balance}' WHERE uset_user={$id} AND uset_name='credits'");
            }
            else
            {
                $db->queryWrite("INSERT INTO gdo_usersetting (uset_user, uset_name, uset_var) VALUES ({$id}, 'credits', '{$balance}')");
            }
            if ($grant)
            {
                $db->queryWrite("INSERT INTO gdo_usersetting (uset_user, uset_name, uset_var) VALUES ({$id}, 'bb_welcome_granted', '1')");
            }
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit credit transaction');
            }
            $user->tempUnset(GDO_Module::SETTINGS_KEY);
            return $balance;
        }
        catch (Throwable $ex)
        {
            if ($ownsTransaction)
            {
                $db->transactionRollback();
            }
            $user->tempUnset(GDO_Module::SETTINGS_KEY);
            throw $ex;
        }
    }
}
