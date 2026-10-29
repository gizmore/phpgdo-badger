<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\EvidencePolicy;
use GDO\Badger\EvidenceScanner;
use PHPUnit\Framework\TestCase;

final class EvidencePolicyTest extends TestCase
{
    public function testContentTypeHashAndCleanScan(): void
    {
        $bytes = "%PDF-1.4\n%%EOF\n";
        $seen = [];
        $result = EvidencePolicy::inspect($bytes, 1000, ['application/pdf'], function (string $input, string $mime) use (&$seen): bool {
            $seen = [$input, $mime];
            return true;
        });
        self::assertSame([$bytes, 'application/pdf'], $seen);
        self::assertSame(['mime' => 'application/pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], $result);
    }

    public function testRejectsDisallowedMime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EvidencePolicy::inspect('not a pdf', 1000, ['application/pdf'], fn () => true);
    }

    public function testRejectsOversizedData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EvidencePolicy::inspect('%PDF-1.4', 1, ['application/pdf'], fn () => true);
    }

    public function testFailsClosedWithoutScanner(): void
    {
        $this->expectException(\RuntimeException::class);
        EvidencePolicy::inspect('%PDF-1.4', 1000, ['application/pdf'], null);
    }

    public function testFailsClosedWhenScannerRejects(): void
    {
        $this->expectException(\RuntimeException::class);
        EvidencePolicy::inspect('%PDF-1.4', 1000, ['application/pdf'], fn () => false);
    }

    public function testFailsClosedWhenScannerThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        EvidencePolicy::inspect('%PDF-1.4', 1000, ['application/pdf'], function (): bool {
            throw new \RuntimeException('scanner unavailable');
        });
    }

    public function testScannerProcessExitCodes(): void
    {
        self::assertTrue(EvidenceScanner::scan('/usr/bin/true', '%PDF-1.4', 2));
        self::assertFalse(EvidenceScanner::scan('/usr/bin/false', '%PDF-1.4', 2));
        $result = EvidencePolicy::inspectWithScanner('%PDF-1.4', 1000, ['application/pdf'], '/usr/bin/true', 2);
        self::assertSame('application/pdf', $result['mime']);
    }

    public function testRejectsInvalidScannerPath(): void
    {
        $this->expectException(\RuntimeException::class);
        EvidenceScanner::scan('clamscan', '%PDF-1.4', 2);
    }

    public function testRejectedScanRemovesTemporarySource(): void
    {
        $directory = sys_get_temp_dir() . '/badger-scan-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($directory, 0700));
        try
        {
            $code = 'require $argv[1]; if (sys_get_temp_dir() !== $argv[2]) { echo "wrong-temp"; exit(1); } echo \\GDO\\Badger\\EvidenceScanner::scan("/usr/bin/false", "%PDF-1.4", 2) ? "clean" : "rejected";';
            $process = proc_open([PHP_BINARY, '-r', $code, GDO_PATH . 'GDO/Badger/EvidenceScanner.php', $directory],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
                ['TMPDIR' => $directory]);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            self::assertSame('rejected', $output);
            self::assertSame(['.', '..'], scandir($directory));
        }
        finally { rmdir($directory); }
    }

    public function testTimedOutScanRemovesTemporarySource(): void
    {
        $directory = sys_get_temp_dir() . '/badger-scan-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($directory, 0700));
        $scanner = $directory . '/scanner';
        try
        {
            self::assertNotFalse(file_put_contents($scanner, "#!/bin/sh\nexec /usr/bin/sleep 10\n"));
            self::assertTrue(chmod($scanner, 0700));
            $code = 'require $argv[1]; if (sys_get_temp_dir() !== $argv[2]) { exit(2); } try { \\GDO\\Badger\\EvidenceScanner::scan($argv[3], "%PDF-1.4", 1); echo "unexpected"; } catch (\\RuntimeException $e) { echo $e->getMessage(); }';
            $process = proc_open([PHP_BINARY, '-r', $code, GDO_PATH . 'GDO/Badger/EvidenceScanner.php', $directory, $scanner],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
                ['TMPDIR' => $directory]);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            self::assertSame('Evidence scan timed out', $output);
            self::assertSame(['.', '..', 'scanner'], scandir($directory));
        }
        finally
        {
            if (is_file($scanner)) { unlink($scanner); }
            rmdir($directory);
        }
    }

    public function testScannerErrorsAndTimeoutFailClosed(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'badger-scanner-test-');
        self::assertIsString($script);
        try
        {
            self::assertNotFalse(file_put_contents($script, "#!/bin/sh\nexit 2\n"));
            self::assertTrue(chmod($script, 0700));
            try
            {
                EvidenceScanner::scan($script, '%PDF-1.4', 1);
                self::fail('Scanner errors must fail closed');
            }
            catch (\RuntimeException $ex) { self::assertSame('Evidence scanner failed', $ex->getMessage()); }
            self::assertNotFalse(file_put_contents($script, "#!/bin/sh\nexec /usr/bin/sleep 10\n"));
            try
            {
                EvidenceScanner::scan($script, '%PDF-1.4', 1);
                self::fail('Scanner timeouts must fail closed');
            }
            catch (\RuntimeException $ex) { self::assertSame('Evidence scan timed out', $ex->getMessage()); }
        }
        finally { unlink($script); }
    }
}
