<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Contracts\TextModelInterface;
use AiSdk\OpenAI\OpenAIOptions;
use AiSdk\OpenAI\OpenAIProvider;
use AiSdk\Support\Concerns\RegistersModels;

/**
 * Friendly facade for the OpenAI provider.
 *
 *   $model = OpenAI::model('gpt-4o');
 */
final class OpenAI
{
    use RegistersModels;

    private static ?OpenAIProvider $default = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public static function create(array $config = []): OpenAIProvider
    {
        return self::$default = new OpenAIProvider(OpenAIOptions::fromArray($config));
    }

    public static function default(): OpenAIProvider
    {
        return self::$default ??= self::create();
    }

    public static function reset(): void
    {
        self::$default = null;
    }

    public static function model(string $modelId): TextModelInterface
    {
        return self::default()->textModel($modelId);
    }
}
