<?php

declare(strict_types=1);

use AiSdk\Generate;
use AiSdk\OpenAI;
use AiSdk\OpenAI\Tests\Fakes\FakeHttpClient;
use AiSdk\Support\Sdk;

afterEach(function () {
    Generate::reset();
    OpenAI::reset();
});

function configureOpenAIImagesWith(FakeHttpClient $client): void
{
    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    Generate::configure(new Sdk(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

it('generates images through the OpenAI image endpoint', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'img_1',
        'created' => 1710000000,
        'model' => 'gpt-image-1',
        'data' => [
            ['b64_json' => base64_encode('image-bytes')],
        ],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 2, 'total_tokens' => 12],
    ]));
    configureOpenAIImagesWith($client);

    OpenAI::create(['apiKey' => 'sk-test']);

    $result = Generate::image('A red cube')
        ->model(OpenAI::image('gpt-image-1'))
        ->count(2)
        ->size('1024x1024')
        ->providerOptions('openai', ['background' => 'transparent'])
        ->run();

    expect($result->output->bytes())->toBe('image-bytes')
        ->and($result->usage->inputTokens)->toBe(10)
        ->and($result->providerMetadata['openai']['id'])->toBe('img_1')
        ->and($result->providerMetadata['openai']['model'])->toBe('gpt-image-1');

    $body = $client->sentBody();
    expect((string) $client->lastRequest?->getUri())->toBe('https://api.openai.com/v1/images/generations')
        ->and($body['model'])->toBe('gpt-image-1')
        ->and($body['prompt'])->toBe('A red cube')
        ->and($body['n'])->toBe(2)
        ->and($body['size'])->toBe('1024x1024')
        ->and($body['response_format'])->toBe('b64_json')
        ->and($body['background'])->toBe('transparent');
});

it('maps common aspect ratios to OpenAI image sizes when size is omitted', function () {
    $client = new FakeHttpClient(200, json_encode([
        'data' => [['b64_json' => base64_encode('image-bytes')]],
    ]));
    configureOpenAIImagesWith($client);

    OpenAI::create(['apiKey' => 'sk-test']);

    Generate::image('A landscape')
        ->model(OpenAI::image('gpt-image-1'))
        ->aspectRatio('16:9')
        ->run();

    expect($client->sentBody()['size'])->toBe('1536x1024');
});

it('rejects unsupported OpenAI aspect ratios when size is omitted', function () {
    $client = new FakeHttpClient(200, json_encode([]));
    configureOpenAIImagesWith($client);

    OpenAI::create(['apiKey' => 'sk-test']);

    Generate::image('A panorama')
        ->model(OpenAI::image('gpt-image-1'))
        ->aspectRatio('21:9')
        ->run();
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class);

it('rejects portable seed for OpenAI image generation', function () {
    $client = new FakeHttpClient(200, json_encode([]));
    configureOpenAIImagesWith($client);

    OpenAI::create(['apiKey' => 'sk-test']);

    Generate::image('A deterministic image')
        ->model(OpenAI::image('gpt-image-1'))
        ->seed(123)
        ->run();
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class);

it('loads image generation support from resources models json', function () {
    OpenAI::create(['apiKey' => 'sk-test']);

    expect(OpenAI::image('gpt-image-1')->supports(\AiSdk\Capability::ImageGeneration))->toBeTrue()
        ->and(OpenAI::model('gpt-4o')->supports(\AiSdk\Capability::ImageGeneration))->toBeFalse();
});
