<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Badger\GDO_Badge;
use GDO\Badger\Module_Badger;

use GDO\DB\Database;
use GDO\User\GDO_User;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class BadgeCreation
{
    public static function normalize(array $values): array
    {
        foreach (['xp', 'hp', 'str', 'int'] as $stat)
        {
            $key = 'bb_' . $stat;
            $value = $values[$key] ?? ($stat === 'xp' ? '1' : '0');
            if (!is_string($value) || !ctype_digit($value))
            {
                throw new InvalidArgumentException('Invalid badge stat');
            }
            $normalized = ltrim($value, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            if (strlen($normalized) > 10 || (strlen($normalized) === 10 && strcmp($normalized, '4294967295') > 0))
            {
                throw new InvalidArgumentException('Badge stat exceeds column range');
            }
            $values[$key] = $normalized;
        }
        $image = $values['bb_has_image'] ?? '0';
        if (!in_array($image, ['0', '1'], true))
        {
            throw new InvalidArgumentException('Invalid image option');
        }
        $values['bb_has_image'] = $image;
        return $values;
    }

    public static function quote(array $values, int $base, array $rates): int
    {
        $values = self::normalize($values);
        $stats = [];
        foreach (['xp', 'hp', 'str', 'int'] as $stat)
        {
            $stats[$stat] = (int) $values['bb_' . $stat];
        }
        return BadgePrice::calculate($base, $rates, $stats, $values['bb_has_image'] === '1');
    }

    public static function create(GDO_User $owner, array $values, int $base, array $rates, int $welcome, bool $ownsTransaction = true): GDO_Badge
    {
        if (!$owner->isMember() || !$owner->getID())
        {
            throw new InvalidArgumentException('Invalid badge owner');
        }
        if (trim($values['bb_name'] ?? '') === '') { throw new InvalidArgumentException('Invalid badge name'); }
        $allowed = ['bb_name', 'bb_description', 'bb_category', 'bb_country', 'bb_visibility', 'bb_has_image', 'bb_xp', 'bb_hp', 'bb_str', 'bb_int'];
        $values = self::normalize(array_intersect_key($values, array_flip($allowed)));
        if (($values['bb_country'] ?? null) === '') { $values['bb_country'] = null; }
        $cost = self::quote($values, $base, $rates);
        $values['bb_owner'] = $owner->getID();
        $module = Module_Badger::instance();
        $staff = (string) ($module->getConfigVar('bb_default_verify_staff') ?? '1');
        $staffCount = (string) ($module->getConfigVar('bb_default_staff_approvals') ?? '1');
        $communityCount = (string) ($module->getConfigVar('bb_default_community_approvals') ?? '1');
        foreach ([$staffCount, $communityCount] as $count)
        {
            if (!ctype_digit($count) || (int) $count < 1 || strlen(ltrim($count, '0')) > 10 || (float) $count > 4294967295)
            {
                throw new InvalidArgumentException('Invalid approval threshold');
            }
        }
        if (!in_array($staff, ['0', '1'], true)) { throw new InvalidArgumentException('Invalid staff approval default'); }
        $values['bb_verify_staff'] = $staff;
        $values['bb_staff_approvals_needed'] = $staffCount;
        $values['bb_community_approvals_needed'] = $communityCount;
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin badge creation');
        }
        try
        {
            $ownerId = \GDO\Core\GDO::quoteS($owner->getID());
            $lockedOwner = $db->queryRead("SELECT user_type FROM gdo_user WHERE user_id={$ownerId} FOR UPDATE")->fetch_assoc();
            if (!$lockedOwner || $lockedOwner['user_type'] !== 'member')
            {
                throw new InvalidArgumentException('Invalid badge owner');
            }
            BadgeCredits::charge($owner, $cost, $welcome, false);
            $badge = GDO_Badge::blank($values)->insert();
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit badge creation');
            }
            return $badge;
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
