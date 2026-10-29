<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use RuntimeException;

final class EvidenceScanner
{
    public static function scan(string $executable, string $bytes, int $timeoutSeconds): bool
    {
        $path = realpath($executable);
        if ($executable === '' || $executable[0] !== '/' || $path === false || !is_file($path) || !is_executable($path) || $timeoutSeconds < 1 || $timeoutSeconds > 60)
        {
            throw new RuntimeException('Invalid evidence scanner configuration');
        }
        $temp = tempnam(sys_get_temp_dir(), 'badger-scan-');
        if ($temp === false)
        {
            throw new RuntimeException('Could not prepare evidence scan');
        }
        try
        {
            if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($temp, 0600))
            {
                throw new RuntimeException('Could not prepare evidence scan');
            }
            $pipes = [];
            $process = proc_open([$path, '--no-summary', $temp], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process))
            {
                throw new RuntimeException('Could not start evidence scanner');
            }
            try
            {
                $deadline = hrtime(true) + $timeoutSeconds * 1000000000;
                do
                {
                    $status = proc_get_status($process);
                    if (!$status['running'])
                    {
                        if ($status['exitcode'] === 0)
                        {
                            return true;
                        }
                        if ($status['exitcode'] === 1)
                        {
                            return false;
                        }
                        throw new RuntimeException('Evidence scanner failed');
                    }
                    if (hrtime(true) >= $deadline)
                    {
                        proc_terminate($process, 9);
                        throw new RuntimeException('Evidence scan timed out');
                    }
                    usleep(50000);
                } while (true);
            }
            finally
            {
                proc_close($process);
            }
        }
        finally
        {
            if (is_file($temp))
            {
                unlink($temp);
            }
        }
    }
}
