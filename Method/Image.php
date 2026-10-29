<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\Service\BadgerPermissions;
use GDO\Badger\GDO_Badge;
use GDO\Badger\GDT_ItemUpload;
use GDO\Badger\Service\ItemImage;
use GDO\Badger\Service\ItemStorage;
use GDO\Badger\Module_Badger;
use GDO\Core\GDT;
use GDO\Core\GDT_Object;
use GDO\Core\GDT_Enum;
use GDO\Form\GDT_AntiCSRF;
use GDO\Form\GDT_Form;
use GDO\Form\GDT_Submit;
use GDO\Form\MethodForm;
use GDO\User\GDO_User;
use InvalidArgumentException;

final class Image extends MethodForm
{
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
        if ($badge && $badge->gdoVar('bb_has_image') === '1' && BadgerPermissions::canEditBadge($user, $badge))
        {
            return true;
        }
        $error = 'err_permission_required';
        return false;
    }

    protected function createForm(GDT_Form $form): void
    {
        $form->multipart();
        $form->addField(GDT_Enum::make('image_kind')->enumValues('item', 'icon', 'background')->notNull()->initial('item'));
        $form->addField(GDT_ItemUpload::make('item_upload')->maxBytes((int) Module_Badger::instance()->getConfigVar('bb_item_max_bytes')));
        $form->addField(GDT_AntiCSRF::make());
        $form->actions()->addField(GDT_Submit::make());
    }

    public function formValidated(GDT_Form $form): GDT
    {
        $field = $form->getField('item_upload');
        if (!$field || !$field->validate($field->getVar()))
        {
            return $this->error('err_badger_upload');
        }
        $module = Module_Badger::instance();
        $limit = (int) $module->getConfigVar('bb_item_max_bytes');
        $path = $_FILES['item_upload']['tmp_name'];
        $bytes = file_get_contents($path, false, null, 0, $limit + 1);
        if (!is_string($bytes))
        {
            return $this->error('err_badger_upload');
        }
        try
        {
            $gif = ItemImage::toGif($bytes, (int) $module->getConfigVar('bb_item_max_w'), (int) $module->getConfigVar('bb_item_max_h'), $limit);
            ItemStorage::save(GDO_User::current(), $this->gdoParameterValue('badge'), $gif, $form->getFormVar('image_kind'));
        }
        catch (InvalidArgumentException $ex)
        {
            return $this->error('err_badger_upload');
        }
        return $this->message('msg_badger_item_saved');
    }
}
