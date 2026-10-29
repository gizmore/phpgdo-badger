<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\BadgeStaff;
use GDO\Badger\GDO_Badge;
use GDO\Core\GDT;
use GDO\Core\GDT_Checkbox;
use GDO\Core\GDT_Object;
use GDO\Form\GDT_AntiCSRF;
use GDO\Form\GDT_Form;
use GDO\Form\GDT_Submit;
use GDO\Form\MethodForm;
use GDO\User\GDO_User;
use GDO\User\GDT_User;
use RuntimeException;

final class AddStaff extends MethodForm
{
    public function isGuestAllowed(): bool { return false; }

    public function gdoParameters(): array
    {
        return [GDT_Object::make('badge')->table(GDO_Badge::table())->notNull()];
    }

    protected function createForm(GDT_Form $form): void
    {
        $form->addFields(
            GDT_User::make('user')->notNull(),
            GDT_Checkbox::make('staff')->initial('1'),
            GDT_AntiCSRF::make(),
        );
        $form->actions()->addField(GDT_Submit::make());
    }

    public function formValidated(GDT_Form $form): GDT
    {
        $badge = $this->gdoParameterValue('badge');
        $actor = GDO_User::current();
        if (!$badge || ($badge->gdoVar('bb_owner') !== $actor->getID() && !$actor->isStaff() && !$actor->isAdmin()))
        {
            return $this->error('err_permission_required');
        }
        $user = $form->getFormValue('user');
        try
        {
            BadgeStaff::set($actor, $badge, $user, $form->getFormVar('staff') === '1', !$this->transactional());
        }
        catch (RuntimeException $ex)
        {
            if ($ex->getMessage() === 'Staff must be a member') { return $this->error('err_badger_staff_member'); }
            throw $ex;
        }
        return $this->message('msg_form_saved');
    }
}
