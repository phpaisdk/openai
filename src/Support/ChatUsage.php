<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Support;

use AiSdk\Support\Usage;

final class ChatUsage
{
    /**
     * @param  array<string, mixed>  $usage
     */
    public static function fromArray(array $usage): Usage
    {
        $details = $usage['completion_tokens_details'] ?? [];
        $promptDetails = $usage['prompt_tokens_details'] ?? [];

        return new Usage(
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            totalTokens: isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
            reasoningTokens: isset($details['reasoning_tokens']) ? (int) $details['reasoning_tokens'] : null,
            cachedInputTokens: isset($promptDetails['cached_tokens']) ? (int) $promptDetails['cached_tokens'] : null,
        );
    }
}
