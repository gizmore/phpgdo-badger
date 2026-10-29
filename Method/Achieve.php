<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\AchievementActions;
use GDO\Badger\AchievementDeletion;
use GDO\Badger\AchievementRequests;
use GDO\Badger\AchievementHistory;
use GDO\Badger\AchievementLifecycle;
use GDO\Badger\AchievementSharing;
use GDO\Badger\CommunityApproval;
use GDO\Badger\EvidenceApproval;
use GDO\Badger\EvidenceAccess;
use GDO\Badger\EvidenceDeletion;
use GDO\Badger\AIReviewHumanApproval;
use GDO\Badger\AIConsent;
use GDO\Badger\Module_Badger;
use GDO\Badger\BadgerPermissions;
use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDO_Badge;
use GDO\Core\GDT;
use GDO\Core\GDT_Enum;
use GDO\Core\GDT_Checkbox;
use GDO\Core\GDT_Object;
use GDO\Core\GDT_Text;
use GDO\Core\GDT_Tuple;
use GDO\Form\GDT_AntiCSRF;
use GDO\Form\GDT_Hidden;
use GDO\Form\GDT_Form;
use GDO\Form\GDT_Submit;
use GDO\Form\MethodForm;
use GDO\User\GDO_User;
use GDO\User\GDT_ACLRelation;
use GDO\UI\GDT_HTML;
use GDO\UI\GDT_Link;

final class Achieve extends MethodForm
{
    public function isUserRequired(): bool { return false; }
    public function isGuestAllowed(): bool { return false; }

    public function gdoParameters(): array
    {
        return [
            GDT_Object::make('achievement')->table(GDO_Achieve::table()),
            GDT_Object::make('badge')->table(GDO_Badge::table()),
        ];
    }

    public function renderPage(): GDT
    {
        $achievement = $this->gdoParameterValue('achievement');
        if (!$achievement)
        {
            return $this->getForm();
        }
        $badge = $achievement->gdoValue('ba_badge');
        $history = AchievementHistory::render(GDO_User::current(), $achievement->gdoValue('ba_user'), $badge, $achievement);
        return GDT_Tuple::makeWith(GDT_HTML::make()->var(self::summary($badge, $achievement)), GDT_HTML::make()->var($history), $this->getForm());
    }

    public static function summary(GDO_Badge $badge, GDO_Achieve $achievement): string
    {
        $status = $achievement->gdoVar('ba_status') ?? '';
        $image = $badge->gdoVar('bb_image') ? '<img alt="" src="' . html(href('Badger', 'BadgeBackground', '&badge=' . $badge->getID())) . '">' : '';
        return '<div class="badger-achievement-summary">' . $image . '<strong>' . html($badge->gdoVar('bb_name') ?? '') .
            '</strong><p>' . html(t('badger_status')) . ': ' . html(t('badger_status_' . $status)) . '</p></div>';
    }

    public function hasPermission(GDO_User $user, string &$error, array &$args): bool
    {
        if (!parent::hasPermission($user, $error, $args))
        {
            return false;
        }
        $achievement = $this->gdoParameterValue('achievement');
        if (!$achievement)
        {
            $badge = $this->gdoParameterValue('badge');
            $owner = $badge?->gdoValue('bb_owner');
            if ($user->isMember() && $badge && $owner && $badge->gdoVar('bb_active') === '1' &&
                BadgerPermissions::canViewBadge($user, $owner, $badge))
            {
                return true;
            }
            $error = 'err_permission_required';
            return false;
        }
        $badge = $achievement?->gdoValue('ba_badge');
        $recipient = $achievement?->gdoValue('ba_user');
        if ($badge && $recipient && BadgerPermissions::canView($user, $recipient, $badge, $achievement))
        {
            return true;
        }
        $error = 'err_permission_required';
        return false;
    }

