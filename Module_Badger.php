<?php
declare(strict_types=1);

namespace GDO\Badger;

use GDO\Badger\install\BadgerInstall;
use GDO\Core\GDO_Module;
use GDO\Core\Method;
use GDO\Core\GDT_UInt;
use GDO\Core\GDT_String;
use GDO\Core\GDT_Checkbox;
use GDO\UI\GDT_Width;
use GDO\UI\GDT_Height;
use GDO\User\GDT_ACLRelation;
use GDO\User\GDO_User;
use GDO\UI\GDT_Link;
use GDO\UI\GDT_Page;
use GDO\UI\GDT_HTML;
use GDO\UI\GDT_Card;
use GDO\Badger\Method\Badges;
use GDO\Register\GDO_UserActivation;

final class Module_Badger extends GDO_Module
{
    public function onLoadLanguage(): void { $this->loadLanguage('lang/badger'); }
    public function defaultMethod(): Method { return Badges::make(); }

    public function hookUserActivated(GDO_User $user, ?GDO_UserActivation $activation = null): void
    {
        if (!$user->isMember()) { return; }
        global $me;
        BadgeCredits::charge($user, 0, (int) $this->getConfigVar('bb_welcome_credits'), !($me instanceof Method && $me->transactional()));
    }

    public function hookAvatarLayers(GDO_User $user, float $size): ?GDT_HTML
    {
        $html = ItemEquipment::renderLayers($user, (int) $this->getConfigVar('bb_item_max_w'), (int) $this->getConfigVar('bb_item_max_h'));
        return $html === '' ? null : GDT_HTML::make()->var($html);
    }

    public function hookProfileMainContent(GDT_Card $card, GDO_User $target, GDO_User $viewer): void
    {
        $html = ProfileAchievements::render($viewer, $target);
        if ($html !== '')
        {
            $card->addField(GDT_HTML::make()->var($html));
        }
    }

    public function onInitSidebar(): void
    {
        $sidebar = GDT_Page::instance()->leftBar();
        $sidebar->addField(GDT_Link::make()->text('mt_badger_badges')->href(href('Badger', 'Badges')));
        $sidebar->addField(GDT_Link::make()->text('mt_badger_rankings')->href(href('Badger', 'Rankings')));
        if (GDO_User::current()->isMember())
        {
            $sidebar->addField(GDT_Link::make()->text('mt_badger_add')->href(href('Badger', 'Add')));
            $sidebar->addField(GDT_Link::make()->text('mt_badger_myachievements')->href(href('Badger', 'MyAchievements')));
            $sidebar->addField(GDT_Link::make()->text('mt_badger_items')->href(href('Badger', 'Items')));
            $sidebar->addField(GDT_Link::make()->text('mt_badger_reviewqueue')->href(href('Badger', 'ReviewQueue')));
            $sidebar->addField(GDT_Link::make()->text('mt_badger_communityqueue')->href(href('Badger', 'CommunityQueue')));
        }
        if (GDO_User::current()->isStaff())
        {
            $sidebar->addField(GDT_Link::make()->text('mt_badger_badgecrud')->href(href('Badger', 'BadgeCrud')));
            $sidebar->addField(GDT_Link::make()->text('mt_badger_categorytree')->href(href('Badger', 'CategoryTree')));
        }
    }

    public function getClasses(): array
    {
        return [
            GDO_Category::class,
            GDO_Badge::class,
            GDO_BadgeTag::class,
            GDO_Achieve::class,
            GDO_Attest::class,
            GDO_Evidence::class,
            GDO_ReviewNotice::class,
        ];
    }

    public function getDependencies(): array
    {
        return [
            'AboutMe',
            'Admin',
            'Avatar',
            'Bootstrap5Theme',
            'Captcha',
            'Category',
            'CLI',
            'Cronjob',
            'Forum', 'Friends',
            'News',
            'PaymentAdmin', 'PaymentPaypal', 'PaymentCredits',
            'PM',
            'Register',
            'Table','Tags',
        ];
    }

    public function getConfig(): array
    {
        return [
            GDT_UInt::make('bb_welcome_credits')->initial('200'),
            GDT_UInt::make('bb_credits_per_badge')->initial('50'),
            GDT_UInt::make('bb_credits_per_xp')->initial('50'),
            GDT_UInt::make('bb_credits_per_hp')->initial('50'),
            GDT_UInt::make('bb_credits_per_str')->initial('50'),
            GDT_UInt::make('bb_credits_per_int')->initial('50'),
            GDT_UInt::make('bb_credits_per_image')->initial('50'),
            GDT_UInt::make('bb_credits_per_equip_slot')->initial('50'),
            GDT_UInt::make('bb_default_xp')->initial('1'),
            GDT_UInt::make('bb_default_hp')->initial('0'),
            GDT_UInt::make('bb_default_str')->initial('0'),
            GDT_UInt::make('bb_default_int')->initial('0'),
            GDT_UInt::make('bb_default_max_equip')->initial('2'),
            GDT_Checkbox::make('bb_default_verify_staff')->initial('1'),
            GDT_UInt::make('bb_default_staff_approvals')->initial('1')->min(1),
            GDT_UInt::make('bb_default_community_approvals')->initial('1')->min(1),
            GDT_Width::make('bb_item_max_w')->initial('512'),
            GDT_Height::make('bb_item_max_h')->initial('512'),
            GDT_UInt::make('bb_item_max_bytes')->initial('2097152'),
            GDT_UInt::make('bb_evidence_max_bytes')->initial('5242880'),
            GDT_String::make('bb_evidence_allowed_mimes')->initial('application/pdf,image/png,image/jpeg'),
            GDT_String::make('bb_evidence_private_dir'),
            GDT_String::make('bb_evidence_scanner'),
            GDT_UInt::make('bb_evidence_scan_timeout')->initial('15')->min(1)->max(60),
            GDT_UInt::make('bb_rejected_document_delete_after')->initial('604800'),
            GDT_UInt::make('bb_evidence_cleanup_cursor')->initial('0'),
            GDT_UInt::make('bb_claims_per_hour')->initial('20'),
            GDT_UInt::make('bb_community_votes_per_hour')->initial('50'),
            GDT_String::make('bb_ai_provider_class')->max(255),
            GDT_String::make('bb_ai_ollama_model')->max(128),
            GDT_UInt::make('bb_ai_worker_cursor')->initial('0'),
            GDT_String::make('bb_notice_cursor')->max(45)->initial('0:0'),
            GDT_ACLRelation::make('bb_default_achieve_visibility')->initial(GDT_ACLRelation::ALL),
        ];
    }

    public function getUserConfig(): array
    {
        return [GDT_UInt::make('bb_add_max_equip')->initial('0')];
    }

    public function getUserSettings(): array
    {
        return [GDT_ACLRelation::make('bb_default_achieve_visible')
            ->initial($this->getConfigVar('bb_default_achieve_visibility') ?: GDT_ACLRelation::ALL)];
    }

    public function onInstall(): void
    {
        BadgerInstall::install();
    }
}
