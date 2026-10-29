<?php
declare(strict_types=1);
namespace GDO\Badger;

use InvalidArgumentException;

final class BadgePrice
{
    public static function calculate(int $base, array $rates, array $stats, bool $image): int
    {
        if ($base < 0)
        {
            throw new InvalidArgumentException('Negative badge cost');
        }
        $cost = $base;
        foreach (['xp', 'hp', 'str', 'int'] as $stat)
        {
            $points = $stats[$stat] ?? 0;
            $rate = $rates[$stat] ?? 0;
            if (!is_int($points) || !is_int($rate) || $points < 0 || $rate < 0)
            {
                throw new InvalidArgumentException('Invalid points or rate');
            }
            $charged = $stat === 'xp' ? max(0, $points - 1) : $points;
            if ($rate && $charged > intdiv(PHP_INT_MAX - $cost, $rate))
            {
                throw new InvalidArgumentException('Badge cost exceeds integer range');
            }
            $cost += $charged * $rate;
        }
        $imageRate = $rates['image'] ?? 0;
        if (!is_int($imageRate) || $imageRate < 0)
        {
            throw new InvalidArgumentException('Invalid image rate');
        }
        if ($image && $imageRate > PHP_INT_MAX - $cost)
        {
            throw new InvalidArgumentException('Badge cost exceeds integer range');
        }
        return $cost + ($image ? $imageRate : 0);
    }
}