    protected function createForm(GDT_Form $form): void
    {
        if (!$this->gdoParameterValue('achievement'))
        {
            $form->addField(GDT_AntiCSRF::make());
            $form->actions()->addField(GDT_Submit::make());
            return;
        }
        $achievement = $this->gdoParameterValue('achievement');
        $actions = [];
        $badge = $achievement->gdoValue('ba_badge');
        $actor = GDO_User::current();
        $status = $achievement->gdoVar('ba_status');
        $hasEvidence = in_array($status, ['pending', 'rejected'], true) && EvidenceAccess::hasCurrentEvidence($achievement);
        if (BadgerPermissions::forAchievement('submit', $actor, $badge, $achievement))
        {
            if (AchievementLifecycle::allows($status, 'pending'))
            {
                $actions[] = 'submit';
            }
            if (AchievementLifecycle::allows($status, 'draft'))
            {
                $actions[] = 'resume';
            }
            if (AchievementLifecycle::allows($status, 'withdrawn'))
            {
                $actions[] = 'withdraw';
            }
        }
        if ($actor->isMember() && ($achievement->gdoVar('ba_user') === $actor->getID() || $actor->isStaff() || $actor->isAdmin()))
        {
            $form->addField(GDT_ACLRelation::make('visibility')->initial($achievement->gdoVar('ba_visibility')));
            $actions[] = 'share';
            if ($achievement->gdoVar('ba_user') === $actor->getID())
            {
                $actions[] = 'delete_user';
            }
        }
        if (BadgerPermissions::canEditBadge(GDO_User::current(), $badge))
        {
            $actions[] = 'delete_badger';
            if ($status === 'pending')
            {
                if ($badge->gdoVar('bb_verify_doc') === '1') { $actions[] = 'approve_document'; }
                if (($badge->gdoVar('bb_verify_ai') || $badge->gdoVar('bb_verify_ai_doc')) && $badge->gdoVar('bb_approve_ai') !== '1') { $actions[] = 'accept_ai'; }
                if ($badge->gdoVar('bb_verify_staff') === '1')
                {
                    $actions[] = 'approve';
                }
                $actions[] = 'reject';
            }
            if (in_array($achievement->gdoVar('ba_status'), ['verified', 'claimed'], true))
            {
                $actions[] = 'revoke';
            }
            if ($status === 'revoked') { $actions[] = 'restore'; }
            if ($status === 'rejected' && $hasEvidence) { $actions[] = 'delete_evidence'; }
        }
        if ($status === 'pending' && CommunityApproval::eligible($actor, $badge, $achievement))
        {
            $actions[] = 'community_approve';
        }
        if ($actor->isMember() && $achievement->gdoVar('ba_status') === 'verified' &&
            $achievement->gdoVar('ba_user') === GDO_User::current()->getID())
        {
            $item = $badge->gdoVar('bb_item') ? '<img alt="" src="' . html(href('Badger', 'AwardItem', '&achievement=' . $achievement->getID())) . '">' : '';
            $name = html($badge->gdoVar('bb_name'));
            $stats = sprintf('XP %d · HP %d · STR %d · INT %d', (int)$badge->gdoVar('bb_xp'), (int)$badge->gdoVar('bb_hp'), (int)$badge->gdoVar('bb_str'), (int)$badge->gdoVar('bb_int'));
            $preview = '<style>@keyframes badger-award {from {opacity:0;transform:scale(.8)} to {opacity:1;transform:scale(1)}} .badger-award {animation:badger-award 1s ease-out} @media (prefers-reduced-motion:reduce){.badger-award{animation:none}}</style>' .
                '<div class="badger-award"><strong>' . $name . '</strong><div>' . $item . '</div><p>' . $stats . '</p></div>';
            $form->addField(GDT_HTML::make()->var($preview));
            $actions[] = 'claim';
        }
        if ($actions)
        {
            if (in_array('submit', $actions, true) && $achievement->gdoVar('ba_user') === $actor->getID())
            {
                $form->addField(GDT_Text::make('claim_message')->label('badger_claim_message'));
                if ($badge->gdoVar('bb_verify_ai') || $badge->gdoVar('bb_verify_ai_doc'))
                {
                    $provider = (string) Module_Badger::instance()->getConfigVar('bb_ai_provider_class');
                    $model = (string) Module_Badger::instance()->getConfigVar('bb_ai_ollama_model');
                    if ($provider === \GDO\Badger\OllamaAIReviewProvider::class && $model !== '') { $provider .= ' / ' . $model; }
                    $form->addField(GDT_HTML::make('ai_disclosure')->var(html(t('badger_ai_disclosure', [$provider ?: t('badger_ai_not_configured')]))));
                    $form->addField(GDT_Checkbox::make('ai_consent')->label('badger_ai_consent')->initial('0'));
                    $form->addField(GDT_Hidden::make('ai_consent_fingerprint')->initial(AIConsent::fingerprint()));
                }
            }
            $form->addFields(
                GDT_Enum::make('action')->enumValues(...$actions)->notNull(),
                GDT_Text::make('reason'),
                GDT_AntiCSRF::make(),
            );
            $form->actions()->addField(GDT_Submit::make());
        }
        if ($actor->isMember() && $achievement->gdoVar('ba_user') === $actor->getID() && $badge->gdoVar('bb_verify_doc') === '1' &&
            in_array($status, ['viewed', 'draft', 'rejected', 'withdrawn'], true))
        {
            $form->actions()->addField(GDT_Link::make('evidence_upload_link')->text('mt_badger_evidenceupload')->href(href('Badger', 'EvidenceUpload', '&achievement=' . $achievement->getID())));
        }
        if ($actor->isMember() && $hasEvidence &&
            ($achievement->gdoVar('ba_user') === $actor->getID() || BadgerPermissions::canEditBadge($actor, $badge)))
        {
            $form->actions()->addField(GDT_Link::make('evidence_download_link')->text('mt_badger_evidencedownload')->href(href('Badger', 'EvidenceDownload', '&achievement=' . $achievement->getID())));
        }
    }

