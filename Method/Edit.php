<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\BadgerPermissions;
use GDO\Badger\GDO_Badge;
use GDO\Badger\Module_Badger;
use GDO\Badger\EvidenceVault;
use GDO\Badger\BadgeTags;
use GDO\Badger\AIReviewProviderFactory;
use GDO\Core\GDO;
use GDO\DB\Database;
use RuntimeException;
use Throwable;
use GDO\Core\GDT;
use GDO\Core\GDT_Object;
use GDO\Core\GDT_String;
use GDO\Form\GDT_AntiCSRF;
use GDO\Form\GDT_Form;
use GDO\Form\GDT_Submit;
use GDO\Form\MethodForm;
use GDO\User\GDO_User;
use GDO\UI\GDT_Link;

final class Edit extends MethodForm
{
    private const EDITABLE_FIELDS = ['bb_name', 'bb_description', 'bb_category', 'bb_visibility', 'bb_active', 'bb_verify_staff', 'bb_staff_approvals_needed', 'bb_verify_cc', 'bb_community_approvals_needed', 'bb_verify_doc', 'bb_verify_ai', 'bb_verify_ai_doc', 'bb_approve_ai'];
    private const ITEM_FIELDS = ['bb_item_name', 'bb_item_x', 'bb_item_y', 'bb_item_w', 'bb_item_h'];

    public function isGuestAllowed(): bool { return false; }
    public function isTransactional(): bool { return false; }

    public function gdoParameters(): array
    {
        return [GDT_Object::make('badge')->table(GDO_Badge::table())->notNull()];
    }

    public function hasPermission(GDO_User $user, string &$error, array &$args): bool
    {
        if (!parent::hasPermission($user, $error, $args))
        {
            return false;
        }
        $badge = $this->gdoParameterValue('badge');
        if ($badge && BadgerPermissions::canEditBadge($user, $badge))
        {
            return true;
        }
        $error = 'err_permission_required';
        return false;
    }

    protected function createForm(GDT_Form $form): void
    {
        $badge = $this->gdoParameterValue('badge');
        foreach (self::EDITABLE_FIELDS as $field)
        {
            $form->addField($badge->gdoColumn($field));
        }
        $form->addField(GDT_String::make('badge_tags')->max(255)->label('badger_tags')->initial(implode(', ', BadgeTags::names($badge->getID()))));
        if ($badge->gdoVar('bb_has_image') === '1')
        {
            foreach (self::ITEM_FIELDS as $field)
            {
                $column = $badge->gdoColumn($field);
                if ($field === 'bb_item_w' || $field === 'bb_item_h')
                {
                    $column->max((int) Module_Badger::instance()->getConfigVar($field === 'bb_item_w' ? 'bb_item_max_w' : 'bb_item_max_h'));
                }
                $form->addField($column);
            }
        }
        $form->addField(GDT_AntiCSRF::make());
        $form->actions()->addField(GDT_Submit::make());
        $actor = GDO_User::current();
        if ($badge->gdoVar('bb_owner') === $actor->getID() || $actor->isStaff() || $actor->isAdmin())
        {
            $form->actions()->addField(GDT_Link::make('transfer')->text('mt_badger_transfer')->href(href('Badger', 'Transfer', '&badge=' . $badge->getID())));
        }
        if ($badge->gdoVar('bb_has_image') === '1')
        {
            $form->actions()->addField(GDT_Link::make('image')->text('mt_badger_image')->href(href('Badger', 'Image', '&badge=' . $badge->getID())));
        }
    }

