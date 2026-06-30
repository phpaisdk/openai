<?php

declare(strict_types=1);

namespace AiSdk\OpenAI;

use AiSdk\Support\Sdk;
use AiSdk\Utils\Support\Env;
use AiSdk\Utils\Support\Url;

final class OpenAIOptions
{
    public const string DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    public const string PROVIDER_NAME = 'openai';

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly ?string $organization = null,
        public readonly array $headers = [],
        public readonly ?Sdk $sdk = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config = []): self
    {
        $apiKey = Env::loadApiKey(
            isset($config['apiKey']) ? (string) $config['apiKey'] : null,
            'OPENAI_API_KEY',
            self::PROVIDER_NAME,
        );

        $baseUrl = Url::withoutTrailingSlash(
            Env::loadOptionalSetting(isset($config['baseUrl']) ? (string) $config['baseUrl'] : null, 'OPENAI_BASE_URL')
                ?? self::DEFAULT_BASE_URL,
        );

        $organization = Env::loadOptionalSetting(
            isset($config['organization']) ? (string) $config['organization'] : null,
            'OPENAI_ORGANIZATION',
        );

        /** @var array<string, string> $headers */
        $headers = isset($config['headers']) && is_array($config['headers']) ? $config['headers'] : [];
        $sdk = $config['sdk'] ?? null;

        return new self(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            organization: $organization,
            headers: $headers,
            sdk: $sdk instanceof Sdk ? $sdk : null,
        );
    }

    /**
     * @return array<string, string>
     */
    public function authHeaders(): array
    {
        $headers = array_merge(['Authorization' => 'Bearer ' . $this->apiKey], $this->headers);

        if ($this->organization !== null) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        return $headers;
    }
}
