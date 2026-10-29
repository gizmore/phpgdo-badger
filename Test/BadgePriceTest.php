<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\Service\BadgePrice;
use GDO\Badger\Service\BadgeCreation;
use PHPUnit\Framework\TestCase;

final class BadgePriceTest extends TestCase
{
    public function testFirstXpIsFreeAndOtherPointsAreCharged(): void
    {
        $rates = ['xp' => 50, 'hp' => 50, 'str' => 50, 'int' => 50, 'image' => 50];
        self::assertSame(50, BadgePrice::calculate(50, $rates, ['xp' => 1], false));
        self::assertSame(50, BadgePrice::calculate(50, $rates, ['xp' => 0], false));
        self::assertSame(300, BadgePrice::calculate(50, $rates, ['xp' => 2, 'hp' => 1, 'str' => 1, 'int' => 1], true));
    }

    public function testRejectsNegativePoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BadgePrice::calculate(50, ['xp' => 50], ['xp' => -1], false);
    }

    public function testRejectsOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BadgePrice::calculate(50, ['hp' => 50], ['hp' => PHP_INT_MAX], false);
    }

    public function testRejectsImageCostOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BadgePrice::calculate(PHP_INT_MAX, ['image' => 1], [], true);
    }

    public function testCreationQuoteValidatesAndNormalizesStats(): void
    {
        $rates = ['xp' => 50, 'hp' => 50, 'str' => 50, 'int' => 50, 'image' => 50];
        self::assertSame(200, BadgeCreation::quote([
            'bb_xp' => '01', 'bb_hp' => '1', 'bb_str' => '1', 'bb_int' => '1', 'bb_has_image' => '0',
        ], 50, $rates));
        self::assertSame('1', BadgeCreation::normalize(['bb_xp' => '0001'])['bb_xp']);
        foreach (['-1', '1.5', '1e3', '4294967296'] as $invalid)
        {
            try
            {
                BadgeCreation::quote(['bb_xp' => $invalid], 50, $rates);
                self::fail('Invalid stat must be rejected: ' . $invalid);
            }
            catch (\InvalidArgumentException $ex)
            {
                self::assertNotSame('', $ex->getMessage());
            }
        }
    }
}
