<?php

declare(strict_types=1);

namespace AiSdk\OpenAI;

use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Contracts\EmbeddingProviderInterface;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\ImageProviderInterface;
use AiSdk\Contracts\LiveProviderInterface;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Contracts\SpeechProviderInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TextProviderInterface;
use AiSdk\Contracts\TranscriptionModelInterface;
use AiSdk\Contracts\TranscriptionProviderInterface;
use AiSdk\Live\Contracts\LiveModelInterface;
use AiSdk\OpenAI\Models\OpenAIEmbeddingModel;
use AiSdk\OpenAI\Models\OpenAIImageModel;
use AiSdk\OpenAI\Models\OpenAILiveModel;
use AiSdk\OpenAI\Models\OpenAISpeechModel;
use AiSdk\OpenAI\Models\OpenAITextModel;
use AiSdk\OpenAI\Models\OpenAITranscriptionModel;

final class OpenAIProvider extends BaseProvider implements EmbeddingProviderInterface, ImageProviderInterface, LiveProviderInterface, SpeechProviderInterface, TextProviderInterface, TranscriptionProviderInterface
{
    public function __construct(public readonly OpenAIOptions $options) {}

    public function name(): string
    {
        return OpenAIOptions::PROVIDER_NAME;
    }

    protected function textModel(string $modelId): TextModelInterface
    {
        return new OpenAITextModel($modelId, $this->options);
    }

    protected function imageModel(string $modelId): ImageModelInterface
    {
        return new OpenAIImageModel($modelId, $this->options);
    }

    protected function speechModel(string $modelId): SpeechModelInterface
    {
        return new OpenAISpeechModel($modelId, $this->options);
    }

    protected function transcriptionModel(string $modelId): TranscriptionModelInterface
    {
        return new OpenAITranscriptionModel($modelId, $this->options);
    }

    protected function embeddingModel(string $modelId): EmbeddingModelInterface
    {
        return new OpenAIEmbeddingModel($modelId, $this->options);
    }

    protected function liveModel(string $modelId): LiveModelInterface
    {
        return new OpenAILiveModel($modelId, $this->options);
    }
}
