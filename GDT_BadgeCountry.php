<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Country\GDT_Country;
use GDO\Core\GDO;

/** Optional country that displays an empty selection as None. */
final class GDT_BadgeCountry extends GDT_Country
{
    protected function __construct()
    {
        parent::__construct();
        $this->emptyVar('')->emptyLabel('none')->placeholder('none', []);
    }

    public function getValueSingle(?string $id): ?GDO
    {
        return $id === null || $id === '' ? null : parent::getValueSingle($id);
    }

    public function validate(int|float|string|array|null|object|bool $value): bool
    {
        if ($value === null && ($this->getVar() ?? '') !== '') { return $this->errorNotFound(); }
        return parent::validate($value);
    }

    public function renderHTML(): string
    {
        return ($this->getVar() ?? '') === '' ? t('none') : parent::renderHTML();
    }

    public function renderCell(): string
    {
        return ($this->getVar() ?? '') === '' ? t('none') : parent::renderCell();
    }
}
