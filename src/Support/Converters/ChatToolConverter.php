<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Support\Converters;

use AiSdk\Tool;
use AiSdk\ToolChoice;

/**
 * Converts portable Tools / ToolChoice to OpenAI chat-completions format.
 */
final class ChatToolConverter
{
    /**
     * @param  array<int, Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    public static function convert(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->inputSchemaForProvider(),
                ],
            ];
        }

        return $out;
    }

    /**
     * @return string|array<string, mixed>
     */
    public static function choice(?ToolChoice $choice): string|array
    {
        if ($choice === null) {
            return 'auto';
        }

        return match ($choice->type) {
            ToolChoice::TOOL => [
                'type' => 'function',
                'function' => ['name' => (string) $choice->toolName],
            ],
            default => $choice->type,
        };
    }
}
