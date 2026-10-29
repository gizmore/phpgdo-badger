<?php
declare(strict_types=1);
namespace GDO\Badger;

use ReflectionClass;
use RuntimeException;

final class AIReviewProviderFactory
{
    public static function create(string $class): ?AIReviewProvider
    {
        if ($class === '') { return null; }
        if (!class_exists($class) || !is_subclass_of($class, AIReviewProvider::class))
        {
            throw new RuntimeException('Invalid AL provider configuration');
        }
        if ($class === OllamaAIReviewProvider::class && trim((string) Module_Badger::instance()->getConfigVar('bb_ai_ollama_model')) === '')
        {
            throw new RuntimeException('Invalid AL provider configuration');
        }
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if (!$reflection->isInstantiable() || ($constructor && $constructor->getNumberOfRequiredParameters() > 0))
        {
            throw new RuntimeException('Invalid AL provider configuration');
        }
        return $reflection->newInstance();
    }
}
