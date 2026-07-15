<?php

declare(strict_types=1);

use AiSdk\Generate;
use AiSdk\OpenAI;
use AiSdk\OpenAI\Tests\Fakes\FakeHttpClient;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    OpenAI::reset();
});

it('generates OpenAI embeddings', function () {
    $client = new FakeHttpClient(200, json_encode([
        'object' => 'list',
        'model' => 'text-embedding-3-small',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.3, 0.4]],
        ],
        'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
    ]));
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    OpenAI::create(['apiKey' => 'sk-test']);

    $result = Generate::embedding(['First document', 'Second document'])
        ->model(OpenAI::model('text-embedding-3-small'))
        ->dimensions(256)
        ->providerOptions('openai', ['user' => 'user-123'])
        ->run();

    expect($result->output->vector)->toBe([0.1, 0.2])
        ->and($result->embeddings[1]->vector)->toBe([0.3, 0.4])
        ->and($result->usage->inputTokens)->toBe(7)
        ->and($result->providerMetadata['openai']['model'])->toBe('text-embedding-3-small')
        ->and((string) $client->lastRequest?->getUri())->toBe('https://api.openai.com/v1/embeddings')
        ->and($client->sentBody())->toMatchArray([
            'model' => 'text-embedding-3-small',
            'input' => ['First document', 'Second document'],
            'encoding_format' => 'float',
            'dimensions' => 256,
            'user' => 'user-123',
        ]);
});

it('accepts opaque embedding model ids', function () {
    OpenAI::create(['apiKey' => 'sk-test']);

    expect(OpenAI::model('future-embedding-model')->modelId())->toBe('future-embedding-model');
});