    public function formValidated(GDT_Form $form): GDT
    {
        $achievement = $this->gdoParameterValue('achievement');
        if (!$achievement)
        {
            $achievement = AchievementRequests::start(GDO_User::current(), $this->gdoParameterValue('badge'), !$this->transactional());
            return $this->redirectMessage('msg_form_saved', null, href('Badger', 'Achieve', '&achievement=' . $achievement->getID()));
        }
        $badge = $achievement->gdoValue('ba_badge');
        $actor = GDO_User::current();
        $actionField = $form->getField('action');
        if (!$actionField)
        {
            return $this->error('err_permission_required');
        }
        $action = $form->getFormVar('action');
        if (!in_array($action, $actionField->enumValues, true))
        {
            return $this->error('err_permission_required');
        }
        if ($action === 'delete_user' || $action === 'delete_badger')
        {
            AchievementDeletion::mark($actor, $badge, $achievement, $action === 'delete_user', !$this->transactional());
        }
        elseif ($action === 'share')
        {
            AchievementSharing::set($actor, $badge, $achievement, $form->getFormVar('visibility'), !$this->transactional());
        }
        elseif ($action === 'approve')
        {
            AchievementActions::approve($actor, $badge, $achievement, !$this->transactional());
        }
        elseif ($action === 'community_approve')
        {
            try
            {
                CommunityApproval::approve($actor, $badge, $achievement, !$this->transactional());
            }
            catch (\RuntimeException $ex)
            {
                if ($ex->getMessage() === 'Badger rate limit exceeded') { return $this->error('err_badger_rate_limit'); }
                if (in_array($ex->getMessage(), ['Community member already approved this round', 'Actor already approved in another role'], true))
                {
                    return $this->error('err_badger_already_voted');
                }
                throw $ex;
            }
        }
        elseif ($action === 'approve_document')
        {
            try
            {
                EvidenceApproval::approve($actor, $achievement, (string) Module_Badger::instance()->getConfigVar('bb_evidence_private_dir'), !$this->transactional());
            }
            catch (\RuntimeException $ex)
            {
                if (in_array($ex->getMessage(), ['Evidence unavailable', 'Document already approved'], true))
                {
                    return $this->error('err_badger_evidence_review');
                }
                throw $ex;
            }
        }
        elseif ($action === 'delete_evidence')
        {
            try
            {
                EvidenceDeletion::removeRejected($actor, $achievement, (string) Module_Badger::instance()->getConfigVar('bb_evidence_private_dir'), !$this->transactional());
            }
            catch (\RuntimeException $ex)
            {
                if ($ex->getMessage() === 'Evidence unavailable') { return $this->error('err_badger_evidence_review'); }
                throw $ex;
            }
        }
        elseif ($action === 'accept_ai')
        {
            try
            {
                AIReviewHumanApproval::accept($actor, $achievement, (string) Module_Badger::instance()->getConfigVar('bb_evidence_private_dir'), !$this->transactional());
            }
            catch (\RuntimeException $ex)
            {
                if (in_array($ex->getMessage(), ['AI review unavailable', 'AI recommendation unavailable', 'AI review already accepted', 'AI consent required'], true))
                {
                    return $this->error('err_badger_ai_review');
                }
                throw $ex;
            }
        }
        else
        {
            if (in_array($action, ['revoke', 'restore'], true) && !trim($form->getFormVar('reason') ?? ''))
            {
                return $this->error('err_badger_revoke_reason');
            }
            $to = ['submit' => 'pending', 'resume' => 'draft', 'withdraw' => 'withdrawn', 'reject' => 'rejected', 'claim' => 'claimed', 'revoke' => 'revoked', 'restore' => 'verified'][$action];
            try
            {
                $reason = $action === 'submit' ? $form->getFormVar('claim_message') : $form->getFormVar('reason');
                AchievementActions::transition($actor, $badge, $achievement, $to, $reason, !$this->transactional(),
                    $action === 'submit' && $form->getField('ai_consent') && $form->getFormVar('ai_consent') === '1',
                    $action === 'submit' && $form->getField('ai_consent_fingerprint') ? $form->getFormVar('ai_consent_fingerprint') : null);
            }
            catch (\RuntimeException $ex)
            {
                if ($ex->getMessage() === 'Evidence required') { return $this->error('err_badger_evidence_required'); }
                if ($ex->getMessage() === 'AI consent required') { return $this->error('err_badger_ai_consent'); }
                if ($ex->getMessage() === 'Badger rate limit exceeded') { return $this->error('err_badger_rate_limit'); }
                throw $ex;
            }
        }
        return $this->message('msg_form_saved');
    }
}
