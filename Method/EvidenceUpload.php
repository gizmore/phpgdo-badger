<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\EvidenceAttachments;
use GDO\Badger\EvidenceScanner;
use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDT_EvidenceUpload;
use GDO\Badger\Module_Badger;
use GDO\Core\GDT;
use GDO\Core\GDT_Object;
use GDO\Form\GDT_AntiCSRF;
use GDO\Form\GDT_Form;
use GDO\Form\GDT_Submit;
use GDO\Form\MethodForm;
use GDO\User\GDO_User;
use InvalidArgumentException;
use RuntimeException;

final class EvidenceUpload extends MethodForm
{
    public function isGuestAllowed(): bool { return false; }
    public function isTransactional(): bool { return false; }

    public function gdoParameters(): array
    {
        return [GDT_Object::make('achievement')->table(GDO_Achieve::table())->notNull()];
    }

    public function hasPermission(GDO_User $user, string &$error, array &$args): bool
    {
        if (!parent::hasPermission($user, $error, $args)) { return false; }
        $achievement = $this->gdoParameterValue('achievement');
        $badge = $achievement?->gdoValue('ba_badge');
        if ($achievement && $badge && $achievement->gdoVar('ba_user') === $user->getID() &&
            $badge->gdoVar('bb_verify_doc') === '1' &&
            in_array($achievement->gdoVar('ba_status'), ['viewed', 'draft', 'rejected', 'withdrawn'], true))
        {
            return true;
        }
        $error = 'err_permission_required';
        return false;
    }

    protected function createForm(GDT_Form $form): void
    {
        $form->multipart();
        $form->addField(GDT_EvidenceUpload::make('evidence_upload')->maxBytes((int) Module_Badger::instance()->getConfigVar('bb_evidence_max_bytes')));
        $form->addField(GDT_AntiCSRF::make());
        $form->actions()->addField(GDT_Submit::make());
    }

    public function formValidated(GDT_Form $form): GDT
    {
        $field = $form->getField('evidence_upload');
        if (!$field || !$field->validate($field->getVar())) { return $this->error('err_badger_evidence_upload'); }
        $module = Module_Badger::instance();
        $directory = (string) $module->getConfigVar('bb_evidence_private_dir');
        $scanner = (string) $module->getConfigVar('bb_evidence_scanner');
        $limit = (int) $module->getConfigVar('bb_evidence_max_bytes');
        $timeout = (int) $module->getConfigVar('bb_evidence_scan_timeout');
        if ($directory === '' || $scanner === '' || $limit < 1 || $timeout < 1)
        {
            return $this->error('err_badger_evidence_unavailable');
        }
        $bytes = file_get_contents($_FILES['evidence_upload']['tmp_name'], false, null, 0, $limit + 1);
        if (!is_string($bytes) || strlen($bytes) > $limit) { return $this->error('err_badger_evidence_upload'); }
        $mimes = array_values(array_filter(array_map('trim', explode(',', (string) $module->getConfigVar('bb_evidence_allowed_mimes')))));
        $achievement = $this->gdoParameterValue('achievement');
        try
        {
            EvidenceAttachments::attach(GDO_User::current(), $achievement->gdoValue('ba_badge'), $achievement,
                $directory, $bytes, $limit, $mimes,
                static fn(string $content, string $mime): bool => EvidenceScanner::scan($scanner, $content, $timeout));
        }
        catch (InvalidArgumentException | RuntimeException $ex)
        {
            return $this->error('err_badger_evidence_upload');
        }
        return $this->redirectMessage('msg_badger_evidence_saved', null, href('Badger', 'Achieve', '&achievement=' . $achievement->getID()));
    }
}