    public function formValidated(GDT_Form $form): GDT
    {
        $badge = $this->gdoParameterValue('badge');
        if (!BadgerPermissions::canEditBadge(GDO_User::current(), $badge))
        {
            return $this->error('err_permission_required');
        }
        $fields = $badge->gdoVar('bb_has_image') === '1' ? array_merge(self::EDITABLE_FIELDS, self::ITEM_FIELDS) : self::EDITABLE_FIELDS;
        $vars = array_intersect_key($form->getFormVars(), array_flip($fields));
        try { $tags = BadgeTags::parse($form->getFormVar('badge_tags') ?? ''); }
        catch (RuntimeException $ex) { return $this->error('err_badger_tags'); }
        $db = Database::instance();
        if (!$db->transactionBegin()) { throw new RuntimeException('Could not begin badge edit'); }
        try
        {
            $id = GDO::quoteS($badge->getID());
            $row = $db->queryRead("SELECT bb_owner, bb_verify_staff, bb_verify_cc, bb_verify_doc, bb_verify_ai, bb_verify_ai_doc, bb_approve_ai, bb_staff_approvals_needed, bb_community_approvals_needed FROM gdo_badger_badge WHERE bb_id={$id} FOR UPDATE")->fetch_assoc();
            $actor = GDO_User::current();
            $staffId = GDO::quoteS($actor->getID());
            $staff = $db->queryRead("SELECT ba_is_staff FROM gdo_badger_achieve WHERE ba_badge={$id} AND ba_user={$staffId} FOR UPDATE")->fetch_assoc();
            if (!$row || ($row['bb_owner'] !== $actor->getID() && !$actor->isStaff() && !$actor->isAdmin() && (!$staff || $staff['ba_is_staff'] !== '1')))
            {
                throw new RuntimeException('Permission denied');
            }
            $policyChanged = false;
            foreach (['bb_verify_staff', 'bb_verify_cc', 'bb_verify_doc', 'bb_verify_ai', 'bb_verify_ai_doc', 'bb_approve_ai', 'bb_staff_approvals_needed', 'bb_community_approvals_needed'] as $policy)
            {
                if (array_key_exists($policy, $vars) && (string) ($vars[$policy] ?? '') !== (string) ($row[$policy] ?? '')) { $policyChanged = true; }
            }
            if ($policyChanged)
            {
                $pending = $db->queryRead("SELECT ba_id FROM gdo_badger_achieve WHERE ba_badge={$id} AND ba_status='pending' LIMIT 1 FOR UPDATE")->fetch_assoc();
                if ($pending) { $db->transactionRollback(); return $this->error('err_badger_document_policy_pending'); }
                $docEnabled = (string) ($vars['bb_verify_doc'] ?? $row['bb_verify_doc']) === '1';
                $prompt = trim((string) ($vars['bb_verify_ai'] ?? $row['bb_verify_ai']));
                $docPrompt = trim((string) ($vars['bb_verify_ai_doc'] ?? $row['bb_verify_ai_doc']));
                if ($docPrompt !== '' && !$docEnabled) { $db->transactionRollback(); return $this->error('err_badger_ai_document'); }
                if ($prompt !== '' || $docPrompt !== '')
                {
                    try { $provider = AIReviewProviderFactory::create((string) Module_Badger::instance()->getConfigVar('bb_ai_provider_class')); }
                    catch (Throwable $ex) { $provider = null; }
                    if (!$provider) { $db->transactionRollback(); return $this->error('err_badger_ai_unavailable'); }
                }
                if ($docEnabled && $row['bb_verify_doc'] !== '1')
                {
                    $module = Module_Badger::instance();
                    $scanner = (string) $module->getConfigVar('bb_evidence_scanner');
                    if ($scanner === '' || $scanner[0] !== '/' || !is_file($scanner) || !is_executable($scanner) ||
                        !EvidenceVault::isReady((string) $module->getConfigVar('bb_evidence_private_dir')))
                    {
                        $db->transactionRollback(); return $this->error('err_badger_evidence_unavailable');
                    }
                }
            }
            $badge->saveVars($vars);
            BadgeTags::replace($badge->getID(), $tags);
            if (!$db->transactionEnd()) { throw new RuntimeException('Could not commit badge edit'); }
        }
        catch (Throwable $ex)
        {
            $db->transactionRollback();
            throw $ex;
        }
        return $this->message('msg_form_saved');
    }
}
