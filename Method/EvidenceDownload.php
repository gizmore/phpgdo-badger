<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Badger\EvidenceAccess;
use GDO\Badger\Module_Badger;
use GDO\Core\Application;
use GDO\Core\GDT;
use GDO\Core\GDT_Response;
use GDO\Core\GDT_UInt;
use GDO\Core\Method;
use GDO\User\GDO_User;
use RuntimeException;

final class EvidenceDownload extends Method
{
    public function isGuestAllowed(): bool { return false; }
    public function isSavingLastUrl(): bool { return false; }
    public function gdoParameters(): array { return [GDT_UInt::make('achievement')->notNull()->min(1)]; }

    public function execute(): GDT
    {
        try
        {
            $bytes = EvidenceAccess::read(GDO_User::current(), $this->gdoParameterVar('achievement'), (string) Module_Badger::instance()->getConfigVar('bb_evidence_private_dir'));
        }
        catch (RuntimeException $ex)
        {
            http_response_code(404);
            return $this->error('err_file_not_found', null, 404);
        }
        hdr('Cache-Control: private, no-store');
        hdr('X-Content-Type-Options: nosniff');
        hdr('Content-Type: application/octet-stream');
        hdr('Content-Disposition: attachment; filename="evidence.bin"');
        hdr('Content-Length: ' . strlen($bytes));
        echo $bytes;
        Application::exit();
        return GDT_Response::make();
    }
}
