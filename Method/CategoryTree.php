<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\Service\CategoryTree as TreeManager;
use GDO\Badger\GDO_Category;
use GDO\Badger\GDT_BadgeTree;
use GDO\Badger\GDO_Badge;
use GDO\Core\GDT;
use GDO\Core\GDT_Tuple;
use GDO\Core\GDT_Object;
use GDO\Core\GDT_String;
use GDO\Form\GDT_AntiCSRF;
use GDO\Form\GDT_Form;
use GDO\Form\GDT_Submit;
use GDO\Form\MethodForm;

final class CategoryTree extends MethodForm
{
    public function getPermission(): ?string { return 'staff'; }
    public function isGuestAllowed(): bool { return false; }

    public function renderPage(): GDT
    {
        $categories = GDO_Category::table()->select()->order('bc_sort ASC, bc_name ASC')->exec()->fetchAllObjects();
        $badges = GDO_Badge::table()->select()->order('bb_name ASC')->exec()->fetchAllObjects();
        return GDT_Tuple::makeWith(GDT_BadgeTree::make()->categories($categories)->badges($badges), $this->getForm());
    }

    protected function createForm(GDT_Form $form): void
    {
        $form->addFields(
            GDT_Object::make('category')->table(GDO_Category::table()),
            GDT_Object::make('parent')->table(GDO_Category::table()),
            GDT_String::make('name')->max(128),
            GDT_String::make('slug')->max(128),
            GDT_AntiCSRF::make(),
        );
        $form->actions()->addField(GDT_Submit::make());
    }

    public function formValidated(GDT_Form $form): GDT
    {
        $category = $form->getFormValue('category');
        $parent = $form->getFormValue('parent');
        if ($category)
        {
            TreeManager::setParent($category->getID(), $parent?->getID(), !$this->transactional());
        }
        else
        {
            $name = trim($form->getFormVar('name') ?? '');
            $slug = trim($form->getFormVar('slug') ?? '');
            if ($name === '' || $slug === '')
            {
                return $this->error('err_badger_category_name_slug');
            }
            TreeManager::create($name, $slug, $parent);
        }
        return $this->message('msg_form_saved');
    }
}
