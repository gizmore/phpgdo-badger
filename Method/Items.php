<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\Service\ItemEquipment;
use GDO\Badger\Module_Badger;
use GDO\Avatar\GDT_Avatar;
use GDO\Core\GDT;
use GDO\Core\GDT_Checkbox;
use GDO\Core\GDT_Select;
use GDO\Core\GDT_Tuple;
use GDO\Form\GDT_AntiCSRF;
use GDO\Form\GDT_Form;
use GDO\Form\GDT_Submit;
use GDO\Form\MethodForm;
use GDO\User\GDO_User;
use GDO\UI\GDT_Link;
use RuntimeException;

final class Items extends MethodForm
{
    public function isGuestAllowed(): bool { return false; }

    public function renderPage(): GDT
    {
        return GDT_Tuple::makeWith(GDT_Avatar::make()->user(GDO_User::current())->imageSize(128), $this->getForm());
    }

    protected function createForm(GDT_Form $form): void
    {
        $form->addFields(
            GDT_Select::make('achievement')->choices(ItemEquipment::choices(GDO_User::current()))->notNull(),
            GDT_Checkbox::make('equip')->initial('1'),
            GDT_AntiCSRF::make(),
        );
        $form->actions()->addField(GDT_Submit::make());
        $form->actions()->addField(GDT_Link::make('buy_slot')->text('mt_badger_buyslot')->href(href('Badger', 'BuySlot')));
    }

    public function formValidated(GDT_Form $form): GDT
    {
        try
        {
            ItemEquipment::set(GDO_User::current(), $form->getFormVar('achievement'), $form->getFormVar('equip') === '1',
                (int) Module_Badger::instance()->getConfigVar('bb_default_max_equip'), !$this->transactional());
        }
        catch (RuntimeException $ex)
        {
            if ($ex->getMessage() === 'Equipment limit reached')
            {
                return $this->error('err_badger_equip_limit');
            }
            if ($ex->getMessage() === 'Item is not available')
            {
                return $this->error('err_badger_item_unavailable');
            }
            throw $ex;
        }
        return $this->message('msg_form_saved');
    }
}
