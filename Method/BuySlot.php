<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\Service\EquipmentPurchase;
use GDO\Badger\Module_Badger;
use GDO\Core\GDT;
use GDO\Form\GDT_AntiCSRF;
use GDO\Form\GDT_Form;
use GDO\Form\GDT_Submit;
use GDO\Form\MethodForm;
use GDO\UI\GDT_HTML;
use GDO\User\GDO_User;
use RuntimeException;

final class BuySlot extends MethodForm
{
    public function isGuestAllowed(): bool { return false; }
    protected function createForm(GDT_Form $form): void
    {
        $cost = (int) Module_Badger::instance()->getConfigVar('bb_credits_per_equip_slot');
        $form->addField(GDT_HTML::make('slot_price')->var(html(t('badger_slot_price', [$cost]))));
        $form->addField(GDT_AntiCSRF::make());
        $form->actions()->addField(GDT_Submit::make()->label('badger_buy_slot'));
    }
    public function formValidated(GDT_Form $form): GDT
    {
        $module = Module_Badger::instance();
        try
        {
            EquipmentPurchase::buy(GDO_User::current(), (int) $module->getConfigVar('bb_credits_per_equip_slot'),
                (int) $module->getConfigVar('bb_welcome_credits'), !$this->transactional());
        }
        catch (RuntimeException $ex)
        {
            if ($ex->getMessage() === 'Insufficient credits') { return $this->error('err_badger_insufficient_credits'); }
            throw $ex;
        }
        return $this->redirectMessage('msg_form_saved', null, href('Badger', 'Items'));
    }
}
