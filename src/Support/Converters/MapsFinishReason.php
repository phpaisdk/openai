<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Support\Converters;

use AiSdk\FinishReason;

final class MapsFinishReason
{
    public static function map(?string $reason): FinishReason
    {
        return match ($reason) {
            'stop', 'end_turn' => FinishReason::Stop,
            'length', 'max_tokens' => FinishReason::Length,
            'tool_calls', 'function_call' => FinishReason::ToolCalls,
            'content_filter' => FinishReason::ContentFilter,
            null => FinishReason::Stop,
            default => FinishReason::Unknown,
        };
    }
}
