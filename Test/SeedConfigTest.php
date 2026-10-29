<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\install\SeedConfig;
use PHPUnit\Framework\TestCase;

final class SeedConfigTest extends TestCase
{
    public function testExternalSeedMustBePrivate(): void
    {
        $original = getenv('GDO_BADGER_SEED_CONFIG');
        $file = tempnam(sys_get_temp_dir(), 'badger-seed-test-');
        self::assertIsString($file);
        $link = $file . '.link';
        try
        {
            file_put_contents($file, '<?php return ["test" => "fixture"];');
            chmod($file, 0600);
            putenv('GDO_BADGER_SEED_CONFIG=' . $file);
            self::assertSame(['test' => 'fixture'], SeedConfig::load());
            chmod($file, 0644);
            try { SeedConfig::load(); self::fail('A group/world-readable seed must be rejected'); }
            catch (\RuntimeException $ex) { self::assertSame('Invalid seed configuration', $ex->getMessage()); }
            putenv('GDO_BADGER_SEED_CONFIG=relative.php');
            try { SeedConfig::load(); self::fail('A relative seed path must be rejected'); }
            catch (\RuntimeException $ex) { self::assertSame('Invalid seed configuration', $ex->getMessage()); }
            chmod($file, 0600);
            self::assertTrue(symlink($file, $link));
            putenv('GDO_BADGER_SEED_CONFIG=' . $link);
            try { SeedConfig::load(); self::fail('A symlink seed must be rejected'); }
            catch (\RuntimeException $ex) { self::assertSame('Invalid seed configuration', $ex->getMessage()); }
        }
        finally
        {
            if ($original === false) { putenv('GDO_BADGER_SEED_CONFIG'); }
            else { putenv('GDO_BADGER_SEED_CONFIG=' . $original); }
            if (is_link($link)) { unlink($link); }
            unlink($file);
        }
    }
}
