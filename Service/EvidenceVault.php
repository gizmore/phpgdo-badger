<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use RuntimeException;
use Throwable;

final class EvidenceVault
{
    public static function isReady(string $directory): bool
    {
        try { self::directory($directory); return true; }
        catch (RuntimeException $ex) { return false; }
    }

    private static function directory(string $directory): string
    {
        clearstatcache(true, $directory);
        if ($directory === '' || $directory[0] !== '/' || is_link($directory))
        {
            throw new RuntimeException('Invalid private evidence directory');
        }
        $root = realpath($directory);
        if ($root !== false) { clearstatcache(true, $root); }
        $permissions = $root === false ? false : fileperms($root);
        if ($root === false || !is_dir($root) || !is_writable($root) || $permissions === false || ($permissions & 0077) !== 0 ||
            !function_exists('posix_geteuid') || fileowner($root) !== posix_geteuid())
        {
            throw new RuntimeException('Invalid private evidence directory');
        }
        foreach ([GDO_PATH, $_SERVER['DOCUMENT_ROOT'] ?? ''] as $publicRoot)
        {
            $public = $publicRoot !== '' ? realpath($publicRoot) : false;
            if ($public !== false && ($root === $public || str_starts_with($root . '/', rtrim($public, '/') . '/')))
            {
                throw new RuntimeException('Evidence directory must be outside the web tree');
            }
        }
        return $root;
    }

    private static function path(string $directory, string $key): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $key))
        {
            throw new RuntimeException('Invalid evidence key');
        }
        $path = self::directory($directory) . '/' . $key;
        clearstatcache(true, $path);
        if (is_link($path))
        {
            throw new RuntimeException('Invalid evidence file');
        }
        return $path;
    }

    public static function store(string $directory, string $bytes, int $maxBytes, array $allowedMimes, ?callable $scanner): array
    {
        $root = self::directory($directory);
        $metadata = EvidencePolicy::inspect($bytes, $maxBytes, $allowedMimes, $scanner);
        $key = bin2hex(random_bytes(32));
        $path = $root . '/' . $key;
        $oldMask = umask(0077);
        try
        {
            $file = @fopen($path, 'xb');
        }
        finally
        {
            umask($oldMask);
        }
        if (!$file)
        {
            throw new RuntimeException('Could not create evidence');
        }
        try
        {
            if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file))
            {
                throw new RuntimeException('Could not store evidence');
            }
        }
        catch (Throwable $ex)
        {
            fclose($file);
            unlink($path);
            throw $ex;
        }
        fclose($file);
        return ['key' => $key] + $metadata;
    }

    public static function read(string $directory, string $key): string
    {
        $path = self::path($directory, $key);
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false)
        {
            throw new RuntimeException('Evidence not found');
        }
        return $bytes;
    }

    public static function remove(string $directory, string $key): void
    {
        $path = self::path($directory, $key);
        if (is_file($path) && !unlink($path))
        {
            throw new RuntimeException('Could not remove evidence');
        }
    }
}
