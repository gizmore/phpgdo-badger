<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Badger\Module_Badger;

final class AIConsent
{
    public static function configuration(): array
    {
        $module = Module_Badger::instance();
        return ['class' => (string) $module->getConfigVar('bb_ai_provider_class'),
            'model' => (string) $module->getConfigVar('bb_ai_ollama_model')];
    }

    public static function fingerprint(?array $configuration = null): string
    {
        return hash('sha256', json_encode($configuration ?? self::configuration(), JSON_THROW_ON_ERROR));
    }
}
