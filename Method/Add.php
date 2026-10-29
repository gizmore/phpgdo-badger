<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\BadgeCreation;
use GDO\Badger\GDO_Badge;
use GDO\Badger\Module_Badger;
use GDO\Core\GDT;
use GDO\Form\GDT_AntiCSRF;
use GDO\Form\GDT_Form;
use GDO\Form\GDT_Submit;
use GDO\Form\MethodForm;
use GDO\User\GDO_User;
use InvalidArgumentException;
use RuntimeException;

final class Add extends MethodForm
{
    public function isGuestAllowed(): bool { return false; }

    protected function createForm(GDT_Form $form): void
    {
        $badge = GDO_Badge::blank();
        $module = Module_Badger::instance();
        foreach (['bb_name', 'bb_description', 'bb_category', 'bb_visibility', 'bb_has_image', 'bb_xp', 'bb_hp', 'bb_str', 'bb_int'] as $field)
        {
            $column = $badge->gdoColumn($field);
            if (in_array($field, ['bb_xp', 'bb_hp', 'bb_str', 'bb_int'], true))
            {
                $column->initial($module->getConfigVar('bb_default_' . substr($field, 3)));
            }
            $form->addField($column);
        }
        $form->addField(GDT_AntiCSRF::make());
        $form->actions()->addField(GDT_Submit::make());
    }

    public function formValidated(GDT_Form $form): GDT
    {
        $module = Module_Badger::instance();
        $values = $form->getFormVars();
        $rates = [];
        foreach (['xp', 'hp', 'str', 'int', 'image'] as $name)
        {
            $rates[$name] = (int) $module->getConfigVar('bb_credits_per_' . $name);
        }
        try
        {
            BadgeCreation::create(GDO_User::current(), $values, (int) $module->getConfigVar('bb_credits_per_badge'), $rates, (int) $module->getConfigVar('bb_welcome_credits'), !$this->transactional());
        }
        catch (InvalidArgumentException $ex)
        {
            if ($ex->getMessage() === 'Invalid badge owner') { return $this->error('err_permission_required'); }
            if ($ex->getMessage() === 'Invalid badge name') { return $this->error('err_badger_badge_name'); }
            return $this->error('err_badger_invalid_price');
        }
        catch (RuntimeException $ex)
        {
            if ($ex->getMessage() !== 'Insufficient credits')
            {
                throw $ex;
            }
            return $this->error('err_badger_insufficient_credits');
        }
        return $this->message('msg_badger_created');
    }
}
