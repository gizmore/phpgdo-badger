<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\BadgeOwnership;
use GDO\Badger\GDO_Badge;
use GDO\Core\GDT;
use GDO\Core\GDT_Object;
use GDO\Form\GDT_AntiCSRF;
use GDO\Form\GDT_Form;
use GDO\Form\GDT_Submit;
use GDO\Form\MethodForm;
use GDO\User\GDO_User;
use GDO\User\GDT_User;

final class Transfer extends MethodForm
{
    public function isGuestAllowed(): bool { return false; }
    public function gdoParameters(): array { return [GDT_Object::make('badge')->table(GDO_Badge::table())->notNull()]; }
    public function hasPermission(GDO_User $user, string &$error, array &$args): bool
    {
        if (!parent::hasPermission($user, $error, $args)) { return false; }
        $badge = $this->gdoParameterValue('badge');
        if ($badge && ($badge->gdoVar('bb_owner') === $user->getID() || $user->isStaff() || $user->isAdmin())) { return true; }
        $error = 'err_permission_required';
        return false;
    }
    protected function createForm(GDT_Form $form): void
    {
        $form->addField(GDT_User::make('recipient')->label('badger_new_owner')->notNull());
        $form->addField(GDT_AntiCSRF::make());
        $form->actions()->addField(GDT_Submit::make()->label('badger_transfer_confirm'));
    }
    public function formValidated(GDT_Form $form): GDT
    {
        BadgeOwnership::transfer(GDO_User::current(), $this->gdoParameterValue('badge'), $form->getFormValue('recipient'), !$this->transactional());
        return $this->redirectMessage('msg_form_saved', null, href('Badger', 'Badges'));
    }
}
