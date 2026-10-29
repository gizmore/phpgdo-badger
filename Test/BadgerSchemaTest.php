<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDO_Attest;
use GDO\Badger\GDO_Badge;
use GDO\Badger\GDO_Category;
use GDO\Badger\GDT_BadgeUserUnique;
use GDO\Badger\GDT_BadgeAction;
use GDO\Badger\GDT_BadgeTree;
use GDO\Badger\Method\Badges;
use GDO\Badger\Method\Achieve;
use GDO\Badger\Module_Badger;
use GDO\Badger\Method\CategoryTree;
use GDO\Badger\Method\BadgeCrud;
use GDO\Badger\GDT_BadgeEditAction;
use GDO\Core\GDT_Virtual;
use PHPUnit\Framework\TestCase;

final class BadgerSchemaTest extends TestCase
{
    public function testConfigurationLabelsExistInEnglishAndGerman(): void
    {
        $module = Module_Badger::instance();
        $fields = array_merge($module->getConfig(), $module->getUserConfig(), $module->getUserSettings());
        foreach (['en', 'de'] as $language)
        {
            $translations = require dirname(__DIR__) . '/lang/badger_' . $language . '.php';
            self::assertArrayHasKey('err_badger_staff_member', $translations);
            self::assertArrayHasKey('err_badger_badge_name', $translations);
            foreach ($fields as $field)
            {
                self::assertArrayHasKey($field->getName(), $translations, $language . ': ' . $field->getName());
            }
        }
    }

    public function testTablesAndVirtualTotals(): void
    {
        self::assertSame('gdo_badger_badge', GDO_Badge::table()->gdoTableName());
        $country = GDO_Badge::table()->gdoColumn('bb_country');
        self::assertInstanceOf(\GDO\Badger\GDT_BadgeCountry::class, $country);
        self::assertFalse($country->notNull);
        $country->var(null);
        self::assertSame(t('none'), $country->renderHTML());
        self::assertSame(t('none'), $country->renderCell());
        self::assertNull($country->getValueSingle(''));
        self::assertNull($country->inputToVar(''));
        self::assertSame('DE', $country->getValueSingle('DE')->getID());
        $country->var('??');
        self::assertFalse($country->validate(null));
        $country->var(null);
        self::assertSame('gdo_badger_category', GDO_Category::table()->gdoTableName());
        self::assertFalse(GDO_Category::table()->gdoColumn('bc_parent')->notNull);
        self::assertSame('gdo_badger_attest', GDO_Attest::table()->gdoTableName());
        $achieve = GDO_Achieve::table();
        self::assertSame('gdo_badger_achieve', $achieve->gdoTableName());
        self::assertInstanceOf(GDT_BadgeUserUnique::class, $achieve->gdoColumn('ba_badge_user_unique'));
        foreach (['xp', 'hp', 'str', 'int'] as $stat)
        {
            $field = $achieve->gdoColumn('ba_total_' . $stat);
            self::assertInstanceOf(GDT_Virtual::class, $field);
            self::assertStringContainsString("a.ba_status = 'claimed'", $field->subquery);
            self::assertStringContainsString('a.ba_user = gdo_badger_achieve.ba_user', $field->subquery);
        }
    }

    public function testAchievementDeletionIsBlocked(): void
    {
        $this->expectException(\LogicException::class);
        GDO_Achieve::table()->delete();
    }

    public function testAttestationMutationIsBlocked(): void
    {
        $this->expectException(\LogicException::class);
        GDO_Attest::table()->update();
    }

    public function testAttestationReplacementIsBlocked(): void
    {
        $this->expectException(\LogicException::class);
        GDO_Attest::table()->replace();
    }

    public function testBadgeOverviewContainsRequestAction(): void
    {
        $headers = Badges::make()->gdoHeaders();
        self::assertInstanceOf(GDT_BadgeAction::class, end($headers));
        self::assertInstanceOf(Badges::class, Module_Badger::instance()->defaultMethod());
    }

    public function testDisplayOnlyColumnsCannotBeFilteredOrSorted(): void
    {
        foreach ([\GDO\Badger\GDT_BadgeIcon::class, \GDO\Badger\GDT_BadgeAction::class,
            \GDO\Badger\GDT_BadgeEditAction::class, \GDO\Badger\GDT_AchievementAction::class] as $class)
        {
            $field = $class::make('display_column');
            self::assertFalse($field->isFilterable());
            self::assertFalse($field->isSearchable());
            self::assertFalse($field->isOrderable());
        }
        $tags = \GDO\Badger\GDT_BadgeTags::make('badge_tags');
        self::assertTrue($tags->isFilterable());
        self::assertFalse($tags->isOrderable());
        self::assertFalse($tags->isSearchable());
    }

    public function testAchievementSummaryEscapesBadgeName(): void
    {
        $badge = GDO_Badge::blank(['bb_name' => '<badge>']);
        $achievement = GDO_Achieve::blank(['ba_status' => 'pending']);
        $mode = \GDO\Core\Application::$MODE;
        try {
            \GDO\Core\Application::$MODE = \GDO\Core\GDT::RENDER_HTML;
            $summary = Achieve::summary($badge, $achievement);
        } finally { \GDO\Core\Application::$MODE = $mode; }
        self::assertStringContainsString('&lt;badge&gt;', $summary);
        self::assertStringNotContainsString('<badge>', $summary);
    }

    public function testCategoryManagementRequiresStaff(): void
    {
        self::assertSame('staff', CategoryTree::make()->getPermission());
        self::assertFalse(CategoryTree::make()->isGuestAllowed());
    }

    public function testBadgeModerationRequiresStaff(): void
    {
        $method = BadgeCrud::make();
        self::assertSame('staff', $method->getPermission());
        $headers = $method->gdoHeaders();
        self::assertInstanceOf(GDT_BadgeEditAction::class, end($headers));
    }

    public function testPersonalQueuesRequireMembers(): void
    {
        foreach ([\GDO\Badger\Method\MyAchievements::class, \GDO\Badger\Method\ReviewQueue::class, \GDO\Badger\Method\CommunityQueue::class] as $class)
        {
            $method = $class::make();
            self::assertTrue($method->isUserRequired());
            self::assertFalse($method->isGuestAllowed());
        }
    }

    public function testCategoryTreeEscapesNamesAndNestsChildren(): void
    {
        $root = GDO_Category::blank(['bc_id' => '1', 'bc_name' => '<Root>']);
        $child = GDO_Category::blank(['bc_id' => '2', 'bc_parent' => '1', 'bc_name' => 'Child']);
        $badge = GDO_Badge::blank(['bb_name' => 'Badge <1>', 'bb_category' => '2']);
        $mode = \GDO\Core\Application::$MODE;
        try {
            \GDO\Core\Application::$MODE = \GDO\Core\GDT::RENDER_HTML;
            $tree = GDT_BadgeTree::make()->categories([$root, $child])->badges([$badge])->renderHTML();
        } finally { \GDO\Core\Application::$MODE = $mode; }
        self::assertStringContainsString('&lt;Root&gt;<ul><li>Child<ul><li>Badge &lt;1&gt;</li></ul></li></ul>', $tree);
        self::assertStringNotContainsString('<Root>', $tree);
    }
}
