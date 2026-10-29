<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use PHPUnit\Framework\TestCase;

final class CertificateHookTest extends TestCase
{
    public function testOnlyBadgerRenewalInvokesReload(): void
    {
        $directory = sys_get_temp_dir() . '/badger-hook-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($directory, 0700));
        $fakeSystemctl = $directory . '/systemctl';
        try
        {
            // A deliberately failing stand-in proves invocation without touching Apache.
            self::assertTrue(symlink('/usr/bin/false', $fakeSystemctl));
            $hook = dirname(__DIR__) . '/deploy/badger-cert-reload.sh';
            $run = static function (string $lineage) use ($directory, $hook): int {
                $process = proc_open(['/bin/sh', $hook], [0 => ['file', '/dev/null', 'r'],
                    1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
                    ['PATH' => $directory, 'RENEWED_LINEAGE' => $lineage]);
                if (!is_resource($process)) { throw new \RuntimeException('Hook test failed to start'); }
                return proc_close($process);
            };
            self::assertSame(0, $run('/etc/letsencrypt/live/another.example'));
            self::assertSame(1, $run('/etc/letsencrypt/live/badger.mogwai.mira-gpt.org'));
        }
        finally
        {
            if (is_link($fakeSystemctl)) { unlink($fakeSystemctl); }
            rmdir($directory);
        }
    }
}
