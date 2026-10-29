<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\Service\EvidenceVault;
use PHPUnit\Framework\TestCase;

final class EvidenceVaultTest extends TestCase
{
    public function testPrivateStoreReadAndRemove(): void
    {
        $dir = sys_get_temp_dir() . '/badger-vault-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($dir, 0700));
        $key = null;
        try
        {
            $bytes = '%PDF-1.4';
            $stored = EvidenceVault::store($dir, $bytes, 1000, ['application/pdf'], fn () => true);
            $key = $stored['key'];
            self::assertSame($bytes, EvidenceVault::read($dir, $key));
            self::assertSame(hash('sha256', $bytes), $stored['sha256']);
            self::assertSame(0, fileperms($dir . '/' . $key) & 0077);
            EvidenceVault::remove($dir, $key);
            self::assertFileDoesNotExist($dir . '/' . $key);
        }
        finally
        {
            if ($key && is_file($dir . '/' . $key))
            {
                unlink($dir . '/' . $key);
            }
            rmdir($dir);
        }
    }

    public function testRejectedScanDoesNotCreateEvidence(): void
    {
        $dir = sys_get_temp_dir() . '/badger-vault-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($dir, 0700));
        try
        {
            try
            {
                EvidenceVault::store($dir, '%PDF-1.4', 1000, ['application/pdf'], fn () => false);
                self::fail('Rejected evidence must not be stored');
            }
            catch (\RuntimeException $ex)
            {
                self::assertSame('Evidence scan did not report clean', $ex->getMessage());
            }
            self::assertSame(['.', '..'], scandir($dir));
        }
        finally
        {
            rmdir($dir);
        }
    }

    public function testChangedDirectoryPermissionsAreRechecked(): void
    {
        $dir = sys_get_temp_dir() . '/badger-vault-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($dir, 0700));
        try
        {
            self::assertTrue(EvidenceVault::isReady($dir));
            self::assertTrue(chmod($dir, 0755));
            self::assertFalse(EvidenceVault::isReady($dir));
        }
        finally { rmdir($dir); }
    }

    public function testRejectsTraversalKey(): void
    {
        $this->expectException(\RuntimeException::class);
        EvidenceVault::read(sys_get_temp_dir(), '../outside');
    }

    public function testRejectsWebDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        EvidenceVault::store(GDO_PATH, '%PDF-1.4', 1000, ['application/pdf'], fn () => true);
    }

    public function testUnavailableEffectiveUidFailsClosed(): void
    {
        $dir = sys_get_temp_dir() . '/badger-vault-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($dir, 0700));
        try
        {
            $code = 'define("GDO_PATH", $argv[1]); require $argv[1] . "GDO/Badger/Service/EvidenceVault.php"; echo function_exists("posix_geteuid") ? "enabled" : (\\GDO\\Badger\\Service\\EvidenceVault::isReady($argv[2]) ? "unsafe" : "rejected");';
            $process = proc_open([PHP_BINARY, '-d', 'disable_functions=posix_geteuid', '-r', $code, GDO_PATH, $dir],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            self::assertSame('rejected', $output);
        }
        finally { rmdir($dir); }
    }
}
