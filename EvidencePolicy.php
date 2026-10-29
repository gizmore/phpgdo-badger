<?php
declare(strict_types=1);
namespace GDO\Badger;

use InvalidArgumentException;
use RuntimeException;

final class EvidencePolicy
{
    public static function inspectWithScanner(string $bytes, int $maxBytes, array $allowedMimes, string $scannerPath, int $timeoutSeconds): array
    {
        return self::inspect($bytes, $maxBytes, $allowedMimes,
            static fn (string $content, string $mime): bool => EvidenceScanner::scan($scannerPath, $content, $timeoutSeconds));
    }

    public static function inspect(string $bytes, int $maxBytes, array $allowedMimes, ?callable $scanner): array
    {
        if ($maxBytes < 1 || $bytes === '' || strlen($bytes) > $maxBytes)
        {
            throw new InvalidArgumentException('Invalid evidence size');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!is_string($mime) || !in_array($mime, $allowedMimes, true))
        {
            throw new InvalidArgumentException('Evidence type not allowed');
        }
        if ($scanner === null)
        {
            throw new RuntimeException('Evidence scanner is not configured');
        }
        try
        {
            $clean = $scanner($bytes, $mime);
        }
        catch (\Throwable $ex)
        {
            throw new RuntimeException('Evidence scan failed', 0, $ex);
        }
        if ($clean !== true)
        {
            throw new RuntimeException('Evidence scan did not report clean');
        }
        return ['mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
}
