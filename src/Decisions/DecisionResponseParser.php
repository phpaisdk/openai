<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Decisions;

use AiSdk\Decisions\Answer;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\Responses\DecisionModelResponse;
use AiSdk\Support\Usage;

/** @internal */
final class DecisionResponseParser
{
    /** @param array<string, mixed> $payload */
    public static function parse(array $payload): DecisionModelResponse
    {
        if (! is_array($payload['answers'] ?? null) || ! array_is_list($payload['answers'])) {
            self::invalid();
        }
        $answers = [];
        foreach ($payload['answers'] as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null) || isset($answers[$item['name']]) || ! is_string($item['type'] ?? null)) {
                self::invalid();
            }
            $type = $item['type'];
            $value = match ($type) {
                'predicate' => self::number($item['probability'] ?? null),
                'score' => self::number($item['score'] ?? null),
                'choice' => is_string($item['choice'] ?? null) ? $item['choice'] : self::invalid(),
                'refusal' => null,
                default => self::invalid(),
            };
            $probabilities = [];
            if (in_array($type, ['choice', 'score'], true)) {
                if (! is_array($item['probabilities'] ?? null)) {
                    self::invalid();
                }
                foreach ($item['probabilities'] as $probability) {
                    if (! is_array($probability) || (! is_string($probability['value'] ?? null) && ! is_int($probability['value'] ?? null)) || array_key_exists($probability['value'], $probabilities)) {
                        self::invalid();
                    }
                    $probabilities[$probability['value']] = self::number($probability['probability'] ?? null);
                }
            }
            $answers[$item['name']] = new Answer($type, $value, isset($item['confidence']) ? self::number($item['confidence']) : null, $probabilities);
        }
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $details = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];

        return new DecisionModelResponse($answers, new Usage(
            inputTokens: self::tokens($usage['input_tokens'] ?? 0),
            outputTokens: self::tokens($usage['output_tokens'] ?? 0),
            cachedInputTokens: isset($details['cached_tokens']) ? self::tokens($details['cached_tokens']) : null,
            cacheWriteInputTokens: isset($details['cache_write_tokens']) ? self::tokens($details['cache_write_tokens']) : null,
        ), $payload);
    }

    private static function number(mixed $value): float
    {
        if (! is_int($value) && ! is_float($value)) {
            self::invalid();
        }

        return (float) $value;
    }

    private static function tokens(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            self::invalid();
        }

        return $value;
    }

    private static function invalid(): never
    {
        throw InvalidResponseException::forProvider('openai', 'OpenAI returned malformed decision answers or usage.');
    }
}
