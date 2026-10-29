<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDT_String;

class GDT_ItemUpload extends GDT_String
{
    private int $maxBytes = 2097152;

    public function maxBytes(int $maxBytes): self
    {
        $this->maxBytes = $maxBytes;
        return $this;
    }

    public function getVar(): string|array|null
    {
        $file = $_FILES[$this->getName()] ?? null;
        return is_array($file) && ($file['error'] ?? null) === UPLOAD_ERR_OK &&
            is_string($file['tmp_name'] ?? null) && is_uploaded_file($file['tmp_name']) ? 'uploaded' : null;
    }

    public function validate(int|float|string|array|null|object|bool $value): bool
    {
        if ($this->getVar() === null)
        {
            return $this->error($this->uploadError());
        }
        $path = $_FILES[$this->getName()]['tmp_name'];
        $size = filesize($path);
        return $size !== false && $size > 0 && $size <= $this->maxBytes ? true : $this->error($this->uploadError());
    }

    protected function uploadError(): string { return 'err_badger_upload'; }

    public function renderForm(): string
    {
        return '<label>' . html(t('badger_item_upload')) . ' <input type="file" name="' . html($this->getName()) . '" accept="image/gif,image/png,image/jpeg,image/webp" required></label>' . $this->htmlError();
    }
}
