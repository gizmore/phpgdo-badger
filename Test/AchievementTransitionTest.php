<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\AchievementTransition;
use GDO\Badger\AchievementActions;
use GDO\Badger\AchievementRequests;
use GDO\Badger\AchievementHistory;
use GDO\Badger\AchievementSharing;
use GDO\Badger\ItemStorage;
use GDO\Badger\ItemEquipment;
use GDO\Badger\ProfileAchievements;
use GDO\Badger\CommunityApproval;
use GDO\Badger\EvidenceAttachments;
use GDO\Badger\EvidenceVault;
use GDO\Badger\AchievementDeletion;
use GDO\Badger\BadgerPermissions;
use GDO\Badger\BadgeStaff;
use GDO\Badger\BadgeCredits;
use GDO\Badger\BadgeCreation;
use GDO\Badger\BadgeListing;
use GDO\Badger\CategoryTree;
use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDO_Badge;
use GDO\Badger\GDT_BadgeUserUnique;
use GDO\Badger\GDT_BadgeAction;
use GDO\Badger\Method\Achieve;
use GDO\Badger\Method\Add;
use GDO\Badger\Method\Image;
use GDO\Badger\Method\Item;
use GDO\Badger\Method\Items;
use GDO\Badger\Method\CommunityQueue;
use GDO\Badger\Method\BadgeBackground;
use GDO\File\GDO_File;
use GDO\Badger\Method\Edit;
use GDO\Badger\Method\MyAchievements;
use GDO\Badger\Method\ReviewQueue;
use GDO\Badger\Method\Rankings;
use GDO\Core\Application;
use GDO\Core\Logger;
use GDO\DB\Database;
use GDO\User\GDO_User;
use PHPUnit\Framework\TestCase;

final class FailingQueueProvider implements \GDO\Badger\AIReviewProvider
{
    public static int $calls = 0;
    public function name(): string { return 'failing-test-provider'; }
    public function review(array $request, ?string $document): array
    {
        self::$calls++;
        throw new \RuntimeException('simulated provider error');
    }
}

