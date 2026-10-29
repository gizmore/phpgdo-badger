<?php
declare(strict_types=1);

use GDO\Core\Application;
use GDO\Core\Logger;
use GDO\Core\ModuleLoader;
use GDO\DB\Database;
use GDO\Install\Installer;
use GDO\Language\Trans;
use GDO\User\GDO_User;

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--preflight', '--seed-check', '--install-empty', '--resume-partial', '--install-register', '--install-captcha'], true))
{
    fwrite(STDERR, "Usage: php GDO/Badger/install/bootstrap.php --preflight|--seed-check|--install-empty|--resume-partial|--install-register|--install-captcha\n");
    exit(1);
}
ini_set('zend.exception_ignore_args', '1');
set_exception_handler(static function (Throwable $ex): void {
    $safe = ['Badger database is not empty; refusing bootstrap', 'Partial installation contains data; refusing resume',
        'Badger isolation configuration is invalid', 'Unexpected database connection', 'Target module state already exists; refusing installation',
        'Target module dependency missing or disabled'];
    $reason = in_array($ex->getMessage(), $safe, true) ? $ex->getMessage() : $ex::class . ' at ' . basename($ex->getFile()) . ':' . $ex->getLine();
    fwrite(STDERR, 'Badger bootstrap failed: ' . $reason . PHP_EOL);
    exit(1);
});
chdir(dirname(__DIR__, 3));
require 'protected/config.php';
require 'GDO7.php';
if (GDO_DB_NAME !== 'phpgdo_badger' || GDO_FILES_DIR !== 'files_badger')
{
    throw new RuntimeException('Badger isolation configuration is invalid');
}
$app = new class extends Application {
    public function __construct() { parent::__construct(); self::$INSTANCE = $this; }
    public function isCLI(): bool { return true; }
    public function isInstall(): bool { return true; }
};
Logger::init(null, 0);
$db = Database::init();
$database = $db->queryRead('SELECT DATABASE() AS name, CURRENT_USER() AS principal')->fetch_assoc();
if (($database['name'] ?? null) !== 'phpgdo_badger' || ($database['principal'] ?? null) !== GDO_DB_USER . '@localhost')
{
    throw new RuntimeException('Unexpected database connection');
}
if ($argv[1] === '--seed-check')
{
    $secret = \GDO\Badger\install\SeedConfig::load();
    if (!is_array($secret)) { throw new RuntimeException('Invalid seed configuration'); }
    foreach (['gizmore', 'dejavu', 'mira'] as $key)
    {
        if (!isset($secret[$key]))
        {
            if ($key === 'mira') { echo "Optional account: not configured\n"; continue; }
            throw new RuntimeException('Missing required seed account');
        }
        $entry = $secret[$key];
        if (!is_array($entry) || !is_string($entry[0] ?? null) || trim($entry[0]) === '') { throw new RuntimeException('Invalid seed account'); }
        $username = \GDO\Core\GDO::quoteS($entry[0]);
        $result = $db->queryRead("SELECT user_type FROM gdo_user WHERE user_name={$username}");
        $matches = [];
        while ($row = $result->fetch_assoc()) { $matches[] = $row['user_type']; }
        $state = !$matches ? 'missing' : (count($matches) !== 1 ? 'ambiguous' : ($matches[0] === 'member' ? 'existing member' : 'existing non-member'));
        echo ($key === 'mira' ? 'Optional' : 'Required') . " account: {$state}\n";
    }
    echo "Read only; no account or seed was changed.\n";
    exit(0);
}
$tables = $db->queryRead('SHOW TABLES')->fetch_assoc();
if ($tables && $argv[1] === '--install-empty') { throw new RuntimeException('Badger database is not empty; refusing bootstrap'); }
if ($argv[1] === '--resume-partial')
{
    $unexpectedUsers = $db->queryRead('SELECT COUNT(*) AS n FROM gdo_user WHERE user_id<>1')->fetch_assoc();
    $badges = $db->queryRead('SELECT COUNT(*) AS n FROM gdo_badger_badge')->fetch_assoc();
    $badger = $db->queryRead("SELECT module_id FROM gdo_module WHERE module_name='Badger'")->fetch_assoc();
    if (!$badger || (int) $unexpectedUsers['n'] !== 0 || (int) $badges['n'] !== 0)
    {
        throw new RuntimeException('Partial installation contains data; refusing resume');
    }
}
$loader = new ModuleLoader(GDO_PATH . 'GDO/');
if ($tables) { $loader->loadModules(true, false); }
GDO_User::setCurrent(GDO_User::ghost());
Trans::$ISO = GDO_LANGUAGE;
if (in_array($argv[1], ['--install-register', '--install-captcha'], true))
{
    if (!$tables) { throw new RuntimeException('Target module dependency missing or disabled'); }
    $moduleName = $argv[1] === '--install-register' ? 'Register' : 'Captcha';
    $module = $loader->loadModuleFS($moduleName, false);
    $moduleQuoted = \GDO\Core\GDO::quoteS($moduleName);
    if (!$module || $module->isPersisted() || $db->queryRead("SELECT module_id FROM gdo_module WHERE module_name={$moduleQuoted}")->fetch_assoc())
    {
        throw new RuntimeException('Target module state already exists; refusing installation');
    }
    foreach ($module->getClasses() as $class)
    {
        $table = $class::table()->gdoTableName();
        $quoted = \GDO\Core\GDO::quoteS($table);
        if ($db->queryRead("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME={$quoted}")->fetch_assoc())
        {
            throw new RuntimeException('Target module state already exists; refusing installation');
        }
    }
    foreach ($moduleName === 'Register' ? ['AboutMe', 'Cronjob', 'Login', 'Mail'] : ['Session'] as $dependency)
    {
        $quoted = \GDO\Core\GDO::quoteS($dependency);
        if (!$db->queryRead("SELECT module_id FROM gdo_module WHERE module_name={$quoted} AND module_enabled=1")->fetch_assoc())
        {
            throw new RuntimeException('Target module dependency missing or disabled');
        }
    }
    if (!Installer::installModules([$module])) { throw new RuntimeException('Target module installation failed'); }
    $module->onAfterInstall();
    \GDO\DB\Cache::remove('gdo_modules');
    echo "{$moduleName} module installed; no Badger seed was run.\n";
    exit(0);
}
$names = Installer::getDependencyNames('Badger', true);
$names[] = 'Badger';
$modules = [];
foreach (array_unique($names) as $name)
{
    $module = $loader->loadModuleFS($name, false);
    if (!$module || !$module->checkSystemDependencies()) { throw new RuntimeException("Missing or invalid dependency: {$name}"); }
    if ($argv[1] !== '--resume-partial' || !$module->isPersisted()) { $modules[] = $module; }
}
if ($argv[1] === '--preflight')
{
    echo 'Badger preflight: isolated database; ' . count($modules) . " module dependencies checked; no installation performed.\n";
    exit(0);
}
echo 'Badger bootstrap: isolated database; ' . count($modules) . " modules to install.\n";
if (!Installer::installModules($modules)) { throw new RuntimeException('Module installation failed'); }
foreach ($modules as $module) { $module->onAfterInstall(); }
if ($argv[1] === '--resume-partial') { $loader->getModule('Badger')->onInstall(); }
echo "Badger module installation finished; configure an administrator and TLS before exposing the site.\n";
