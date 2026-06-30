<?php

declare(strict_types=1);

namespace AiSdk\OpenAI\Support\Converters;

use AiSdk\Content;
use AiSdk\ContentSource;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Message;

/**
 * Converts portable Messages to the OpenAI chat-completions `messages` array.
 */
final class ChatMessageConverter
{
    /**
     * @param  array<int, Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    public static function convert(array $messages, ?string $system = null): array
    {
        $out = [];

        if ($system !== null && $system !== '') {
            $out[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($messages as $message) {
            $out[] = self::convertMessage($message);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function convertMessage(Message $message): array
    {
        if ($message->role === Message::ROLE_TOOL) {
            return [
                'role' => 'tool',
                'tool_call_id' => $message->toolCallId ?? '',
                'name' => $message->name ?? '',
                'content' => $message->text(),
            ];
        }

        if ($message->role === Message::ROLE_ASSISTANT && $message->toolCalls !== []) {
            return [
                'role' => 'assistant',
                'content' => $message->text() !== '' ? $message->text() : null,
                'tool_calls' => array_map(
                    fn($call): array => [
                        'id' => $call->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $call->name,
                            'arguments' => json_encode($call->arguments, JSON_THROW_ON_ERROR),
                        ],
                    ],
                    $message->toolCalls,
                ),
            ];
        }

        $parts = array_map(self::convertContent(...), $message->content);

        if (count($parts) === 1 && ($parts[0]['type'] ?? null) === 'text') {
            return ['role' => $message->role, 'content' => $parts[0]['text']];
        }

        return ['role' => $message->role, 'content' => $parts];
    }

    /**
     * @return array<string, mixed>
     */
    private static function convertContent(Content $content): array
    {
        return match ($content->type) {
            Content::TYPE_TEXT => ['type' => 'text', 'text' => (string) $content->textValue()],
            Content::TYPE_IMAGE => self::image($content),
            Content::TYPE_AUDIO => self::audio($content),
            Content::TYPE_FILE => self::file($content),
            default => throw new InvalidArgumentException("Unsupported content type [{$content->type}]."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function image(Content $content): array
    {
        return [
            'type' => 'image_url',
            'image_url' => ['url' => $content->source() === ContentSource::Url ? (string) $content->url() : (string) $content->dataUri()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function audio(Content $content): array
    {
        return [
            'type' => 'input_audio',
            'input_audio' => [
                'data' => (string) $content->base64Data(),
                'format' => self::audioFormat($content->mimeType()),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function file(Content $content): array
    {
        return [
            'type' => 'file',
            'file' => [
                'filename' => $content->filename() ?? 'input',
                'file_data' => $content->source() === ContentSource::Url ? (string) $content->url() : (string) $content->dataUri(),
            ],
        ];
    }

    private static function audioFormat(?string $mimeType): string
    {
        return match ($mimeType) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            default => 'wav',
        };
    }
}
