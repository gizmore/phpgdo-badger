<?php
declare(strict_types=1);
namespace GDO\Badger;

final class GDT_EvidenceUpload extends GDT_ItemUpload
{
    protected function uploadError(): string { return 'err_badger_evidence_upload'; }

    public function renderForm(): string
    {
        return '<label>' . html(t('badger_evidence_upload')) . ' <input type="file" name="' . html($this->getName()) . '" accept="application/pdf,image/png,image/jpeg" required></label>' . $this->htmlError();
    }
}
