<?php

declare(strict_types=1);

namespace AiSdk\OpenAI;

use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\OpenAI\Models\OpenAIImageModel;
use AiSdk\OpenAI\Models\OpenAISpeechModel;
use AiSdk\OpenAI\Models\OpenAITextModel;

final class OpenAIProvider extends BaseProvider
{
    public function __construct(public readonly OpenAIOptions $options) {}

    public function name(): string
    {
        return OpenAIOptions::PROVIDER_NAME;
    }

    public function textModel(string $modelId): TextModelInterface
    {
        return new OpenAITextModel($modelId, $this->options, $this->modelRegistry());
    }

    public function imageModel(string $modelId): ImageModelInterface
    {
        return new OpenAIImageModel($modelId, $this->options, $this->modelRegistry());
    }

    public function speechModel(string $modelId): SpeechModelInterface
    {
        return new OpenAISpeechModel($modelId, $this->options, $this->modelRegistry());
    }
}
