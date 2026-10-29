<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Decisions;

use AiSdk\Content;
use AiSdk\ContentSource;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\DecisionModelInterface;
use AiSdk\Decisions\Question;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\OpenAI\OpenAIOptions;
use AiSdk\Requests\DecisionModelRequest;
use AiSdk\Responses\DecisionModelResponse;

final class OpenAIDecisionModel extends BaseModel implements DecisionModelInterface
{
    public function __construct(private readonly string $id, private readonly OpenAIOptions $options) {}
    public function provider(): string
    {
        return OpenAIOptions::PROVIDER_NAME;
    }
    public function modelId(): string
    {
        return $this->id;
    }

    public function decide(DecisionModelRequest $request): DecisionModelResponse
    {
        if (count($request->questions) < 1 || count($request->questions) > 400) {
            throw new InvalidArgumentException('OpenAI Decisions requires 1 to 400 questions.');
        }
        $input = $request->input;
        if (is_array($input) || $request->images !== []) {
            $parts = is_array($input) ? [['type' => 'input_json', 'data' => $input]] : [['type' => 'input_text', 'text' => $input]];
            foreach ($request->images as $image) {
                $parts[] = self::content($image);
            }
            $input = [['role' => 'user', 'content' => $parts]];
        }
        foreach (['model', 'input', 'questions'] as $reserved) {
            if (array_key_exists($reserved, $request->providerOptions)) {
                throw new InvalidArgumentException('Decision provider options cannot override model, input, or questions.');
            }
        }
        $body = ['model' => $this->id, 'input' => $input, 'questions' => array_map(self::question(...), $request->questions)] + $request->providerOptions;
        $payload = $this->runner($this->options->sdk)->postJson(rtrim($this->options->baseUrl, '/') . '/decisions', $body, $this->options->authHeaders(), $this->provider());

        return DecisionResponseParser::parse($payload);
    }

    /** @return array<string, mixed> */
    private static function question(Question $question): array
    {
        $result = ['name' => $question->name, 'type' => $question->kind, 'instructions' => is_array($question->instructions) ? array_map(self::content(...), $question->instructions) : $question->instructions];
        if ($question->kind === 'choice') {
            $result['choices'] = [];
            foreach ($question->choices as $value => $description) {
                $result['choices'][] = ['value' => $value, 'description' => $description];
            }
        }
        if ($question->kind === 'score') {
            if (count($question->levels) > 10) {
                throw new InvalidArgumentException('OpenAI Decisions supports at most 10 score levels.');
            }
            $result['levels'] = array_map(static fn(string $level): array => ['label' => $level], $question->levels);
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function content(Content $part): array
    {
        if ($part->type === Content::TYPE_TEXT) {
            return ['type' => 'input_text', 'text' => (string) $part->textValue()];
        }
        if ($part->type !== Content::TYPE_IMAGE || $part->fileReference() !== null) {
            throw new InvalidArgumentException('Decisions accepts text and inline or URL images, not file ids.');
        }
        $url = $part->source() === ContentSource::Url ? $part->url() : $part->dataUri();
        if ($url === null) {
            throw new InvalidArgumentException('Decision image has no usable data.');
        }

        return ['type' => 'input_image', 'image_url' => $url];
    }
}
