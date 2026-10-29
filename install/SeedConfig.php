<?php
declare(strict_types=1);
namespace GDO\Badger\install;

use RuntimeException;

final class SeedConfig
{
    public static function load(): array
    {
        $path = getenv('GDO_BADGER_SEED_CONFIG');
        if ($path === false || $path === '')
        {
            $path = GDO_PATH . 'GDO/Badger/secret.php';
        }
        else
        {
            clearstatcache(true, $path);
            if ($path[0] !== '/' || is_link($path)) { throw new RuntimeException('Invalid seed configuration'); }
            $resolved = realpath($path);
            $root = realpath(GDO_PATH);
            if ($resolved !== false) { clearstatcache(true, $resolved); }
            $mode = $resolved === false ? false : fileperms($resolved);
            if ($resolved === false || $root === false || !is_file($resolved) || !is_readable($resolved) ||
                $mode === false || ($mode & 0077) !== 0 || str_starts_with($resolved, rtrim($root, '/') . '/'))
            {
                throw new RuntimeException('Invalid seed configuration');
            }
            $path = $resolved;
        }
        $config = require $path;
        if (!is_array($config)) { throw new RuntimeException('Invalid seed configuration'); }
        return $config;
    }
}