final class AchievementTransitionTest extends TestCase
{
    private int $originalRenderMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRenderMode = Application::$MODE;
    }

    protected function tearDown(): void
    {
        Application::$MODE = $this->originalRenderMode;
        parent::tearDown();
    }

    public function testTransitionWritesAuditAndRejectsStaleUpdate(): void
    {
        Application::init();
        Logger::init(null, 0);
        $db = Database::init();
        $fairRows = $db->queryRead('SELECT a.ba_id FROM (SELECT 1 AS ba_id UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12) a ORDER BY ' . \GDO\Badger\AIReviewQueue::orderBy(10));
        $fairIds = [];
        while ($fairRow = $fairRows->fetch_assoc()) { $fairIds[] = (int) $fairRow['ba_id']; }
        self::assertSame([11, 12, 1, 10], $fairIds);
        $schema = Database::DBMS()->dbmsCreateTableCode(GDO_Achieve::table());
        self::assertStringContainsString('UNIQUE (ba_badge, ba_user)', $schema);
        self::assertStringContainsString('FOREIGN KEY (ba_badge) REFERENCES gdo_badger_badge(bb_id) ON DELETE RESTRICT', $schema);
        self::assertStringContainsString('FOREIGN KEY (ba_user) REFERENCES gdo_user(user_id) ON DELETE RESTRICT', $schema);
        $badgeSchema = Database::DBMS()->dbmsCreateTableCode(GDO_Badge::table());
        self::assertStringContainsString('FOREIGN KEY (bb_item) REFERENCES gdo_file(file_id) ON DELETE SET NULL', $badgeSchema);
        self::assertStringNotContainsString('UNIQUE(bb_name)', $badgeSchema);
        $db->queryWrite("CREATE TEMPORARY TABLE gdo_badger_badge (bb_id INT AUTO_INCREMENT PRIMARY KEY, bb_owner INT, bb_name VARCHAR(64), bb_description TEXT, bb_category INT, bb_image INT, bb_icon INT, bb_item INT, bb_has_image TINYINT DEFAULT 0, bb_item_name VARCHAR(255), bb_item_x INT, bb_item_y INT, bb_item_w INT, bb_item_h INT, bb_active TINYINT DEFAULT 1, bb_visibility VARCHAR(32) DEFAULT 'acl_all', bb_xp INT, bb_hp INT, bb_str INT, bb_int INT, bb_verify_staff TINYINT DEFAULT 1, bb_verify_cc TINYINT DEFAULT 0, bb_verify_doc TINYINT DEFAULT 0, bb_verify_ai TEXT, bb_verify_ai_doc TEXT, bb_approve_ai TINYINT DEFAULT 0, bb_staff_approvals_needed INT DEFAULT 1, bb_community_approvals_needed INT DEFAULT 1) ENGINE=InnoDB AUTO_INCREMENT=100");
        $db->queryWrite('INSERT INTO gdo_badger_badge (bb_id, bb_owner, bb_xp, bb_hp, bb_str, bb_int) VALUES (7, 11, 9, 2, 3, 4)');
        $db->queryWrite("CREATE TEMPORARY TABLE gdo_user (user_id INT PRIMARY KEY, user_name VARCHAR(64), user_type VARCHAR(32) DEFAULT 'member') ENGINE=InnoDB");
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (12, 'ranked-recipient'), (20, 'ranked-viewer')");
        $db->queryWrite('CREATE TEMPORARY TABLE gdo_usersetting (uset_user INT NOT NULL, uset_name VARCHAR(128) NOT NULL, uset_var VARCHAR(255), PRIMARY KEY (uset_user, uset_name)) ENGINE=InnoDB');
        $db->queryWrite('CREATE TEMPORARY TABLE gdo_badger_category (bc_id INT AUTO_INCREMENT PRIMARY KEY, bc_parent INT NULL, bc_name VARCHAR(128), bc_slug VARCHAR(128), bc_sort INT DEFAULT 0, bc_created DATETIME, bc_edited DATETIME NULL) ENGINE=InnoDB');
        $db->queryWrite('CREATE TEMPORARY TABLE gdo_badger_achieve (ba_id INT AUTO_INCREMENT PRIMARY KEY, ba_badge INT, ba_user INT, ba_is_staff TINYINT DEFAULT 0, ba_equipped TINYINT DEFAULT 0, ba_round INT NOT NULL DEFAULT 0, ba_staff_approvals INT NOT NULL DEFAULT 0, ba_community_approvals INT NOT NULL DEFAULT 0, ba_status VARCHAR(32), ba_deletion VARCHAR(32), ba_visibility VARCHAR(32), ba_created DATETIME, ba_submitted DATETIME, ba_verified DATETIME, ba_claimed DATETIME, ba_revoked DATETIME, UNIQUE (ba_badge, ba_user)) ENGINE=InnoDB');
        $db->queryWrite('CREATE TEMPORARY TABLE gdo_badger_attest (bat_id INT AUTO_INCREMENT PRIMARY KEY, bat_achieve INT, bat_actor_type VARCHAR(32), bat_actor INT NULL, bat_decision VARCHAR(32), bat_round INT NOT NULL DEFAULT 0, bat_reason TEXT NULL, bat_metadata TEXT NULL, bat_from_status VARCHAR(32), bat_to_status VARCHAR(32), bat_created DATETIME) ENGINE=InnoDB');
        $db->queryWrite('CREATE TEMPORARY TABLE gdo_badger_evidence (be_id INT AUTO_INCREMENT PRIMARY KEY, be_achieve INT NOT NULL, be_round INT NOT NULL, be_key VARCHAR(64) NOT NULL, be_sha256 VARCHAR(64) NOT NULL, be_mime VARCHAR(128) NOT NULL, be_size INT NOT NULL, be_deleted TINYINT DEFAULT 0, be_created DATETIME) ENGINE=InnoDB');
        // Exercise tag filtering even when connection and table collations differ.
        $db->queryWrite('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db->queryWrite('CREATE TEMPORARY TABLE gdo_badger_tag (bt_badge INT NOT NULL, bt_name VARCHAR(28) NOT NULL, PRIMARY KEY (bt_badge, bt_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $db->queryWrite('CREATE TEMPORARY TABLE gdo_badger_review_notice (bn_attest INT NOT NULL, bn_user INT NOT NULL, bn_delivered TINYINT DEFAULT 0, bn_created DATETIME, PRIMARY KEY (bn_attest, bn_user)) ENGINE=InnoDB');
        $db->queryWrite('CREATE TEMPORARY TABLE gdo_modulevar (mv_module INT NOT NULL, mv_name VARCHAR(128) NOT NULL, mv_value VARCHAR(255), PRIMARY KEY (mv_module, mv_name)) ENGINE=InnoDB');
        foreach ([
            ["x' OR 1=1 --", 'ordinary text', '0'],
            ['100%', '100% complete', '1'],
            ['100%', '100X complete', '0'],
            ['under_score', 'underXscore', '0'],
            ['one\\two', 'one\\two', '1'],
        ] as [$needle, $haystack, $expected])
        {
            $literal = \GDO\Core\GDO::quoteS($haystack);
            $escaped = \GDO\Core\GDO::escapeSearchS($needle);
            $match = $db->queryRead("SELECT {$literal} LIKE '%{$escaped}%' AS matched")->fetch_assoc();
            self::assertSame($expected, $match['matched']);
        }
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name, user_type) VALUES (451, 'welcome-test', 'member')");
        $welcomeUser = GDO_User::blank(['user_id' => '451', 'user_type' => 'member']);
        \GDO\Badger\Module_Badger::instance()->hookUserActivated($welcomeUser);
        \GDO\Badger\Module_Badger::instance()->hookUserActivated($welcomeUser);
        $welcomeBalance = $db->queryRead("SELECT uset_var FROM gdo_usersetting WHERE uset_user=451 AND uset_name='credits'")->fetch_assoc();
        self::assertSame('200', $welcomeBalance['uset_var']);
        self::assertSame(150, BadgeCredits::charge($welcomeUser, 50, 200));
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name, user_type) VALUES (452, 'welcome-rollback-test', 'member')");
        $rollbackUser = GDO_User::blank(['user_id' => '452', 'user_type' => 'member']);
        $previousMethod = $GLOBALS['me'] ?? null;
        $GLOBALS['me'] = new class extends \GDO\Core\Method {
            public function transactional(): bool { return true; }
            public function execute(): \GDO\Core\GDT { return \GDO\Core\GDT_Response::make(); }
        };
        try
        {
            self::assertTrue($db->transactionBegin());
            \GDO\Badger\Module_Badger::instance()->hookUserActivated($rollbackUser);
            self::assertSame('200', $db->queryRead("SELECT uset_var FROM gdo_usersetting WHERE uset_user=452 AND uset_name='credits'")->fetch_assoc()['uset_var']);
            self::assertTrue($db->transactionRollback());
        }
        finally { $GLOBALS['me'] = $previousMethod; }
        self::assertSame('0', $db->queryRead("SELECT COUNT(*) AS n FROM gdo_usersetting WHERE uset_user=452 AND uset_name IN ('credits','bb_welcome_granted')")->fetch_assoc()['n']);
        $db->queryWrite('CREATE TEMPORARY TABLE gdo_pm (pm_id INT AUTO_INCREMENT PRIMARY KEY, pm_sent_at DATETIME, pm_deleted_at DATETIME, pm_read_at DATETIME, pm_owner INT, pm_from INT, pm_to INT, pm_folder INT, pm_parent INT, pm_other INT, pm_title VARCHAR(255), pm_message_input TEXT, pm_message_output TEXT, pm_message_text TEXT, pm_message_editor VARCHAR(32), pm_other_read_at DATETIME, pm_other_deleted_at DATETIME) ENGINE=InnoDB');
        self::assertSame(['Hiking', 'Art'], \GDO\Badger\BadgeTags::parse('hiking, Hiking, Art'));
        self::assertTrue($db->transactionBegin());
        \GDO\Badger\BadgeTags::replace('7', ['Hiking', 'Art']);
        self::assertSame(['Art', 'Hiking'], \GDO\Badger\BadgeTags::names('7'));
        $tagCell = \GDO\Badger\GDT_BadgeTags::make('badge_tags')->gdo(GDO_Badge::blank(['bb_id' => '7']));
        self::assertSame('Art, Hiking', $tagCell->renderCell());
        \GDO\Badger\BadgeTags::replace('7', ['Art']);
        self::assertSame(['Art'], \GDO\Badger\BadgeTags::names('7'));
        self::assertTrue($db->transactionRollback());
        self::assertSame([], \GDO\Badger\BadgeTags::names('7'));
        $db->queryWrite("INSERT INTO gdo_badger_badge (bb_id, bb_name, bb_owner, bb_visibility) VALUES (901, 'Public tag test', 11, 'acl_all'), (902, 'Private tag test', 11, 'acl_noone')");
        $db->queryWrite("INSERT INTO gdo_badger_tag (bt_badge, bt_name) VALUES (901, 'RareWord'), (902, 'RareWord')");
        $guest = GDO_User::blank(['user_type' => 'ghost']);
        $tagQuery = \GDO\Badger\BadgeListing::query($guest);
        $tagFilter = \GDO\Table\GDT_Filter::make()->inputs(['f' => ['badge_tags' => 'rareword']]);
        \GDO\Badger\GDT_BadgeTags::make('badge_tags')->filterQuery($tagQuery, $tagFilter);
        $tagRows = $tagQuery->exec();
        $matched = [];
        while ($row = $tagRows->fetchAssoc()) { $matched[] = $row['bb_id']; }
        self::assertSame(['901'], $matched);
        $tagQuery = \GDO\Badger\BadgeListing::query($guest);
        $tagFilter = \GDO\Table\GDT_Filter::make()->inputs(['f' => ['badge_tags' => "x' OR 1=1 --"]]);
        \GDO\Badger\GDT_BadgeTags::make('badge_tags')->filterQuery($tagQuery, $tagFilter);
        self::assertFalse((bool) $tagQuery->exec()->fetchAssoc());
        $tagQuery = \GDO\Badger\BadgeListing::query($guest);
        $tagFilter = \GDO\Table\GDT_Filter::make()->inputs(['f' => ['badge_tags' => ['not-a-string']]]);
        \GDO\Badger\GDT_BadgeTags::make('badge_tags')->filterQuery($tagQuery, $tagFilter);
        $matched = [];
        $tagRows = $tagQuery->exec();
        while ($row = $tagRows->fetchAssoc()) { $matched[] = $row['bb_id']; }
        self::assertContains('901', $matched);
        self::assertNotContains('902', $matched);
        $db->queryWrite('DELETE FROM gdo_badger_tag WHERE bt_badge IN (901,902)');
        $db->queryWrite('DELETE FROM gdo_badger_badge WHERE bb_id IN (901,902)');
        $badgeHeaders = \GDO\Badger\Method\Badges::make()->gdoHeaderCache();
        self::assertInstanceOf(\GDO\Badger\GDT_BadgeTags::class, $badgeHeaders['badge_tags']);
        self::assertInstanceOf(GDT_BadgeAction::class, $badgeHeaders['bb_id']);
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_status) VALUES (1, 'pending')");
        $achievement = GDO_Achieve::blank(['ba_id' => '1', 'ba_status' => 'pending']);
        AchievementTransition::apply($achievement, 'verified', 'system', null);
        $row = $db->queryRead('SELECT ba_status, ba_verified FROM gdo_badger_achieve WHERE ba_id=1')->fetch_assoc();
        self::assertSame('verified', $row['ba_status']);
        self::assertNotNull($row['ba_verified']);
        $audit = $db->queryRead('SELECT bat_from_status, bat_to_status FROM gdo_badger_attest')->fetch_assoc();
        self::assertSame(['bat_from_status' => 'pending', 'bat_to_status' => 'verified'], $audit);
        try
        {
            AchievementTransition::apply($achievement, 'rejected', 'system', null);
            self::fail('A stale transition must fail');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Achievement changed concurrently', $ex->getMessage());
        }
        $count = $db->queryRead('SELECT COUNT(*) AS n FROM gdo_badger_attest')->fetch_assoc();
        self::assertSame('1', $count['n']);

        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_status) VALUES (2, 'pending')");
        self::assertTrue($db->transactionBegin());
        $second = GDO_Achieve::blank(['ba_id' => '2', 'ba_status' => 'pending']);
        AchievementTransition::apply($second, 'verified', 'system', null, null, false);
        self::assertTrue($db->transactionRollback());
        $row = $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=2')->fetch_assoc();
        self::assertSame('pending', $row['ba_status']);
        $count = $db->queryRead('SELECT COUNT(*) AS n FROM gdo_badger_attest WHERE bat_achieve=2')->fetch_assoc();
        self::assertSame('0', $count['n']);
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_is_staff, ba_status) VALUES (3, 7, 11, 1, 'viewed')");
        self::assertTrue(BadgerPermissions::isBadgeStaff('7', '11'));
        self::assertFalse(BadgerPermissions::isBadgeStaff('8', '11'));
        self::assertFalse(BadgerPermissions::isBadgeStaff('7', '12'));
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (11, 'reviewer')");
        $walletUser = GDO_User::blank(['user_id' => '11']);
        self::assertSame(150, BadgeCredits::charge($walletUser, 50, 200));
        self::assertSame(100, BadgeCredits::charge($walletUser, 50, 200));
        try
        {
            BadgeCredits::charge($walletUser, 101, 200);
            self::fail('Insufficient credits must fail');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Insufficient credits', $ex->getMessage());
        }
        self::assertTrue($db->transactionBegin());
        self::assertSame(50, BadgeCredits::charge($walletUser, 50, 200, false));
        self::assertTrue($db->transactionRollback());
        $balance = $db->queryRead("SELECT uset_var FROM gdo_usersetting WHERE uset_user=11 AND uset_name='credits'")->fetch_assoc();
        self::assertSame('100', $balance['uset_var']);
        $walletUser->setVar('user_type', 'member');
        $rates = ['xp' => 50, 'hp' => 50, 'str' => 50, 'int' => 50, 'image' => 50];
        $module = \GDO\Badger\Module_Badger::instance();
        $config = [];
        foreach (['bb_default_verify_staff' => '0', 'bb_default_staff_approvals' => '2', 'bb_default_community_approvals' => '3'] as $key => $value)
        {
            $field = $module->getConfigColumn($key);
            $config[$key] = $field->getVar();
            $field->var($value);
        }
        try { $created = BadgeCreation::create($walletUser, ['bb_name' => 'Badge test'], 50, $rates, 200); }
        finally { foreach ($config as $key => $value) { $module->getConfigColumn($key)->var($value); } }
        self::assertSame('Badge test', $created->gdoVar('bb_name'));
        self::assertSame('11', $created->gdoVar('bb_owner'));
        self::assertSame('0', $created->gdoVar('bb_verify_staff'));
        self::assertSame('2', $created->gdoVar('bb_staff_approvals_needed'));
        self::assertSame('3', $created->gdoVar('bb_community_approvals_needed'));
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status, ba_round) VALUES (450, " . (int) $created->getID() . ", 11, 'viewed', 0)");
        $automatic = GDO_Achieve::blank(['ba_id' => '450', 'ba_badge' => $created->getID(), 'ba_user' => '11', 'ba_status' => 'viewed', 'ba_round' => '0']);
        AchievementActions::transition($walletUser, $created, $automatic, 'pending');
        $autoState = $db->queryRead('SELECT ba_status, ba_round, ba_claimed FROM gdo_badger_achieve WHERE ba_id=450')->fetch_assoc();
        self::assertSame('verified', $autoState['ba_status']);
        self::assertSame('1', $autoState['ba_round']);
        self::assertNull($autoState['ba_claimed']);
        $autoAudit = $db->queryRead('SELECT bat_to_status FROM gdo_badger_attest WHERE bat_achieve=450 ORDER BY bat_id')->fetch_all(MYSQLI_ASSOC);
        self::assertSame([['bat_to_status' => 'pending'], ['bat_to_status' => 'verified']], $autoAudit);
        $priorUser = GDO_User::current();
        GDO_User::setCurrent($walletUser);
        try
        {
            $configure = Edit::make()->inputs(['badge' => $created->getID()]);
            $configureForm = $configure->getForm();
            $configureForm->getField('bb_verify_staff')->var('1');
            $configure->formValidated($configureForm);
            self::assertSame('1', $db->queryRead('SELECT bb_verify_staff FROM gdo_badger_badge WHERE bb_id=' . (int) $created->getID())->fetch_assoc()['bb_verify_staff']);
        }
        finally { GDO_User::setCurrent($priorUser); }
        $balance = $db->queryRead("SELECT uset_var FROM gdo_usersetting WHERE uset_user=11 AND uset_name='credits'")->fetch_assoc();
        self::assertSame('50', $balance['uset_var']);
        self::assertTrue($db->transactionBegin());
        BadgeCreation::create($walletUser, ['bb_name' => 'Rolled back'], 50, $rates, 200, false);
        self::assertTrue($db->transactionRollback());
        $balance = $db->queryRead("SELECT uset_var FROM gdo_usersetting WHERE uset_user=11 AND uset_name='credits'")->fetch_assoc();
        self::assertSame('50', $balance['uset_var']);
        $rolledBack = $db->queryRead("SELECT COUNT(*) AS n FROM gdo_badger_badge WHERE bb_name='Rolled back'")->fetch_assoc();
        self::assertSame('0', $rolledBack['n']);
        $beforeBadInsert = $db->queryRead("SELECT COUNT(*) AS n FROM gdo_badger_badge")->fetch_assoc()['n'];
        try
        {
            BadgeCreation::create($walletUser, ['bb_name' => str_repeat('x', 65)], 50, $rates, 200);
            self::fail('A database insertion failure must roll back the debit');
        }
        catch (\GDO\Core\GDO_DBException $ex) { self::assertNotNull($ex); }
        self::assertSame('50', $db->queryRead("SELECT uset_var FROM gdo_usersetting WHERE uset_user=11 AND uset_name='credits'")->fetch_assoc()['uset_var']);
        self::assertSame($beforeBadInsert, $db->queryRead('SELECT COUNT(*) AS n FROM gdo_badger_badge')->fetch_assoc()['n']);
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name, user_type) VALUES (453, 'failed-first-purchase', 'member')");
        $newWallet = GDO_User::blank(['user_id' => '453', 'user_type' => 'member']);
        try
        {
            BadgeCreation::create($newWallet, ['bb_name' => str_repeat('y', 65)], 50, $rates, 200);
            self::fail('A failed first purchase must roll back the welcome grant');
        }
        catch (\GDO\Core\GDO_DBException $ex) { self::assertNotNull($ex); }
        self::assertSame('0', $db->queryRead("SELECT COUNT(*) AS n FROM gdo_usersetting WHERE uset_user=453 AND uset_name IN ('credits','bb_welcome_granted')")->fetch_assoc()['n']);
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name, user_type) VALUES (454, 'deactivated-purchase', 'ghost')");
        $staleMember = GDO_User::blank(['user_id' => '454', 'user_type' => 'member']);
        try
        {
            BadgeCreation::create($staleMember, ['bb_name' => 'Stale owner'], 50, $rates, 200);
            self::fail('A deactivated owner must not create a badge');
        }
        catch (\InvalidArgumentException $ex) { self::assertSame('Invalid badge owner', $ex->getMessage()); }
        self::assertSame('0', $db->queryRead("SELECT COUNT(*) AS n FROM gdo_usersetting WHERE uset_user=454 AND uset_name IN ('credits','bb_welcome_granted')")->fetch_assoc()['n']);
        self::assertSame('0', $db->queryRead("SELECT COUNT(*) AS n FROM gdo_badger_badge WHERE bb_name='Stale owner'")->fetch_assoc()['n']);
        self::assertTrue($db->transactionBegin());
        try
        {
            BadgeCreation::create($staleMember, ['bb_name' => 'Stale nested owner'], 50, $rates, 200, false);
            self::fail('A stale owner must fail before changing an enclosing transaction');
        }
        catch (\InvalidArgumentException $ex) { self::assertSame('Invalid badge owner', $ex->getMessage()); }
        self::assertSame('0', $db->queryRead("SELECT COUNT(*) AS n FROM gdo_usersetting WHERE uset_user=454 AND uset_name IN ('credits','bb_welcome_granted')")->fetch_assoc()['n']);
        self::assertTrue($db->transactionRollback());
        try
        {
            \GDO\Badger\EquipmentPurchase::buy($staleMember, 50, 200);
            self::fail('A deactivated owner must not purchase an equipment slot');
        }
        catch (\RuntimeException $ex) { self::assertSame('Invalid purchase', $ex->getMessage()); }
        self::assertSame('0', $db->queryRead("SELECT COUNT(*) AS n FROM gdo_usersetting WHERE uset_user=454 AND uset_name IN ('credits','bb_welcome_granted','bb_add_max_equip')")->fetch_assoc()['n']);
        try
        {
            BadgeCreation::create($walletUser, ['bb_name' => 'Too expensive'], 51, $rates, 200);
            self::fail('An unaffordable badge must not be created');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Insufficient credits', $ex->getMessage());
        }
        $count = $db->queryRead("SELECT COUNT(*) AS n FROM gdo_badger_badge WHERE bb_name='Too expensive'")->fetch_assoc();
        self::assertSame('0', $count['n']);
        try
        {
            BadgeCreation::create($walletUser, ['bb_name' => 'Paid stat', 'bb_hp' => '1'], 50, $rates, 200);
            self::fail('A stat surcharge must be calculated by the creation service');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Insufficient credits', $ex->getMessage());
        }
        $count = $db->queryRead("SELECT COUNT(*) AS n FROM gdo_badger_badge WHERE bb_name='Paid stat'")->fetch_assoc();
        self::assertSame('0', $count['n']);
        $db->queryWrite("DELETE FROM gdo_badger_badge WHERE bb_name='Badge test'");
        GDO_User::setCurrent($walletUser);
        $addForm = Add::make()->getForm();
        $htmlFields = array_filter($addForm->getAllFields(), static fn($field) => $field instanceof \GDO\UI\GDT_HTML);
        $htmlContents = implode('', array_map(static fn($field) => $field->renderHTML(), $htmlFields));
        self::assertStringContainsString('badger-price-preview', $htmlContents);
        self::assertStringContainsString('data-xp=', $htmlContents);
        self::assertStringContainsString('<p>', $htmlContents);
        self::assertNotNull($addForm->getField('bb_name'));
        self::assertSame('bb_description', $addForm->getField('bb_description')->labelKey);
        self::assertNotNull($addForm->getField('bb_has_image'));
        self::assertNotNull($addForm->getField('bb_xp'));
        $inputs = ['bb_name' => 'Form badge', 'bb_xp' => '1', 'bb_hp' => '0', 'bb_str' => '0', 'bb_int' => '0', 'bb_has_image' => '0', 'submit' => '1'];
        // The core intentionally bypasses CSRF checks in CLI and unit-test mode.
        if (!\GDO\Core\Application::$INSTANCE->isCLIOrUnitTest())
        {
            $missingToken = Add::make()->inputs($inputs);
            $missingToken->execute();
            self::assertTrue($missingToken->getForm()->getField('xsrf')->hasError());
        }
        $count = $db->queryRead("SELECT COUNT(*) AS n FROM gdo_badger_badge WHERE bb_name='Form badge'")->fetch_assoc();
        self::assertSame('0', $count['n']);
        $balance = $db->queryRead("SELECT uset_var FROM gdo_usersetting WHERE uset_user=11 AND uset_name='credits'")->fetch_assoc();
        self::assertSame('50', $balance['uset_var']);
        $addMethod = Add::make()->inputs($inputs);
        $form = $addMethod->getForm();
        $token = $form->getField('xsrf')->csrfToken();
        $form->inputs(['xsrf' => $token]);
        $addMethod->inputs(['xsrf' => $token]);
        $addMethod->execute();
        $invalidFields = [];
        foreach ($addMethod->getForm()->getAllFields() as $field)
        {
            if ($field->hasError())
            {
                $invalidFields[] = $field->getName() . ': ' . $field->renderError();
            }
        }
        self::assertSame([], $invalidFields);
        $formBadge = $db->queryRead("SELECT bb_owner, bb_xp, bb_has_image FROM gdo_badger_badge WHERE bb_name='Form badge'")->fetch_assoc();
        self::assertNotNull($formBadge);
        self::assertSame('11', $formBadge['bb_owner']);
        self::assertSame('1', $formBadge['bb_xp']);
        $balance = $db->queryRead("SELECT uset_var FROM gdo_usersetting WHERE uset_user=11 AND uset_name='credits'")->fetch_assoc();
        self::assertSame('0', $balance['uset_var']);
        $db->queryWrite("DELETE FROM gdo_badger_badge WHERE bb_name='Form badge'");
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status) VALUES (4, 7, 12, 'pending')");
        $badge = GDO_Badge::blank(['bb_id' => '7', 'bb_owner' => '11', 'bb_verify_staff' => '1', 'bb_staff_approvals_needed' => '1']);
        $link = GDT_BadgeAction::make('bb_id')->gdo($badge)->renderCell();
        self::assertStringContainsString(href('Badger', 'Achieve', '&badge=7'), html_entity_decode($link));
        $recipient = GDO_Achieve::blank(['ba_id' => '4', 'ba_badge' => '7', 'ba_user' => '12', 'ba_status' => 'pending']);
        $reviewer = GDO_User::blank(['user_id' => '11', 'user_type' => 'member']);
        AchievementActions::approve($reviewer, $badge, $recipient);
        $row = $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=4')->fetch_assoc();
        self::assertSame('verified', $row['ba_status']);
        $audit = $db->queryRead("SELECT bat_actor, bat_actor_type FROM gdo_badger_attest WHERE bat_achieve=4 AND bat_to_status='verified'")->fetch_assoc();
        self::assertSame('11', $audit['bat_actor']);
        self::assertSame('staff', $audit['bat_actor_type']);
        $recipient->setVar('ba_status', 'verified');
        try
        {
            AchievementActions::transition($reviewer, $badge, $recipient, 'revoked');
            self::fail('Revocation requires a reason');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Revocation requires a reason', $ex->getMessage());
        }
        AchievementActions::transition($reviewer, $badge, $recipient, 'revoked', 'Mistaken approval');
        $revoked = $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=4')->fetch_assoc();
        self::assertSame('revoked', $revoked['ba_status']);
        $revocation = $db->queryRead("SELECT bat_reason FROM gdo_badger_attest WHERE bat_achieve=4 AND bat_to_status='revoked'")->fetch_assoc();
        self::assertSame('Mistaken approval', $revocation['bat_reason']);
        $recipientUser = GDO_User::blank(['user_id' => '12', 'user_type' => 'member']);
        $viewer = GDO_User::blank(['user_id' => '20', 'user_type' => 'member']);
        $badge->setVar('bb_visibility', 'acl_noone');
        self::assertTrue(BadgerPermissions::canViewBadge($reviewer, $reviewer, $badge));
        self::assertFalse(BadgerPermissions::canViewBadge($viewer, $reviewer, $badge));
        self::assertFalse(BadgerPermissions::canViewBadge($viewer, $recipientUser, $badge));
        $badge->setVar('bb_visibility', 'acl_all');
        self::assertTrue(BadgerPermissions::canViewBadge($viewer, $reviewer, $badge));
        $recipient->setVar('ba_visibility', 'acl_all');
        $recipient->setVar('ba_deletion', 'not_deleted');
        self::assertTrue(BadgerPermissions::canView($viewer, $recipientUser, $badge, $recipient));
        $db->queryWrite("INSERT INTO gdo_badger_attest (bat_achieve, bat_actor_type, bat_decision, bat_reason) VALUES (4, 'staff', 'rejected', '<private reason>')");
        $db->queryWrite("INSERT INTO gdo_badger_attest (bat_achieve, bat_actor_type, bat_decision, bat_reason) VALUES (4, 'system', 'pending', '<private claim>')");
        $history = AchievementHistory::render($viewer, $recipientUser, $badge, $recipient);
        self::assertStringContainsString('Mistaken approval', $history);
        self::assertStringNotContainsString('private reason', $history);
        self::assertStringNotContainsString('private claim', $history);
        $formerRecipient = GDO_User::blank(['user_id' => '12', 'user_type' => 'guest']);
        $guestHistory = AchievementHistory::render($formerRecipient, $recipientUser, $badge, $recipient);
        self::assertStringContainsString('Mistaken approval', $guestHistory);
        self::assertStringNotContainsString('private reason', $guestHistory);
        self::assertStringNotContainsString('private claim', $guestHistory);
        $renderMode = \GDO\Core\Application::$MODE;
        try {
            \GDO\Core\Application::$MODE = \GDO\Core\GDT::RENDER_HTML;
            $recipientHistory = AchievementHistory::render($recipientUser, $recipientUser, $badge, $recipient);
        } finally { \GDO\Core\Application::$MODE = $renderMode; }
        self::assertStringContainsString('&lt;private reason&gt;', $recipientHistory);
        self::assertStringContainsString('&lt;private claim&gt;', $recipientHistory);
        self::assertStringNotContainsString('<private reason>', $recipientHistory);
        $recipient->setVar('ba_deletion', 'deleted_user');
        self::assertFalse(BadgerPermissions::canView($viewer, $recipientUser, $badge, $recipient));
        self::assertTrue(BadgerPermissions::canView($recipientUser, $recipientUser, $badge, $recipient));
        self::assertTrue(BadgerPermissions::canView($reviewer, $recipientUser, $badge, $recipient));
        $recipient->setVar('ba_deletion', 'not_deleted');
        $recipient->setVar('ba_visibility', 'acl_noone');
        self::assertFalse(BadgerPermissions::canView($viewer, $recipientUser, $badge, $recipient));
        self::assertTrue(BadgerPermissions::canView($recipientUser, $recipientUser, $badge, $recipient));
        self::assertTrue(BadgerPermissions::canView($reviewer, $recipientUser, $badge, $recipient));
        $db->queryWrite('INSERT INTO gdo_badger_badge (bb_id, bb_xp, bb_hp, bb_str, bb_int) VALUES (8, 30, 30, 30, 30), (9, 50, 50, 50, 50)');
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status) VALUES (5, 8, 12, 'claimed'), (6, 9, 12, 'revoked'), (7, 7, 20, 'claimed')");
        $totals = GDO_Achieve::table()->select('ba_id')->where('ba_id=4')->exec()->fetchAssoc();
        self::assertSame('30', $totals['ba_total_xp']);
        self::assertSame('30', $totals['ba_total_hp']);
        self::assertSame('30', $totals['ba_total_str']);
        self::assertSame('30', $totals['ba_total_int']);
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (16, 'claimant')");
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status) VALUES (16, 7, 16, 'verified')");
        $db->queryWrite("UPDATE gdo_badger_badge SET bb_name='Blue Star', bb_owner=11 WHERE bb_id=7");
        $claimant = GDO_User::blank(['user_id' => '16', 'user_type' => 'member']);
        GDO_User::setCurrent($claimant);
        $formMethod = Achieve::make()->inputs(['achievement' => '16']);
        $claimForm = $formMethod->getForm();
        $actionField = $claimForm->getField('action');
        self::assertContains('claim', $actionField->enumValues);
        self::assertContains('share', $actionField->enumValues);
        self::assertContains('delete_user', $actionField->enumValues);
        self::assertNotContains('delete_badger', $actionField->enumValues);
        self::assertNotNull($claimForm->getField('visibility'));
        $previews = array_filter($claimForm->getFields(), fn ($field) => $field instanceof \GDO\UI\GDT_HTML);
        self::assertCount(1, $previews);
        self::assertStringContainsString('Blue Star', reset($previews)->renderHTML());
        GDO_User::setCurrent($reviewer);
        $otherForm = Achieve::make()->inputs(['achievement' => '16'])->getForm();
        self::assertNotContains('claim', $otherForm->getField('action')->enumValues);
        self::assertNotContains('share', $otherForm->getField('action')->enumValues);
        self::assertContains('delete_badger', $otherForm->getField('action')->enumValues);
        self::assertContains('revoke', $otherForm->getField('action')->enumValues);
        self::assertNotContains('delete_user', $otherForm->getField('action')->enumValues);
        self::assertNotContains('submit', $otherForm->getField('action')->enumValues);
        self::assertNotContains('approve', $otherForm->getField('action')->enumValues);
        $db->queryWrite('UPDATE gdo_badger_badge SET bb_owner=11 WHERE bb_id IN (8,9)');
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status, ba_visibility) VALUES (41, 8, 16, 'pending', 'acl_all'), (42, 9, 16, 'withdrawn', 'acl_all')");
        $pendingReview = Achieve::make()->inputs(['achievement' => '41'])->getForm();
        self::assertContains('approve', $pendingReview->getField('action')->enumValues);
        self::assertContains('reject', $pendingReview->getField('action')->enumValues);
        self::assertNotContains('submit', $pendingReview->getField('action')->enumValues);
        $pendingEdit = Edit::make()->inputs(['badge' => '8']);
        $pendingEditForm = $pendingEdit->getForm();
        $pendingEditForm->getField('bb_staff_approvals_needed')->var('2');
        $pendingEdit->formValidated($pendingEditForm);
        self::assertSame('1', $db->queryRead('SELECT bb_staff_approvals_needed FROM gdo_badger_badge WHERE bb_id=8')->fetch_assoc()['bb_staff_approvals_needed']);
        GDO_User::setCurrent($claimant);
        $pendingOwner = Achieve::make()->inputs(['achievement' => '41'])->getForm();
        self::assertContains('withdraw', $pendingOwner->getField('action')->enumValues);
        self::assertNotContains('approve', $pendingOwner->getField('action')->enumValues);
        self::assertNotContains('submit', $pendingOwner->getField('action')->enumValues);
        $withdrawn = Achieve::make()->inputs(['achievement' => '42'])->getForm();
        self::assertContains('resume', $withdrawn->getField('action')->enumValues);
        self::assertNotContains('submit', $withdrawn->getField('action')->enumValues);
        GDO_User::setCurrent($viewer);
        $publicView = Achieve::make()->inputs(['achievement' => '41'])->getForm();
        self::assertNull($publicView->getField('action'));
        $db->queryWrite('DELETE FROM gdo_badger_achieve WHERE ba_id IN (41,42)');
        $db->queryWrite('UPDATE gdo_badger_badge SET bb_owner=NULL WHERE bb_id IN (8,9)');
        GDO_User::setCurrent($reviewer);
        $editForm = Edit::make()->inputs(['badge' => '7'])->getForm();
        self::assertNotNull($editForm->getField('bb_name'));
        self::assertNotNull($editForm->getField('bb_verify_staff'));
        self::assertNotNull($editForm->getField('bb_staff_approvals_needed'));
        self::assertNotNull($editForm->getField('bb_verify_doc'));
        self::assertNotNull($editForm->getField('badge_tags'));
        self::assertFalse($editForm->getField('bb_staff_approvals_needed')->validate(0));
        self::assertTrue($editForm->getField('bb_staff_approvals_needed')->validate(2));
        self::assertNull($editForm->getField('bb_xp'));
        $db->queryWrite('UPDATE gdo_badger_badge SET bb_has_image=1 WHERE bb_id=7');
        GDO_Badge::getById('7')?->uncache();
        $imageEdit = Edit::make()->inputs(['badge' => '7'])->getForm();
        self::assertNotNull($imageEdit->actions()->getField('image'));
        foreach ($imageEdit->actions()->getAllFields() as $action) { self::assertNotEmpty($action->getName()); }
        $badgeWithImage = GDO_Badge::blank(['bb_id' => '7', 'bb_owner' => '11', 'bb_has_image' => '1']);
        $imageForm = Image::make()->inputs(['badge' => '7'])->getForm();
        self::assertSame(' enctype="multipart/form-data"', $imageForm->htmlEnctype());
        self::assertNotNull($imageForm->getField('item_upload'));
        self::assertSame(['item', 'icon', 'background'], $imageForm->getField('image_kind')->enumValues);
        self::assertNotNull($imageForm->getField('xsrf'));
        try
        {
            ItemStorage::save($viewer, $badgeWithImage, 'GIF89a');
            self::fail('An unrelated user must not store an item');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Permission denied', $ex->getMessage());
        }
        $testFileId = random_int(1500000000, 2000000000);
        $testFilePath = GDO_File::filesDir() . $testFileId;
        self::assertFileDoesNotExist($testFilePath);
        self::assertFileDoesNotExist(GDO_File::filesDir() . ($testFileId + 1));
        self::assertFileDoesNotExist(GDO_File::filesDir() . ($testFileId + 2));
        $db->queryWrite('CREATE TEMPORARY TABLE gdo_file (file_id INT AUTO_INCREMENT PRIMARY KEY, file_name VARCHAR(191) NOT NULL, file_type VARCHAR(191) NOT NULL, file_size INT NOT NULL, file_width INT, file_height INT, file_bitrate INT, file_duration INT) ENGINE=InnoDB AUTO_INCREMENT=' . $testFileId);
        $testGif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        try
        {
            $stored = ItemStorage::save($reviewer, $badgeWithImage, $testGif);
            self::assertSame((string) $testFileId, $stored->getID());
            self::assertFileExists($testFilePath);
            self::assertSame($testGif, file_get_contents($testFilePath));
            $item = $db->queryRead('SELECT bb_item FROM gdo_badger_badge WHERE bb_id=7')->fetch_assoc();
            self::assertSame((string) $testFileId, $item['bb_item']);
            $icon = ItemStorage::save($reviewer, $badgeWithImage, $testGif, 'icon');
            $background = ItemStorage::save($reviewer, $badgeWithImage, $testGif, 'background');
            $images = $db->queryRead('SELECT bb_item, bb_icon, bb_image FROM gdo_badger_badge WHERE bb_id=7')->fetch_assoc();
            self::assertSame((string) $testFileId, $images['bb_item']);
            self::assertSame($icon->getID(), $images['bb_icon']);
            self::assertSame($background->getID(), $images['bb_image']);
            $db->queryWrite("UPDATE gdo_badger_badge SET bb_visibility='acl_noone' WHERE bb_id=7");
            self::assertSame($background->getID(), BadgeBackground::fileId($reviewer, '7'));
            self::assertNull(BadgeBackground::fileId($viewer, '7'));
            $db->queryWrite("UPDATE gdo_badger_badge SET bb_visibility='acl_all' WHERE bb_id=7");
            self::assertSame($background->getID(), BadgeBackground::fileId($viewer, '7'));
        }
        finally
        {
            foreach ([$testFilePath, GDO_File::filesDir() . ($testFileId + 1), GDO_File::filesDir() . ($testFileId + 2)] as $path)
            {
                if (is_file($path) && file_get_contents($path) === $testGif)
                {
                    unlink($path);
                }
            }
            $db->queryWrite('UPDATE gdo_badger_badge SET bb_item=NULL, bb_icon=NULL, bb_image=NULL WHERE bb_id=7');
        }
        $db->queryWrite('UPDATE gdo_badger_badge SET bb_has_image=0 WHERE bb_id=7');
        $error = '';
        $args = [];
        self::assertTrue(Edit::make()->inputs(['badge' => '7'])->hasPermission($reviewer, $error, $args));
        $error = '';
        self::assertFalse(Edit::make()->inputs(['badge' => '7'])->hasPermission($viewer, $error, $args));
        self::assertSame('err_permission_required', $error);
        GDO_User::setCurrent($claimant);
        $actionField->var('claim');
        $formMethod->formValidated($claimForm);
        $claim = $db->queryRead('SELECT ba_status, ba_claimed FROM gdo_badger_achieve WHERE ba_id=16')->fetch_assoc();
        self::assertSame('claimed', $claim['ba_status']);
        self::assertNotNull($claim['ba_claimed']);
        $sharing = GDO_Achieve::blank(['ba_id' => '16', 'ba_badge' => '7', 'ba_user' => '16']);
        AchievementSharing::set($claimant, $badge, $sharing, 'acl_noone');
        $shared = $db->queryRead('SELECT ba_visibility FROM gdo_badger_achieve WHERE ba_id=16')->fetch_assoc();
        self::assertSame('acl_noone', $shared['ba_visibility']);
        try
        {
            AchievementSharing::set($viewer, $badge, $sharing, 'acl_all');
            self::fail('Another user must not change sharing');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Permission denied', $ex->getMessage());
        }
        self::assertTrue($db->transactionBegin());
        AchievementSharing::set($claimant, $badge, $sharing, 'acl_all', false);
        self::assertTrue($db->transactionRollback());
        $shared = $db->queryRead('SELECT ba_visibility FROM gdo_badger_achieve WHERE ba_id=16')->fetch_assoc();
        self::assertSame('acl_noone', $shared['ba_visibility']);
        $db->queryWrite("UPDATE gdo_badger_achieve SET ba_deletion='not_deleted' WHERE ba_id=16");
        $deletion = GDO_Achieve::blank(['ba_id' => '16', 'ba_badge' => '7', 'ba_user' => '16', 'ba_deletion' => 'not_deleted']);
        AchievementDeletion::mark($claimant, $badge, $deletion, true);
        $row = $db->queryRead('SELECT ba_deletion FROM gdo_badger_achieve WHERE ba_id=16')->fetch_assoc();
        self::assertSame('deleted_user', $row['ba_deletion']);
        $forgedDeletion = GDO_Achieve::blank(['ba_id' => '16', 'ba_badge' => '7', 'ba_user' => '20', 'ba_deletion' => 'deleted_user']);
        try
        {
            AchievementDeletion::mark($viewer, $badge, $forgedDeletion, true);
            self::fail('A forged recipient must not mark an achievement deleted');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Achievement changed concurrently', $ex->getMessage());
        }
        try
        {
            AchievementDeletion::mark($reviewer, $badge, $deletion, false);
            self::fail('A stale deletion must not overwrite the other side');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Achievement changed concurrently', $ex->getMessage());
        }
        $deletion->setVar('ba_deletion', 'deleted_user');
        AchievementDeletion::mark($reviewer, $badge, $deletion, false);
        $row = $db->queryRead('SELECT ba_deletion FROM gdo_badger_achieve WHERE ba_id=16')->fetch_assoc();
        self::assertSame('deleted_both', $row['ba_deletion']);
        BadgeStaff::set($reviewer, $badge, $reviewer, false);
        self::assertFalse(BadgerPermissions::isBadgeStaff('7', '11'));
        BadgeStaff::set($reviewer, $badge, $reviewer, true);
        self::assertTrue(BadgerPermissions::isBadgeStaff('7', '11'));
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (14, 'newstaff')");
        $newStaff = GDO_User::blank(['user_id' => '14', 'user_type' => 'member']);
        $newStaff->tempSet('user_settings', ['bb_default_achieve_visible' => 'acl_noone']);
        BadgeStaff::set($reviewer, $badge, $newStaff, true);
        $newRow = $db->queryRead('SELECT ba_id, ba_status, ba_is_staff, ba_visibility FROM gdo_badger_achieve WHERE ba_badge=7 AND ba_user=14')->fetch_assoc();
        self::assertSame('viewed', $newRow['ba_status']);
        self::assertSame('1', $newRow['ba_is_staff']);
        self::assertSame('acl_noone', $newRow['ba_visibility']);
        $staffAudit = $db->queryRead('SELECT bat_actor, bat_to_status FROM gdo_badger_attest WHERE bat_achieve=' . (int)$newRow['ba_id'])->fetch_assoc();
        self::assertSame('11', $staffAudit['bat_actor']);
        self::assertSame('viewed', $staffAudit['bat_to_status']);
        try
        {
            BadgeStaff::set($reviewer, $badge, $staleMember, true);
            self::fail('A deactivated account must not be assigned badge staff');
        }
        catch (\RuntimeException $ex) { self::assertSame('Staff must be a member', $ex->getMessage()); }
        self::assertSame('0', $db->queryRead('SELECT COUNT(*) AS n FROM gdo_badger_achieve WHERE ba_badge=7 AND ba_user=454')->fetch_assoc()['n']);
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_badge, ba_user, ba_status, ba_is_staff) VALUES (7, 454, 'viewed', 1)");
        BadgeStaff::set($reviewer, $badge, $staleMember, false);
        self::assertSame('0', $db->queryRead('SELECT ba_is_staff FROM gdo_badger_achieve WHERE ba_badge=7 AND ba_user=454')->fetch_assoc()['ba_is_staff']);
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (15, 'applicant')");
        $applicant = GDO_User::blank(['user_id' => '15', 'user_type' => 'member']);
        $applicant->tempSet('user_settings', ['bb_default_achieve_visible' => 'acl_noone']);
        $badge->setVar('bb_active', '1');
        $started = AchievementRequests::start($applicant, $badge);
        self::assertSame('viewed', $started->gdoVar('ba_status'));
        self::assertSame('acl_noone', $started->gdoVar('ba_visibility'));
        $audit = $db->queryRead('SELECT bat_to_status FROM gdo_badger_attest WHERE bat_achieve=' . (int)$started->getID())->fetch_assoc();
        self::assertSame('viewed', $audit['bat_to_status']);
        self::assertSame($started->getID(), AchievementRequests::start($applicant, $badge)->getID());
        $db->queryWrite("UPDATE gdo_badger_badge SET bb_visibility='acl_noone' WHERE bb_id=7");
        try
        {
            AchievementRequests::start($applicant, $badge);
            self::fail('A stale public badge must not bypass its current private ACL');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Cannot start this achievement', $ex->getMessage());
        }
        BadgeStaff::set($reviewer, $badge, $applicant, true);
        self::assertSame($started->getID(), AchievementRequests::start($applicant, $badge)->getID());
        $staffBadges = BadgeListing::query($applicant)->where('bb_id=7')->exec()->fetchAllAssoc();
        self::assertCount(1, $staffBadges);
        BadgeStaff::set($reviewer, $badge, $applicant, false);
        $db->queryWrite("UPDATE gdo_badger_badge SET bb_visibility='acl_all' WHERE bb_id=7");
        $db->queryWrite('UPDATE gdo_badger_badge SET bb_active=0 WHERE bb_id=7');
        try
        {
            AchievementRequests::start($applicant, $badge);
            self::fail('A stale badge object must not bypass the archived state');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Cannot start this achievement', $ex->getMessage());
        }
        $db->queryWrite('UPDATE gdo_badger_badge SET bb_active=1, bb_owner=99 WHERE bb_id=7');
        try
        {
            BadgeStaff::set($reviewer, $badge, $applicant, true);
            self::fail('A stale badge owner must not assign staff');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Permission denied', $ex->getMessage());
        }
        $db->queryWrite('UPDATE gdo_badger_badge SET bb_owner=11 WHERE bb_id=7');
        GDO_User::setCurrent($applicant);
        $ownRows = MyAchievements::make()->gdoQuery()->exec()->fetchAllAssoc();
        self::assertSame([$started->getID()], array_column($ownRows, 'ba_id'));
        $startId = $started->getID();
        $submitInputs = ['achievement' => $startId, 'action' => 'submit', 'claim_message' => 'I helped at the event', 'visibility' => 'acl_noone', 'submit' => '1'];
        if (!\GDO\Core\Application::$INSTANCE->isCLIOrUnitTest())
        {
            $withoutToken = Achieve::make()->inputs($submitInputs);
            $withoutToken->execute();
            self::assertTrue($withoutToken->getForm()->getField('xsrf')->hasError());
        }
        $current = $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=' . (int) $startId)->fetch_assoc();
        self::assertSame('viewed', $current['ba_status']);
        $submit = Achieve::make()->inputs($submitInputs);
        $submitForm = $submit->getForm();
        $token = $submitForm->getField('xsrf')->csrfToken();
        $submitForm->inputs(['xsrf' => $token]);
        $submit->inputs(['xsrf' => $token]);
        $submit->execute();
        $current = $db->queryRead('SELECT ba_status, ba_round FROM gdo_badger_achieve WHERE ba_id=' . (int) $startId)->fetch_assoc();
        self::assertSame('pending', $current['ba_status']);
        self::assertSame('1', $current['ba_round']);
        $message = $db->queryRead('SELECT bat_reason FROM gdo_badger_attest WHERE bat_achieve=' . (int) $startId . " AND bat_to_status='pending'")->fetch_assoc();
        self::assertSame('I helped at the event', $message['bat_reason']);
        self::assertTrue($db->transactionBegin());
        try
        {
            \GDO\Badger\BadgeRateLimit::check($applicant, 'claim', 1, time());
            self::fail('A recent submission must count against the limit');
        }
        catch (\RuntimeException $ex) { self::assertSame('Badger rate limit exceeded', $ex->getMessage()); }
        finally { $db->transactionRollback(); }
        self::assertTrue($db->transactionBegin());
        \GDO\Badger\BadgeRateLimit::check($applicant, 'claim', 1, time() + 3601);
        self::assertTrue($db->transactionRollback());
        $forged = Achieve::make()->inputs(['achievement' => $startId, 'action' => 'approve', 'visibility' => 'acl_noone', 'submit' => '1']);
        $forgedForm = $forged->getForm();
        $token = $forgedForm->getField('xsrf')->csrfToken();
        $forgedForm->inputs(['xsrf' => $token]);
        $forged->inputs(['xsrf' => $token]);
        $forged->execute();
        self::assertTrue($forgedForm->getField('action')->hasError());
        $current = $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=' . (int) $startId)->fetch_assoc();
        self::assertSame('pending', $current['ba_status']);
        GDO_User::setCurrent($reviewer);
        $approve = Achieve::make()->inputs(['achievement' => $startId, 'action' => 'approve', 'submit' => '1']);
        $approveForm = $approve->getForm();
        $token = $approveForm->getField('xsrf')->csrfToken();
        $approveForm->inputs(['xsrf' => $token]);
        $approve->inputs(['xsrf' => $token]);
        $approve->execute();
        $current = $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=' . (int) $startId)->fetch_assoc();
        self::assertSame('verified', $current['ba_status']);
        GDO_User::setCurrent($applicant);
        $claim = Achieve::make()->inputs(['achievement' => $startId, 'action' => 'claim', 'visibility' => 'acl_noone', 'submit' => '1']);
        $claimForm = $claim->getForm();
        $token = $claimForm->getField('xsrf')->csrfToken();
        $claimForm->inputs(['xsrf' => $token]);
        $claim->inputs(['xsrf' => $token]);
        $claim->execute();
        $current = $db->queryRead('SELECT ba_status, ba_claimed FROM gdo_badger_achieve WHERE ba_id=' . (int) $startId)->fetch_assoc();
        self::assertSame('claimed', $current['ba_status']);
        self::assertNotNull($current['ba_claimed']);
        $db->queryWrite('UPDATE gdo_badger_achieve SET ba_status=\'viewed\', ba_round=0, ba_staff_approvals=0 WHERE ba_id=' . (int) $startId);
        $db->queryWrite("INSERT INTO gdo_badger_badge (bb_id, bb_owner, bb_active, bb_visibility) VALUES (50, 15, 1, 'acl_noone'), (51, 99, 1, 'acl_noone'), (52, 99, 1, 'acl_all'), (53, 15, 0, 'acl_all')");
        $listing = BadgeListing::query($applicant)->where('bb_id>=50')->exec()->fetchAllAssoc();
        $listed = array_column($listing, 'bb_id');
        sort($listed);
        self::assertSame(['50', '52'], $listed);
        $formerOwner = GDO_User::blank(['user_id' => '15', 'user_type' => 'guest']);
        $guestListing = BadgeListing::query($formerOwner)->where('bb_id>=50')->exec()->fetchAllAssoc();
        self::assertSame(['52'], array_column($guestListing, 'bb_id'));
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status, ba_deletion) VALUES (30, 7, 30, 'pending', 'not_deleted'), (31, 9, 31, 'pending', 'not_deleted'), (32, 7, 32, 'pending', 'deleted_badger')");
        GDO_User::setCurrent($reviewer);
        $reviewRows = ReviewQueue::make()->gdoQuery()->exec()->fetchAllAssoc();
        self::assertSame(['30'], array_column($reviewRows, 'ba_id'));
        $rankingRows = Rankings::make()->gdoQuery()->exec()->fetchAllAssoc();
        self::assertSame('12', $rankingRows[0]['ba_user']);
        self::assertSame('30', $rankingRows[0]['ba_total_xp']);
        self::assertSame('1', $rankingRows[0]['ba_total_count']);
        self::assertArrayNotHasKey('ba_badge', $rankingRows[0]);
        // Ranking exposes only totals, including private claims, but not revoked awards.
        $rankedId = (int) $db->queryRead("SELECT ba_id FROM gdo_badger_achieve WHERE ba_user=12 AND ba_status='claimed'")->fetch_assoc()['ba_id'];
        $db->queryWrite("UPDATE gdo_badger_achieve SET ba_visibility='acl_noone' WHERE ba_id={$rankedId}");
        $privateRanking = Rankings::make()->gdoQuery()->exec()->fetchAllAssoc();
        self::assertSame('30', $privateRanking[0]['ba_total_xp']);
        self::assertArrayNotHasKey('ba_badge', $privateRanking[0]);
        $db->queryWrite("UPDATE gdo_badger_achieve SET ba_status='revoked' WHERE ba_id={$rankedId}");
        $revokedRanking = Rankings::make()->gdoQuery()->exec()->fetchAllAssoc();
        self::assertNotContains('12', array_column($revokedRanking, 'ba_user'));
        $db->queryWrite("UPDATE gdo_badger_achieve SET ba_status='claimed' WHERE ba_id={$rankedId}");
        $db->queryWrite("UPDATE gdo_user SET user_type='ghost' WHERE user_id=12");
        $visibleRanking = Rankings::make()->gdoQuery()->exec()->fetchAllAssoc();
        self::assertNotContains('12', array_column($visibleRanking, 'ba_user'));
        $db->queryWrite("UPDATE gdo_user SET user_type='member' WHERE user_id=12");
        $rankingObjects = Rankings::make()->gdoQuery()->exec()->fetchAllObjects();
        self::assertSame('30', $rankingObjects[0]->gdoVar('ba_total_xp'));
        self::assertSame('9', $rankingObjects[1]->gdoVar('ba_total_xp'));
        self::assertStringContainsString('30', $rankingObjects[0]->gdoColumn('ba_total_xp')->renderCell());
        $multiCheck = GDO_Achieve::blank(['ba_id' => '30', 'ba_badge' => '7', 'ba_user' => '30', 'ba_status' => 'pending']);
        $badge->setVar('bb_verify_cc', '1');
        $db->queryWrite('UPDATE gdo_badger_badge SET bb_verify_cc=1 WHERE bb_id=7');
        AchievementActions::approve($reviewer, $badge, $multiCheck);
        $pending = $db->queryRead('SELECT ba_status, ba_staff_approvals FROM gdo_badger_achieve WHERE ba_id=30')->fetch_assoc();
        self::assertSame('pending', $pending['ba_status']);
        self::assertSame('1', $pending['ba_staff_approvals']);
        try
        {
            AchievementActions::approve($reviewer, $badge, $multiCheck);
            self::fail('A second approval from the same staff member must be rejected');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Staff member already approved this round', $ex->getMessage());
        }
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (17, 'secondreviewer')");
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status, ba_deletion, ba_is_staff) VALUES (33, 7, 33, 'pending', 'not_deleted', 0), (34, 7, 17, 'viewed', 'not_deleted', 1)");
        $badge->setVar('bb_verify_cc', '0');
        $badge->setVar('bb_staff_approvals_needed', '2');
        $db->queryWrite('UPDATE gdo_badger_badge SET bb_verify_cc=0, bb_staff_approvals_needed=2 WHERE bb_id=7');
        $twoReviews = GDO_Achieve::blank(['ba_id' => '33', 'ba_badge' => '7', 'ba_user' => '33', 'ba_status' => 'pending']);
        $forgedTarget = GDO_Achieve::blank(['ba_id' => '31', 'ba_badge' => '7', 'ba_user' => '31', 'ba_status' => 'pending']);
        try
        {
            AchievementActions::approve($reviewer, $badge, $forgedTarget);
            self::fail('An achievement from another badge must not be approved');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Achievement changed concurrently', $ex->getMessage());
        }
        try
        {
            AchievementActions::transition($reviewer, $badge, $forgedTarget, 'rejected', 'Wrong badge');
            self::fail('A transition must verify the actual badge association');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Achievement changed concurrently', $ex->getMessage());
        }
        self::assertTrue($db->transactionBegin());
        AchievementActions::transition($reviewer, $badge, $twoReviews, 'rejected', 'Rollback test', false);
        self::assertTrue($db->transactionRollback());
        $rolledBack = $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=33')->fetch_assoc();
        self::assertSame('pending', $rolledBack['ba_status']);
        AchievementActions::approve($reviewer, $badge, $twoReviews);
        $firstVote = $db->queryRead('SELECT ba_status, ba_staff_approvals FROM gdo_badger_achieve WHERE ba_id=33')->fetch_assoc();
        self::assertSame('pending', $firstVote['ba_status']);
        self::assertSame('1', $firstVote['ba_staff_approvals']);
        $db->queryWrite('UPDATE gdo_badger_achieve SET ba_is_staff=0 WHERE ba_id=34');
        try
        {
            AchievementActions::approve(GDO_User::blank(['user_id' => '17', 'user_type' => 'member']), $badge, $twoReviews);
            self::fail('Removed staff must not approve');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Permission denied', $ex->getMessage());
        }
        try
        {
            AchievementActions::transition(GDO_User::blank(['user_id' => '17', 'user_type' => 'member']), $badge, $twoReviews, 'rejected', 'Removed staff');
            self::fail('Removed staff must not reject');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Permission denied', $ex->getMessage());
        }
        $db->queryWrite('UPDATE gdo_badger_achieve SET ba_is_staff=1 WHERE ba_id=34');
        AchievementActions::approve(GDO_User::blank(['user_id' => '17', 'user_type' => 'member']), $badge, $twoReviews);
        $secondVote = $db->queryRead('SELECT ba_status, ba_staff_approvals FROM gdo_badger_achieve WHERE ba_id=33')->fetch_assoc();
        self::assertSame('verified', $secondVote['ba_status']);
        self::assertSame('2', $secondVote['ba_staff_approvals']);
        AchievementActions::transition($reviewer, $badge, $multiCheck, 'rejected', 'Please revise');
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (30, 'resubmitter')");
        $multiCheck->setVar('ba_status', 'rejected');
        $multiCheck->setVar('ba_round', '0');
        AchievementActions::transition(GDO_User::blank(['user_id' => '30', 'user_type' => 'member']), $badge, $multiCheck, 'pending');
        $multiCheck->setVar('ba_status', 'pending');
        $multiCheck->setVar('ba_round', '1');
        AchievementActions::approve($reviewer, $badge, $multiCheck);
        $resubmitted = $db->queryRead('SELECT ba_status, ba_round, ba_staff_approvals FROM gdo_badger_achieve WHERE ba_id=30')->fetch_assoc();
        self::assertSame('pending', $resubmitted['ba_status']);
        self::assertSame('1', $resubmitted['ba_round']);
        self::assertSame('1', $resubmitted['ba_staff_approvals']);
        $staleRound = GDO_Achieve::blank(['ba_id' => '30', 'ba_badge' => '7', 'ba_user' => '30', 'ba_status' => 'pending', 'ba_round' => '0']);
        try
        {
            AchievementActions::approve(GDO_User::blank(['user_id' => '17', 'user_type' => 'member']), $badge, $staleRound);
            self::fail('An old-round approval must not count in the new round');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Achievement changed concurrently', $ex->getMessage());
        }
        try
        {
            AchievementActions::transition($reviewer, $badge, $staleRound, 'rejected', 'Stale decision');
            self::fail('An old round must not update the new round');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Achievement changed concurrently', $ex->getMessage());
        }
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (19, 'equipper')");
        $db->queryWrite("INSERT INTO gdo_badger_badge (bb_id, bb_owner, bb_name, bb_item, bb_item_name, bb_item_x, bb_item_y, bb_item_w, bb_item_h) VALUES (70, 11, '<hat>', 1, '<hat>', 128, 0, 256, 256), (71, 11, 'shoes', 2, 'shoes', 0, 0, NULL, NULL), (72, 11, 'coat', 3, 'coat', 0, 0, NULL, NULL)");
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status, ba_equipped, ba_visibility) VALUES (70, 70, 19, 'claimed', 0, 'acl_noone'), (71, 71, 19, 'claimed', 0, 'acl_all'), (72, 72, 19, 'verified', 0, 'acl_all')");
        $equipper = GDO_User::blank(['user_id' => '19', 'user_type' => 'member']);
        Application::$MODE = \GDO\Core\GDT::RENDER_HTML;
        $choices = ItemEquipment::choices($equipper);
        self::assertStringContainsString('&lt;hat&gt;', $choices['70']);
        self::assertArrayNotHasKey('72', $choices);
        ItemEquipment::set($equipper, '70', true, 1);
        $layers = ItemEquipment::renderLayers($equipper, 512, 512);
        self::assertStringContainsString('left:25.0000%', $layers);
        self::assertStringContainsString('width:50.0000%', $layers);
        self::assertStringContainsString('height:50.0000%', $layers);
        self::assertStringContainsString('@keyframes badger-avatar-reveal', $layers);
        self::assertStringContainsString('@keyframes badger-item-reveal', $layers);
        self::assertStringContainsString('prefers-reduced-motion:reduce', $layers);
        self::assertStringContainsString('&lt;hat&gt;', $layers);
        self::assertStringNotContainsString('<hat>', $layers);
        self::assertStringContainsString(html(href('Badger', 'Item', '&achievement=70')), $layers);
        self::assertSame('1', Item::fileId('70'));
        self::assertNull(Item::fileId('71'));
        try
        {
            ItemEquipment::set($equipper, '71', true, 1);
            self::fail('Equipment limit must be enforced');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Equipment limit reached', $ex->getMessage());
        }
        try
        {
            ItemEquipment::set($equipper, '72', true, 2);
            self::fail('Unclaimed achievements cannot be equipped');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Item is not available', $ex->getMessage());
        }
        $db->queryWrite("INSERT INTO gdo_usersetting (uset_user, uset_name, uset_var) VALUES (19, 'bb_add_max_equip', '1')");
        ItemEquipment::set($equipper, '71', true, 1);
        GDO_User::setCurrent($equipper);
        $items = Items::make()->inputs(['achievement' => '70', 'equip' => '0', 'submit' => '1']);
        $itemForm = $items->getForm();
        $token = $itemForm->getField('xsrf')->csrfToken();
        $itemForm->inputs(['xsrf' => $token]);
        $items->inputs(['xsrf' => $token]);
        $items->execute();
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (1, 'system')");
        $preview = $items->renderPage();
        self::assertInstanceOf(\GDO\Avatar\GDT_Avatar::class, array_values($preview->getFields())[0]);
        self::assertNull(Item::fileId('70'));
        $equipped = $db->queryRead('SELECT ba_equipped FROM gdo_badger_achieve WHERE ba_id=70')->fetch_assoc();
        self::assertSame('0', $equipped['ba_equipped']);
        $revokedItem = GDO_Achieve::blank(['ba_id' => '71', 'ba_badge' => '71', 'ba_user' => '19', 'ba_status' => 'claimed']);
        AchievementActions::transition($reviewer, GDO_Badge::blank(['bb_id' => '71', 'bb_owner' => '11']), $revokedItem, 'revoked', 'test');
        $equipped = $db->queryRead('SELECT ba_equipped, ba_status FROM gdo_badger_achieve WHERE ba_id=71')->fetch_assoc();
        self::assertSame('0', $equipped['ba_equipped']);
        self::assertSame('revoked', $equipped['ba_status']);
        self::assertSame('', ItemEquipment::renderLayers($equipper, 512, 512));
        GDO_Achieve::getById('71')?->uncache();
        GDO_User::setCurrent($reviewer);
        $reviewForm = Achieve::make()->inputs(['achievement' => '71'])->getForm();
        self::assertContains('restore', $reviewForm->getField('action')->enumValues);
        GDO_User::setCurrent($equipper);
        $recipientForm = Achieve::make()->inputs(['achievement' => '71'])->getForm();
        self::assertNotContains('restore', $recipientForm->getField('action')->enumValues);
        $revokedItem->setVar('ba_status', 'revoked');
        $restoreBadge = GDO_Badge::blank(['bb_id' => '71', 'bb_owner' => '11']);
        try
        {
            AchievementActions::transition($equipper, $restoreBadge, $revokedItem, 'verified', 'Not authorized');
            self::fail('Only a reviewer may correct a revocation');
        }
        catch (\RuntimeException $ex) { self::assertSame('Permission denied', $ex->getMessage()); }
        try
        {
            AchievementActions::transition($reviewer, $restoreBadge, $revokedItem, 'verified');
            self::fail('A correction needs a reason');
        }
        catch (\RuntimeException $ex) { self::assertSame('Staff correction requires a reason', $ex->getMessage()); }
        $db->queryWrite("UPDATE gdo_badger_achieve SET ba_claimed='2026-01-01 00:00:00' WHERE ba_id=71");
        AchievementActions::transition($reviewer, $restoreBadge, $revokedItem, 'verified', 'Revocation was mistaken');
        $restored = $db->queryRead('SELECT ba_equipped, ba_status, ba_claimed FROM gdo_badger_achieve WHERE ba_id=71')->fetch_assoc();
        self::assertSame('0', $restored['ba_equipped']);
        self::assertSame('verified', $restored['ba_status']);
        self::assertNull($restored['ba_claimed']);
        $correction = $db->queryRead("SELECT bat_reason, bat_actor_type FROM gdo_badger_attest WHERE bat_achieve=71 AND bat_from_status='revoked' AND bat_to_status='verified'")->fetch_assoc();
        self::assertSame(['bat_reason' => 'Revocation was mistaken', 'bat_actor_type' => 'staff'], $correction);
        $historyAward = GDO_Achieve::blank(['ba_id' => '71', 'ba_badge' => '71', 'ba_user' => '19', 'ba_status' => 'verified', 'ba_visibility' => 'acl_all', 'ba_deletion' => 'not_deleted']);
        $history = \GDO\Badger\AchievementHistory::render($viewer, $equipper, $restoreBadge, $historyAward);
        self::assertStringContainsString('Revocation was mistaken', $history);
        $db->queryWrite('UPDATE gdo_badger_badge SET bb_icon=9 WHERE bb_id=70');
        $profile = ProfileAchievements::render($equipper, $equipper);
        self::assertStringContainsString('&lt;hat&gt;', $profile);
        self::assertStringContainsString(html(href('Badger', 'ProfileIcon', '&achievement=70')), $profile);
        self::assertSame('9', ProfileAchievements::iconId($equipper, '70'));
        $card = \GDO\UI\GDT_Card::make();
        \GDO\Badger\Module_Badger::instance()->hookProfileMainContent($card, $equipper, $equipper);
        self::assertCount(1, $card->getFields());
        self::assertStringContainsString('&lt;hat&gt;', array_values($card->getFields())[0]->renderHTML());
        self::assertSame('', ProfileAchievements::render($viewer, $equipper));
        $hiddenCard = \GDO\UI\GDT_Card::make();
        \GDO\Badger\Module_Badger::instance()->hookProfileMainContent($hiddenCard, $equipper, $viewer);
        self::assertCount(0, $hiddenCard->getFields());
        self::assertNull(ProfileAchievements::iconId($viewer, '70'));
        self::assertStringContainsString('&lt;hat&gt;', ProfileAchievements::render($reviewer, $equipper));
        $db->queryWrite("UPDATE gdo_badger_achieve SET ba_visibility='acl_all', ba_deletion='deleted_user' WHERE ba_id=70");
        self::assertSame('', ProfileAchievements::render($equipper, $equipper));
        self::assertNull(ProfileAchievements::iconId($equipper, '70'));
        $db->queryWrite("UPDATE gdo_badger_achieve SET ba_deletion='not_deleted' WHERE ba_id=70");
        self::assertSame('9', ProfileAchievements::iconId($viewer, '70'));
        $db->queryWrite("INSERT INTO gdo_badger_badge (bb_id, bb_owner, bb_name, bb_verify_cc, bb_staff_approvals_needed, bb_community_approvals_needed) VALUES (80, 11, 'community', 1, 1, 2)");
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (81, 'member1'), (82, 'member2'), (83, 'recipient')");
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status, ba_deletion, ba_visibility, ba_is_staff) VALUES (80, 80, 83, 'pending', 'not_deleted', 'acl_all', 0), (81, 80, 81, 'claimed', 'not_deleted', 'acl_all', 0), (82, 80, 82, 'claimed', 'not_deleted', 'acl_all', 0)");
        $communityBadge = GDO_Badge::blank(['bb_id' => '80', 'bb_owner' => '11', 'bb_verify_cc' => '1']);
        $communityTarget = GDO_Achieve::blank(['ba_id' => '80', 'ba_badge' => '80', 'ba_user' => '83', 'ba_status' => 'pending']);
        $member1 = GDO_User::blank(['user_id' => '81', 'user_type' => 'member']);
        $member2 = GDO_User::blank(['user_id' => '82', 'user_type' => 'member']);
        self::assertTrue(CommunityApproval::eligible($member1, $communityBadge, $communityTarget));
        $guestHolder = GDO_User::blank(['user_id' => '81', 'user_type' => 'guest']);
        self::assertFalse(CommunityApproval::eligible($guestHolder, $communityBadge, $communityTarget));
        try
        {
            CommunityApproval::approve($guestHolder, $communityBadge, $communityTarget);
            self::fail('A guest cannot cast a community vote');
        }
        catch (\RuntimeException $ex) { self::assertSame('Permission denied', $ex->getMessage()); }
        GDO_User::setCurrent($member1);
        $queue = CommunityQueue::make()->gdoQuery()->exec()->fetchAllAssoc();
        self::assertSame(['80'], array_column($queue, 'ba_id'));
        CommunityApproval::approve($member1, $communityBadge, $communityTarget);
        $queue = CommunityQueue::make()->gdoQuery()->exec()->fetchAllAssoc();
        self::assertSame([], array_column($queue, 'ba_id'));
        try
        {
            CommunityApproval::approve($member1, $communityBadge, $communityTarget);
            self::fail('Duplicate community approval must fail');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Community member already approved this round', $ex->getMessage());
        }
        BadgeStaff::set($reviewer, $communityBadge, $member1, true);
        try
        {
            AchievementActions::approve($member1, $communityBadge, $communityTarget);
            self::fail('A community voter must not count again after promotion');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Actor already approved in another role', $ex->getMessage());
        }
        BadgeStaff::set($reviewer, $communityBadge, $member1, false);
        AchievementActions::approve($reviewer, $communityBadge, $communityTarget);
        $state = $db->queryRead('SELECT ba_status, ba_staff_approvals, ba_community_approvals FROM gdo_badger_achieve WHERE ba_id=80')->fetch_assoc();
        self::assertSame('pending', $state['ba_status']);
        self::assertSame('1', $state['ba_staff_approvals']);
        self::assertSame('1', $state['ba_community_approvals']);
        CommunityApproval::approve($member2, $communityBadge, $communityTarget);
        $state = $db->queryRead('SELECT ba_status, ba_community_approvals FROM gdo_badger_achieve WHERE ba_id=80')->fetch_assoc();
        self::assertSame('verified', $state['ba_status']);
        self::assertSame('2', $state['ba_community_approvals']);
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (84, 'recipient2'), (85, 'recipient3')");
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status, ba_deletion, ba_visibility, ba_is_staff) VALUES (84, 80, 84, 'pending', 'not_deleted', 'acl_all', 0), (85, 80, 85, 'pending', 'not_deleted', 'acl_noone', 0)");
        $reverse = GDO_Achieve::blank(['ba_id' => '84', 'ba_badge' => '80', 'ba_user' => '84', 'ba_status' => 'pending']);
        AchievementActions::approve($reviewer, $communityBadge, $reverse);
        CommunityApproval::approve($member1, $communityBadge, $reverse);
        CommunityApproval::approve($member2, $communityBadge, $reverse);
        $state = $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=84')->fetch_assoc();
        self::assertSame('verified', $state['ba_status']);
        $private = GDO_Achieve::blank(['ba_id' => '85', 'ba_badge' => '80', 'ba_user' => '85', 'ba_status' => 'pending']);
        $queue = CommunityQueue::make()->gdoQuery()->exec()->fetchAllAssoc();
        self::assertNotContains('85', array_column($queue, 'ba_id'));
        try
        {
            CommunityApproval::approve($member1, $communityBadge, $private);
            self::fail('Community members cannot review a private request');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Permission denied', $ex->getMessage());
        }
        $db->queryWrite("UPDATE gdo_badger_achieve SET ba_visibility='acl_all' WHERE ba_id=85");
        $queue = CommunityQueue::make()->gdoQuery()->exec()->fetchAllAssoc();
        self::assertContains('85', array_column($queue, 'ba_id'));
        CommunityApproval::approve($member1, $communityBadge, $private);
        AchievementActions::transition($reviewer, $communityBadge, $private, 'rejected', 'Try again');
        $private->setVar('ba_status', 'rejected');
        AchievementActions::transition(GDO_User::blank(['user_id' => '85', 'user_type' => 'member']), $communityBadge, $private, 'pending');
        $private->setVar('ba_status', 'pending');
        $private->setVar('ba_round', '1');
        $state = $db->queryRead('SELECT ba_round, ba_staff_approvals, ba_community_approvals FROM gdo_badger_achieve WHERE ba_id=85')->fetch_assoc();
        self::assertSame(['ba_round' => '1', 'ba_staff_approvals' => '0', 'ba_community_approvals' => '0'], $state);
        CommunityApproval::approve($member1, $communityBadge, $private);
        $oldVotes = $db->queryRead("SELECT COUNT(*) AS n FROM gdo_badger_attest WHERE bat_achieve=85 AND bat_actor_type='community' AND bat_actor=81")->fetch_assoc();
        self::assertSame('2', $oldVotes['n']);
        $db->queryWrite("INSERT INTO gdo_badger_badge (bb_id, bb_owner, bb_name, bb_verify_doc) VALUES (90, 11, 'document', 1)");
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (91, 'evidence-owner')");
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status, ba_deletion, ba_visibility) VALUES (90, 90, 91, 'viewed', 'not_deleted', 'acl_all')");
        $docBadge = GDO_Badge::blank(['bb_id' => '90', 'bb_owner' => '11', 'bb_verify_doc' => '1']);
        $docTarget = GDO_Achieve::blank(['ba_id' => '90', 'ba_badge' => '90', 'ba_user' => '91', 'ba_status' => 'viewed']);
        $docUser = GDO_User::blank(['user_id' => '91', 'user_type' => 'member']);
        $upload = \GDO\Badger\Method\EvidenceUpload::make()->inputs(['achievement' => '90']);
        $error = ''; $args = [];
        self::assertTrue($upload->hasPermission($docUser, $error, $args));
        self::assertFalse($upload->hasPermission($reviewer, $error, $args));
        $uploadForm = $upload->getForm();
        self::assertSame(' enctype="multipart/form-data"', $uploadForm->htmlEnctype());
        self::assertNotNull($uploadForm->getField('xsrf'));
        $uploadField = $uploadForm->getField('evidence_upload');
        self::assertInstanceOf(\GDO\Badger\GDT_EvidenceUpload::class, $uploadField);
        $_FILES['evidence_upload'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__];
        try
        {
            self::assertNull($uploadField->getVar());
            self::assertFalse($uploadField->validate('uploaded'));
        }
        finally { unset($_FILES['evidence_upload']); }
        try
        {
            $db->queryWrite("UPDATE gdo_badger_badge SET bb_verify_ai='Review the claim', bb_verify_ai_doc='Review the evidence' WHERE bb_id=90");
            AchievementActions::transition($docUser, $docBadge, $docTarget, 'pending', null, true, true, \GDO\Badger\AIConsent::fingerprint());
            self::fail('Required evidence must be present before submission');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Evidence required', $ex->getMessage());
        }
        $vault = sys_get_temp_dir() . '/badger-attach-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($vault, 0700));
        $key = null;
        try
        {
            $evidence = EvidenceAttachments::attach($docUser, $docBadge, $docTarget, $vault, '%PDF-1.4', 1000, ['application/pdf'], fn () => true);
            $key = $evidence->gdoVar('be_key');
            self::assertSame('1', $evidence->gdoVar('be_round'));
            self::assertSame('%PDF-1.4', EvidenceVault::read($vault, $key));
            try
            {
                EvidenceAttachments::attach($docUser, $docBadge, $docTarget, $vault, '%PDF-1.4', 1000, ['application/pdf'], fn () => true);
                self::fail('Duplicate evidence for a round must fail');
            }
            catch (\RuntimeException $ex)
            {
                self::assertSame('Evidence already attached for this round', $ex->getMessage());
            }
            self::assertCount(3, scandir($vault));
            AchievementActions::transition($docUser, $docBadge, $docTarget, 'pending', null, true, true, \GDO\Badger\AIConsent::fingerprint());
            $state = $db->queryRead('SELECT ba_status, ba_round FROM gdo_badger_achieve WHERE ba_id=90')->fetch_assoc();
            self::assertSame(['ba_status' => 'pending', 'ba_round' => '1'], $state);
            $aiInput = \GDO\Badger\AIReviewInput::capture('90', $vault);
            self::assertSame('%PDF-1.4', $aiInput['document']);
            self::assertSame(hash('sha256', '%PDF-1.4'), $aiInput['request']['document_sha256']);
            self::assertSame(1, $aiInput['request']['round']);
            $db->queryWrite("UPDATE gdo_badger_badge SET bb_verify_ai='Changed rubric' WHERE bb_id=90");
            self::assertNotSame($aiInput['fingerprint'], \GDO\Badger\AIReviewInput::capture('90', $vault)['fingerprint']);
            try
            {
                \GDO\Badger\AIReviewRecommendation::record('90', $vault, $aiInput['fingerprint'], 'test', 'mock', 'approve', 'Looks good');
                self::fail('A result for changed inputs must not be recorded');
            }
            catch (\RuntimeException $ex) { self::assertSame('AI review changed concurrently', $ex->getMessage()); }
            $freshInput = \GDO\Badger\AIReviewInput::capture('90', $vault);
            \GDO\Badger\AIReviewRecommendation::record('90', $vault, $freshInput['fingerprint'], 'test', 'mock', 'approve', 'Looks good');
            $recommendation = $db->queryRead("SELECT bat_reason, bat_metadata FROM gdo_badger_attest WHERE bat_achieve=90 AND bat_decision='recommendation_approve'")->fetch_assoc();
            self::assertSame('Looks good', $recommendation['bat_reason']);
            self::assertSame($freshInput['fingerprint'], json_decode($recommendation['bat_metadata'], true)['snapshot_sha256']);
            self::assertSame('pending', $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=90')->fetch_assoc()['ba_status']);
            $provider = new class implements \GDO\Badger\AIReviewProvider {
                public function name(): string { return 'test'; }
                public function review(array $request, ?string $document): array
                {
                    if ($document !== '%PDF-1.4') { throw new \RuntimeException('Unexpected document'); }
                    return ['model' => 'mock', 'decision' => 'approve', 'rationale' => 'Looks good'];
                }
            };
            self::assertFalse(\GDO\Badger\AIReviewRunner::run($provider, '90', $vault));
            $privacyProvider = new class implements \GDO\Badger\AIReviewProvider {
                public array $received = [];
                public function name(): string { return 'privacy-test'; }
                public function review(array $request, ?string $document): array
                {
                    $this->received = $request;
                    if ($document !== '%PDF-1.4') { throw new \RuntimeException('Unexpected document'); }
                    return ['model' => 'mock', 'decision' => 'approve', 'rationale' => 'Looks good'];
                }
            };
            self::assertTrue(\GDO\Badger\AIReviewRunner::run($privacyProvider, '90', $vault));
            self::assertArrayNotHasKey('recipient', $privacyProvider->received);
            self::assertArrayNotHasKey('achievement', $privacyProvider->received);
            self::assertArrayNotHasKey('badge', $privacyProvider->received);
            self::assertArrayNotHasKey('round', $privacyProvider->received);
            self::assertArrayNotHasKey('document_sha256', $privacyProvider->received);
            self::assertSame('Changed rubric', $privacyProvider->received['prompt']);
            self::assertSame('application/pdf', $privacyProvider->received['document_mime']);
            self::assertNull(\GDO\Badger\AIReviewProviderFactory::create(''));
            try
            {
                \GDO\Badger\AIReviewProviderFactory::create(\stdClass::class);
                self::fail('Only provider implementations may be configured');
            }
            catch (\RuntimeException $ex) { self::assertSame('Invalid AL provider configuration', $ex->getMessage()); }
            $recommendationCount = $db->queryRead("SELECT COUNT(*) AS n FROM gdo_badger_attest WHERE bat_achieve=90 AND bat_decision='recommendation_approve'")->fetch_assoc();
            self::assertSame('2', $recommendationCount['n']);
            $db->queryWrite('UPDATE gdo_badger_badge SET bb_verify_ai=NULL, bb_verify_ai_doc=NULL WHERE bb_id=90');
            GDO_User::setCurrent($reviewer);
            $documentEdit = Edit::make()->inputs(['badge' => '90']);
            $documentForm = $documentEdit->getForm();
            $documentForm->getField('bb_verify_doc')->var('0');
            $documentEdit->formValidated($documentForm);
            self::assertSame('1', $db->queryRead('SELECT bb_verify_doc FROM gdo_badger_badge WHERE bb_id=90')->fetch_assoc()['bb_verify_doc']);
            self::assertSame('%PDF-1.4', \GDO\Badger\EvidenceAccess::read($docUser, '90', $vault));
            self::assertSame('%PDF-1.4', \GDO\Badger\EvidenceAccess::read($reviewer, '90', $vault));
            try
            {
                \GDO\Badger\EvidenceAccess::read(GDO_User::blank(['user_id' => '91', 'user_type' => 'guest']), '90', $vault);
                self::fail('A guest identity must not access a member evidence record');
            }
            catch (\RuntimeException $ex) { self::assertSame('Evidence unavailable', $ex->getMessage()); }
            try
            {
                \GDO\Badger\EvidenceAccess::read(GDO_User::blank(['user_id' => '84', 'user_type' => 'member']), '90', $vault);
                self::fail('An unrelated member must not access evidence');
            }
            catch (\RuntimeException $ex)
            {
                self::assertSame('Evidence unavailable', $ex->getMessage());
            }
            $docTarget->setVar('ba_status', 'pending')->setVar('ba_round', '1');
            try
            {
                \GDO\Badger\EvidenceApproval::approve($docUser, $docTarget, $vault);
                self::fail('The recipient cannot approve their own document');
            }
            catch (\RuntimeException $ex)
            {
                self::assertSame('Permission denied', $ex->getMessage());
            }
            \GDO\Badger\EvidenceApproval::approve($reviewer, $docTarget, $vault);
            self::assertTrue(\GDO\Badger\EvidenceApproval::approved('90', 1));
            self::assertSame('pending', $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=90')->fetch_assoc()['ba_status']);
            try
            {
                \GDO\Badger\EvidenceApproval::approve($reviewer, $docTarget, $vault);
                self::fail('Duplicate document approval must fail');
            }
            catch (\RuntimeException $ex)
            {
                self::assertSame('Document already approved', $ex->getMessage());
            }
            AchievementActions::approve($reviewer, $docBadge, $docTarget);
            self::assertSame('verified', $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=90')->fetch_assoc()['ba_status']);
            $cleanupModule = \GDO\Badger\Module_Badger::instance();
            $cleanupOriginalId = $cleanupModule->getID();
            $cleanupOriginalCursor = $cleanupModule->getConfigVar('bb_evidence_cleanup_cursor');
            $cleanupModule->setVar('module_id', $db->queryRead('SELECT module_id FROM gdo_module ORDER BY module_id LIMIT 1')->fetch_assoc()['module_id']);
            self::assertSame(1, \GDO\Badger\EvidenceCleanup::run($vault, 604800, time()));
            self::assertFalse(is_file($vault . '/' . $key));
            $afterCleanup = $db->queryRead('SELECT be_deleted, be_sha256 FROM gdo_badger_evidence WHERE be_achieve=90')->fetch_assoc();
            self::assertSame('1', $afterCleanup['be_deleted']);
            self::assertSame(hash('sha256', '%PDF-1.4'), $afterCleanup['be_sha256']);
            self::assertSame(0, \GDO\Badger\EvidenceCleanup::run($vault, 604800, time()));
            $secondEvidence = EvidenceVault::store($vault, '%PDF-1.5', 1000, ['application/pdf'], fn () => true);
            $secondKey = $secondEvidence['key'];
            try
            {
                $db->queryWrite("INSERT INTO gdo_badger_evidence (be_achieve, be_round, be_key, be_sha256, be_mime, be_size) VALUES (90, 2, '{$secondKey}', '{$secondEvidence['sha256']}', 'application/pdf', 8)");
                self::assertSame(0, \GDO\Badger\EvidenceCleanup::run($vault, 604800, time()));
                $db->queryWrite("INSERT INTO gdo_badger_attest (bat_achieve, bat_actor_type, bat_decision, bat_round, bat_to_status, bat_created) VALUES (90, 'staff', 'rejected', 2, 'rejected', NOW())");
                self::assertSame(0, \GDO\Badger\EvidenceCleanup::run($vault, 604800, time()));
                self::assertTrue(is_file($vault . '/' . $secondKey));
                $db->queryWrite("UPDATE gdo_badger_achieve SET ba_status='rejected', ba_round=2 WHERE ba_id=90");
                $rejectedTarget = GDO_Achieve::blank(['ba_id' => '90', 'ba_badge' => '90', 'ba_user' => $docUser->getID(), 'ba_status' => 'rejected', 'ba_round' => '2']);
                self::assertTrue(\GDO\Badger\EvidenceAccess::hasCurrentEvidence($rejectedTarget));
                GDO_Achieve::getById('90')?->uncache();
                GDO_User::setCurrent($reviewer);
                $rejectedForm = Achieve::make()->inputs(['achievement' => '90'])->getForm();
                self::assertContains('delete_evidence', $rejectedForm->getField('action')->enumValues);
                GDO_User::setCurrent($docUser);
                $recipientRejectedForm = Achieve::make()->inputs(['achievement' => '90'])->getForm();
                self::assertNotContains('delete_evidence', $recipientRejectedForm->getField('action')->enumValues);
                self::assertSame('%PDF-1.5', \GDO\Badger\EvidenceAccess::read($reviewer, '90', $vault));
                try
                {
                    \GDO\Badger\EvidenceDeletion::removeRejected($docUser, $rejectedTarget, $vault);
                    self::fail('The recipient must not delete rejected evidence as staff');
                }
                catch (\RuntimeException $ex) { self::assertSame('Evidence unavailable', $ex->getMessage()); }
                self::assertTrue(is_file($vault . '/' . $secondKey));
                \GDO\Badger\EvidenceDeletion::removeRejected($reviewer, $rejectedTarget, $vault);
                self::assertFalse(is_file($vault . '/' . $secondKey));
                $retained = $db->queryRead('SELECT be_deleted, be_sha256 FROM gdo_badger_evidence WHERE be_achieve=90 AND be_round=2')->fetch_assoc();
                self::assertSame('1', $retained['be_deleted']);
                self::assertSame($secondEvidence['sha256'], $retained['be_sha256']);
                self::assertFalse(\GDO\Badger\EvidenceAccess::hasCurrentEvidence($rejectedTarget));
                GDO_User::setCurrent($reviewer);
                $deletedForm = Achieve::make()->inputs(['achievement' => '90'])->getForm();
                self::assertNotContains('delete_evidence', $deletedForm->getField('action')->enumValues);
                $thirdEvidence = EvidenceVault::store($vault, '%PDF-1.6', 1000, ['application/pdf'], fn () => true);
                $thirdKey = $thirdEvidence['key'];
                $db->queryWrite("INSERT INTO gdo_badger_evidence (be_achieve, be_round, be_key, be_sha256, be_mime, be_size) VALUES (90, 3, '{$thirdKey}', '{$thirdEvidence['sha256']}', 'application/pdf', 8)");
                $db->queryWrite("INSERT INTO gdo_badger_attest (bat_achieve, bat_actor_type, bat_decision, bat_round, bat_to_status, bat_created) VALUES (90, 'staff', 'rejected', 3, 'rejected', NOW())");
                self::assertSame(1, \GDO\Badger\EvidenceCleanup::run($vault, 604800, time() + 604801));
                self::assertFalse(is_file($vault . '/' . $thirdKey));
            }
            finally { EvidenceVault::remove($vault, $secondKey); }
            $faultEvidence = EvidenceVault::store($vault, '%PDF-1.7', 1000, ['application/pdf'], fn () => true);
            $laterEvidence = EvidenceVault::store($vault, '%PDF-1.8', 1000, ['application/pdf'], fn () => true);
            $faultPath = $vault . '/' . $faultEvidence['key'];
            try
            {
                $db->queryWrite("INSERT INTO gdo_badger_evidence (be_achieve, be_round, be_key, be_sha256, be_mime, be_size) VALUES (90, 4, '{$faultEvidence['key']}', '{$faultEvidence['sha256']}', 'application/pdf', 8), (90, 5, '{$laterEvidence['key']}', '{$laterEvidence['sha256']}', 'application/pdf', 8)");
                $db->queryWrite("INSERT INTO gdo_badger_attest (bat_achieve, bat_actor_type, bat_decision, bat_round, bat_to_status, bat_created) VALUES (90, 'staff', 'rejected', 4, 'rejected', NOW()), (90, 'staff', 'rejected', 5, 'rejected', NOW())");
                self::assertTrue(unlink($faultPath));
                self::assertTrue(symlink($vault . '/' . $laterEvidence['key'], $faultPath));
                $cleanupFailures = 0;
                $onCleanupError = static function () use (&$cleanupFailures): void { $cleanupFailures++; };
                self::assertSame(0, \GDO\Badger\EvidenceCleanup::run($vault, 604800, time() + 604801, 1, $onCleanupError));
                self::assertSame(1, $cleanupFailures);
                self::assertSame(1, \GDO\Badger\EvidenceCleanup::run($vault, 604800, time() + 604801, 1, $onCleanupError));
                self::assertFalse(is_file($vault . '/' . $laterEvidence['key']));
                self::assertTrue(unlink($faultPath));
                file_put_contents($faultPath, '%PDF-1.7');
                chmod($faultPath, 0600);
                self::assertSame(1, \GDO\Badger\EvidenceCleanup::run($vault, 604800, time() + 604801, 1, $onCleanupError));
                self::assertFalse(is_file($faultPath));
            }
            finally
            {
                if (is_link($faultPath)) { unlink($faultPath); }
                EvidenceVault::remove($vault, $faultEvidence['key']);
                EvidenceVault::remove($vault, $laterEvidence['key']);
            }
            $cleanupModule->setVar('module_id', $cleanupOriginalId);
            $cleanupModule->getConfigColumn('bb_evidence_cleanup_cursor')->var($cleanupOriginalCursor);
        }
        finally
        {
            if ($key)
            {
                EvidenceVault::remove($vault, $key);
            }
            rmdir($vault);
        }
        $db->queryWrite("INSERT INTO gdo_badger_badge (bb_id, bb_owner, bb_name) VALUES (92, 11, 'transfer')");
        $db->queryWrite("INSERT INTO gdo_badger_badge (bb_id, bb_owner, bb_name, bb_verify_ai, bb_approve_ai) VALUES (93, 11, 'AL test', 'Review', 1)");
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status) VALUES (93, 93, 91, 'viewed')");
        $aiBadge = GDO_Badge::blank(['bb_id' => '93', 'bb_owner' => '11']);
        $aiTarget = GDO_Achieve::blank(['ba_id' => '93', 'ba_badge' => '93', 'ba_user' => '91', 'ba_status' => 'viewed']);
        try
        {
            AchievementActions::transition($docUser, $aiBadge, $aiTarget, 'pending', 'Evidence in words');
            self::fail('AI review requires explicit recipient consent');
        }
        catch (\RuntimeException $ex) { self::assertSame('AI consent required', $ex->getMessage()); }
        try
        {
            AchievementActions::transition($docUser, $aiBadge, $aiTarget, 'pending', 'Evidence in words', true, true, str_repeat('0', 64));
            self::fail('A stale provider disclosure must not authorize submission');
        }
        catch (\RuntimeException $ex) { self::assertSame('AI consent required', $ex->getMessage()); }
        GDO_User::setCurrent($docUser);
        $aiSubmit = Achieve::make()->inputs(['achievement' => '93', 'action' => 'submit', 'claim_message' => 'Evidence in words', 'ai_consent' => '1', 'ai_consent_fingerprint' => \GDO\Badger\AIConsent::fingerprint(), 'visibility' => 'acl_all', 'submit' => '1']);
        $aiSubmitForm = $aiSubmit->getForm();
        self::assertNotEmpty(array_filter($aiSubmitForm->getAllFields(), static fn($field) => $field instanceof \GDO\UI\GDT_HTML));
        self::assertNotNull($aiSubmitForm->getField('ai_consent'));
        $aiToken = $aiSubmitForm->getField('xsrf')->csrfToken();
        $aiSubmitForm->inputs(['xsrf' => $aiToken]);
        $aiSubmit->inputs(['xsrf' => $aiToken]);
        $aiSubmit->execute();
        $savedConsent = $db->queryRead("SELECT bat_metadata FROM gdo_badger_attest WHERE bat_achieve=93 AND bat_to_status='pending'")->fetch_assoc();
        self::assertTrue(json_decode($savedConsent['bat_metadata'], true)['ai_consent']);
        $aiTarget->setVar('ba_status', 'pending')->setVar('ba_round', '1');
        $input = \GDO\Badger\AIReviewInput::capture('93', '');
        \GDO\Badger\AIReviewRecommendation::record('93', '', $input['fingerprint'], 'test', 'mock', 'approve', 'Meets rubric');
        self::assertSame('pending', $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=93')->fetch_assoc()['ba_status']);
        AchievementActions::approve($reviewer, $aiBadge, $aiTarget);
        self::assertSame('verified', $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=93')->fetch_assoc()['ba_status']);
        $db->queryWrite("INSERT INTO gdo_badger_badge (bb_id, bb_owner, bb_name, bb_verify_ai, bb_approve_ai) VALUES (94, 11, 'AL stale', 'Review', 1)");
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status) VALUES (94, 94, 91, 'viewed')");
        $staleBadge = GDO_Badge::blank(['bb_id' => '94', 'bb_owner' => '11']);
        $staleTarget = GDO_Achieve::blank(['ba_id' => '94', 'ba_badge' => '94', 'ba_user' => '91', 'ba_status' => 'viewed']);
        AchievementActions::transition($docUser, $staleBadge, $staleTarget, 'pending', 'Hello', true, true, \GDO\Badger\AIConsent::fingerprint());
        $staleTarget->setVar('ba_status', 'pending')->setVar('ba_round', '1');
        $staleInput = \GDO\Badger\AIReviewInput::capture('94', '');
        \GDO\Badger\AIReviewRecommendation::record('94', '', $staleInput['fingerprint'], 'test', 'mock', 'approve', 'Approved');
        $db->queryWrite("UPDATE gdo_badger_badge SET bb_verify_ai='Changed' WHERE bb_id=94");
        AchievementActions::approve($reviewer, $staleBadge, $staleTarget);
        self::assertSame('pending', $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=94')->fetch_assoc()['ba_status']);
        $db->queryWrite("INSERT INTO gdo_badger_badge (bb_id, bb_owner, bb_name, bb_verify_ai, bb_approve_ai) VALUES (96, 11, 'AL manual', 'Review', 0)");
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status) VALUES (197, 96, 91, 'viewed')");
        $manualBadge = GDO_Badge::blank(['bb_id' => '96', 'bb_owner' => '11']);
        $manualTarget = GDO_Achieve::blank(['ba_id' => '197', 'ba_badge' => '96', 'ba_user' => '91', 'ba_status' => 'viewed']);
        AchievementActions::transition($docUser, $manualBadge, $manualTarget, 'pending', 'Hello', true, true, \GDO\Badger\AIConsent::fingerprint());
        $manualTarget->setVar('ba_status', 'pending')->setVar('ba_round', '1');
        $manualInput = \GDO\Badger\AIReviewInput::capture('197', '');
        \GDO\Badger\AIReviewRecommendation::record('197', '', $manualInput['fingerprint'], 'test', 'mock', 'approve', 'Looks good');
        try
        {
            \GDO\Badger\AIReviewHumanApproval::accept($docUser, $manualTarget, '');
            self::fail('The recipient cannot accept the AI review');
        }
        catch (\RuntimeException $ex) { self::assertSame('Permission denied', $ex->getMessage()); }
        \GDO\Badger\AIReviewHumanApproval::accept($reviewer, $manualTarget, '');
        self::assertSame('pending', $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=197')->fetch_assoc()['ba_status']);
        AchievementActions::approve($reviewer, $manualBadge, $manualTarget);
        self::assertSame('verified', $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=197')->fetch_assoc()['ba_status']);
        $db->queryWrite("INSERT INTO gdo_badger_badge (bb_id, bb_owner, bb_name, bb_verify_ai, bb_approve_ai) VALUES (195, 11, 'AL rejection', 'Review', 1)");
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status, ba_is_staff) VALUES (195, 195, 91, 'viewed', 0), (196, 195, 14, 'viewed', 1)");
        $rejectBadge = GDO_Badge::blank(['bb_id' => '195', 'bb_owner' => '11']);
        $rejectTarget = GDO_Achieve::blank(['ba_id' => '195', 'ba_badge' => '195', 'ba_user' => '91', 'ba_status' => 'viewed']);
        AchievementActions::transition($docUser, $rejectBadge, $rejectTarget, 'pending', 'Please review', true, true, \GDO\Badger\AIConsent::fingerprint());
        $rejectInput = \GDO\Badger\AIReviewInput::capture('195', '');
        \GDO\Badger\AIReviewRecommendation::record('195', '', $rejectInput['fingerprint'], 'test', 'mock', 'reject', 'Missing requirement');
        self::assertSame('rejected', $db->queryRead('SELECT ba_status FROM gdo_badger_achieve WHERE ba_id=195')->fetch_assoc()['ba_status']);
        $notices = $db->queryRead('SELECT bn_user FROM gdo_badger_review_notice ORDER BY bn_user')->fetch_all(MYSQLI_ASSOC);
        self::assertSame(['11', '14'], array_column($notices, 'bn_user'));
        $testModule = \GDO\Badger\Module_Badger::instance();
        $testModuleOriginalId = $testModule->getID();
        $testModuleOriginalCursor = $testModule->getConfigVar('bb_notice_cursor');
        $testModuleId = $db->queryRead('SELECT module_id FROM gdo_module ORDER BY module_id LIMIT 1')->fetch_assoc()['module_id'];
        $testModule->setVar('module_id', $testModuleId);
        $testModule->getConfigColumn('bb_notice_cursor')->var('0:0');
        $noticeErrors = 0;
        $failed = \GDO\Badger\ReviewNotices::deliver(100, static function (): void { throw new \RuntimeException('delivery failed'); }, static function () use (&$noticeErrors): void { $noticeErrors++; });
        self::assertSame(0, $failed);
        self::assertSame(2, $noticeErrors);
        self::assertSame('0', $db->queryRead('SELECT COUNT(*) AS n FROM gdo_badger_review_notice WHERE bn_delivered=1')->fetch_assoc()['n']);
        $testModule->getConfigColumn('bb_notice_cursor')->var('0:0');
        self::assertSame(0, \GDO\Badger\ReviewNotices::deliver(1, static function (): void { throw new \RuntimeException('delivery failed'); }, static function (): void {}));
        self::assertStringEndsWith(':11', $testModule->getConfigVar('bb_notice_cursor'));
        $laterNotice = [];
        self::assertSame(1, \GDO\Badger\ReviewNotices::deliver(1, static function (string $userId) use (&$laterNotice): void { $laterNotice[] = $userId; }));
        self::assertSame(['14'], $laterNotice);
        $db->queryWrite('UPDATE gdo_badger_review_notice SET bn_delivered=0 WHERE bn_user=14');
        $testModule->getConfigColumn('bb_notice_cursor')->var('0:0');
        $sameBatch = [];
        self::assertSame(1, \GDO\Badger\ReviewNotices::deliver(100, static function (string $userId) use (&$sameBatch): void {
            if ($userId === '11') { throw new \RuntimeException('simulated failure'); }
            $sameBatch[] = $userId;
        }, static function (): void {}));
        self::assertSame(['14'], $sameBatch);
        $storedNoticeCursor = $db->queryRead("SELECT mv_value FROM gdo_modulevar WHERE mv_module=" . (int) $testModuleId . " AND mv_name='bb_notice_cursor'")->fetch_assoc();
        self::assertSame($testModule->getConfigVar('bb_notice_cursor'), $storedNoticeCursor['mv_value']);
        $retried = [];
        self::assertSame(1, \GDO\Badger\ReviewNotices::deliver(1, static function (string $userId) use (&$retried): void { $retried[] = $userId; }));
        self::assertSame(['11'], $retried);
        $db->queryWrite('UPDATE gdo_badger_review_notice SET bn_delivered=0 WHERE bn_user=11');
        $db->queryWrite('UPDATE gdo_badger_review_notice SET bn_delivered=0 WHERE bn_user=14');
        $db->queryWrite('UPDATE gdo_badger_achieve SET ba_is_staff=0 WHERE ba_id=196');
        $sentNotices = [];
        $sent = \GDO\Badger\ReviewNotices::deliver(100, static function (string $userId, string $achievementId) use (&$sentNotices): void { $sentNotices[] = [$userId, $achievementId]; });
        self::assertSame(1, $sent);
        self::assertSame([['11', '195']], $sentNotices);
        self::assertSame(0, \GDO\Badger\ReviewNotices::deliver(100, static function (): void { throw new \RuntimeException('duplicate'); }));
        $db->queryWrite('UPDATE gdo_badger_review_notice SET bn_delivered=0 WHERE bn_user=11');
        self::assertSame(1, \GDO\Badger\ReviewNotices::deliver());
        $inbox = $db->queryRead('SELECT pm_to, pm_title, pm_message_input FROM gdo_pm WHERE pm_owner=11 AND pm_folder=1')->fetch_assoc();
        self::assertSame('11', $inbox['pm_to']);
        self::assertStringContainsString('195', $inbox['pm_message_input']);
        self::assertStringNotContainsString('Missing requirement', $inbox['pm_message_input']);
        self::assertSame(0, \GDO\Badger\ReviewNotices::deliver());
        self::assertSame('1', $db->queryRead('SELECT COUNT(*) AS n FROM gdo_pm WHERE pm_owner=11 AND pm_folder=1')->fetch_assoc()['n']);
        $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_badge, ba_user, ba_status, ba_is_staff) VALUES (195, 454, 'viewed', 1)");
        $db->queryWrite('INSERT INTO gdo_badger_review_notice (bn_attest, bn_user, bn_delivered) SELECT bn_attest, 454, 0 FROM gdo_badger_review_notice WHERE bn_user=11 LIMIT 1');
        $inactiveNoticeSent = false;
        self::assertSame(0, \GDO\Badger\ReviewNotices::deliver(100, static function () use (&$inactiveNoticeSent): void { $inactiveNoticeSent = true; }));
        self::assertFalse($inactiveNoticeSent);
        self::assertSame('1', $db->queryRead('SELECT bn_delivered FROM gdo_badger_review_notice WHERE bn_user=454')->fetch_assoc()['bn_delivered']);
        $db->queryWrite("INSERT INTO gdo_user (user_id, user_name) VALUES (92, 'slotbuyer')");
        $slotBuyer = GDO_User::blank(['user_id' => '92', 'user_type' => 'member']);
        \GDO\Badger\EquipmentPurchase::buy($slotBuyer, 50, 200);
        $slotSettings = $db->queryRead("SELECT uset_name, uset_var FROM gdo_usersetting WHERE uset_user=92 ORDER BY uset_name")->fetch_all(MYSQLI_ASSOC);
        self::assertSame(['bb_add_max_equip', 'bb_welcome_granted', 'credits'], array_column($slotSettings, 'uset_name'));
        self::assertSame(['1', '1', '150'], array_column($slotSettings, 'uset_var'));
        try
        {
            \GDO\Badger\EquipmentPurchase::buy($slotBuyer, 1000, 200);
            self::fail('Insufficient credits must not buy a slot');
        }
        catch (\RuntimeException $ex) { self::assertSame('Insufficient credits', $ex->getMessage()); }
        $afterFailedPurchase = $db->queryRead("SELECT uset_name, uset_var FROM gdo_usersetting WHERE uset_user=92 ORDER BY uset_name")->fetch_all(MYSQLI_ASSOC);
        self::assertSame($slotSettings, $afterFailedPurchase);
        $transferBadge = GDO_Badge::blank(['bb_id' => '92', 'bb_owner' => '11']);
        try
        {
            \GDO\Badger\BadgeOwnership::transfer($docUser, $transferBadge, $docUser);
            self::fail('A non-owner cannot transfer a badge');
        }
        catch (\RuntimeException $ex) { self::assertSame('Permission denied', $ex->getMessage()); }
        \GDO\Badger\BadgeOwnership::transfer($reviewer, $transferBadge, $docUser);
        self::assertSame('91', $db->queryRead('SELECT bb_owner FROM gdo_badger_badge WHERE bb_id=92')->fetch_assoc()['bb_owner']);
        try
        {
            \GDO\Badger\BadgeOwnership::transfer($reviewer, $transferBadge, $reviewer);
            self::fail('The previous owner cannot transfer again');
        }
        catch (\RuntimeException $ex) { self::assertSame('Permission denied', $ex->getMessage()); }
        $db->queryWrite('INSERT INTO gdo_badger_category (bc_id, bc_parent) VALUES (1, NULL), (2, 1), (3, 2)');
        try
        {
            CategoryTree::setParent('1', '3');
            self::fail('A category cycle must be rejected');
        }
        catch (\RuntimeException $ex)
        {
            self::assertSame('Category cycle', $ex->getMessage());
        }
        CategoryTree::setParent('3', '1');
        $row = $db->queryRead('SELECT bc_parent FROM gdo_badger_category WHERE bc_id=3')->fetch_assoc();
        self::assertSame('1', $row['bc_parent']);
        CategoryTree::setParent('3', null);
        $row = $db->queryRead('SELECT bc_parent FROM gdo_badger_category WHERE bc_id=3')->fetch_assoc();
        self::assertNull($row['bc_parent']);
        $newCategory = CategoryTree::create('  Workshops ', 'workshops', null);
        $categoryRow = $db->queryRead('SELECT bc_name, bc_slug, bc_parent FROM gdo_badger_category WHERE bc_id=' . (int)$newCategory->getID())->fetch_assoc();
        self::assertSame('Workshops', $categoryRow['bc_name']);
        self::assertSame('workshops', $categoryRow['bc_slug']);
        self::assertNull($categoryRow['bc_parent']);
        $link = $db->getLink();
        $constraint = GDT_BadgeUserUnique::make('test_unique')->gdoColumnDefine();
        $link->query('CREATE TEMPORARY TABLE badger_unique_test (ba_badge INT NOT NULL, ba_user INT NOT NULL, ' . $constraint . ') ENGINE=InnoDB');
        $link->query('INSERT INTO badger_unique_test VALUES (7, 12)');
        try
        {
            $link->query('INSERT INTO badger_unique_test VALUES (7, 12)');
            self::fail('Duplicate badge/user must be rejected');
        }
        catch (\mysqli_sql_exception $ex)
        {
            self::assertSame(1062, $ex->getCode());
        }
        // Only temporary tables are modified by this worker integration test.
        $db->queryWrite("UPDATE gdo_badger_achieve SET ba_status='withdrawn' WHERE ba_status='pending'");
        $db->queryWrite("INSERT INTO gdo_badger_badge (bb_id, bb_owner, bb_name, bb_verify_ai) VALUES (6000, 11, 'Worker fairness test', 'Test')");
        $module = \GDO\Badger\Module_Badger::instance();
        $originalId = $module->getID();
        $originalProvider = $module->getConfigVar('bb_ai_provider_class');
        $originalCursor = $module->getConfigVar('bb_ai_worker_cursor');
        try
        {
        $moduleId = $db->queryRead('SELECT module_id FROM gdo_module ORDER BY module_id LIMIT 1')->fetch_assoc()['module_id'];
        $module->setVar('module_id', $moduleId);
        $module->getConfigColumn('bb_ai_provider_class')->var(FailingQueueProvider::class);
        $module->getConfigColumn('bb_ai_worker_cursor')->var('0');
        self::assertSame($moduleId, \GDO\Badger\Module_Badger::instance()->getID());
        $model = (string) $module->getConfigVar('bb_ai_ollama_model');
        $consent = \GDO\Core\GDO::quoteS(json_encode(['ai_consent' => true, 'ai_provider_class' => FailingQueueProvider::class, 'ai_provider_model' => $model]));
        for ($i = 1; $i <= 12; $i++)
        {
            $aid = 6000 + $i;
            $db->queryWrite("INSERT INTO gdo_badger_achieve (ba_id, ba_badge, ba_user, ba_status, ba_round) VALUES ({$aid}, 6000, {$aid}, 'pending', 1)");
            $db->queryWrite("INSERT INTO gdo_badger_attest (bat_achieve, bat_round, bat_to_status, bat_metadata) VALUES ({$aid}, 1, 'pending', {$consent})");
        }
        FailingQueueProvider::$calls = 0;
        ob_start();
        try { \GDO\Badger\Method\RunAIReviews::make()->run(); }
        finally { ob_end_clean(); }
        self::assertSame(10, FailingQueueProvider::$calls);
        self::assertSame('6010', $module->getConfigVar('bb_ai_worker_cursor'));
        self::assertSame('6010', $db->queryRead("SELECT mv_value FROM gdo_modulevar WHERE mv_name='bb_ai_worker_cursor'")->fetch_assoc()['mv_value']);
        ob_start();
        try { \GDO\Badger\Method\RunAIReviews::make()->run(); }
        finally { ob_end_clean(); }
        self::assertSame(20, FailingQueueProvider::$calls);
        self::assertSame('6008', $module->getConfigVar('bb_ai_worker_cursor'));
        }
        finally
        {
            $module->setVar('module_id', $originalId);
            $module->getConfigColumn('bb_ai_provider_class')->var($originalProvider);
            $module->getConfigColumn('bb_ai_worker_cursor')->var($originalCursor);
            $testModule->setVar('module_id', $testModuleOriginalId);
            $testModule->getConfigColumn('bb_notice_cursor')->var($testModuleOriginalCursor);
        }
    }
}
