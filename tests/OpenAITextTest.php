<?php

declare(strict_types=1);

use AiSdk\Content;
use AiSdk\Generate;
use AiSdk\OpenAI;
use AiSdk\OpenAI\Tests\Fakes\FakeHttpClient;
use AiSdk\Reasoning;
use AiSdk\Support\Sdk;

afterEach(function () {
    Generate::reset();
    OpenAI::reset();
});

function configureWith(FakeHttpClient $client): void
{
    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    Generate::configure(new Sdk(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

it('generates text end to end through the OpenAI vertical', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'chatcmpl_openai',
        'object' => 'chat.completion',
        'created' => 1710000000,
        'model' => 'gpt-4o',
        'system_fingerprint' => 'fp_openai',
        'choices' => [['index' => 0, 'message' => ['content' => 'Hello from OpenAI'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3],
    ]));
    configureWith($client);

    OpenAI::create(['apiKey' => 'sk-test']);

    $result = Generate::text('Hi')->model(OpenAI::model('gpt-4o'))->run();

    expect($result->text)->toBe('Hello from OpenAI')
        ->and($result->usage->inputTokens)->toBe(12)
        ->and($result->providerMetadata['openai']['id'])->toBe('chatcmpl_openai')
        ->and($result->providerMetadata['openai']['model'])->toBe('gpt-4o')
        ->and($result->providerMetadata['openai']['choice_finish_reason'])->toBe('stop');

    // Verifies the request body the model built.
    $body = $client->sentBody();
    expect($body['model'])->toBe('gpt-4o')
        ->and($body['messages'][0]['role'])->toBe('user')
        ->and($body['stream'])->toBeFalse();

    // Verifies auth header applied.
    expect($client->lastRequest->getHeaderLine('Authorization'))->toBe('Bearer sk-test');
});

it('maps a 401 to an authentication exception', function () {
    $client = new FakeHttpClient(401, json_encode(['error' => ['message' => 'bad key']]));
    configureWith($client);
    OpenAI::create(['apiKey' => 'sk-bad']);

    Generate::text('Hi')->model(OpenAI::model('gpt-4o'))->run();
})->throws(\AiSdk\Exceptions\AuthenticationException::class);

it('sends image input for image-capable models', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Looks good'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3],
    ]));
    configureWith($client);

    OpenAI::create(['apiKey' => 'sk-test']);

    Generate::text()
        ->messages([
            \AiSdk\Message::user([
                Content::text('What is this?'),
                Content::image('https://example.com/photo.png'),
            ]),
        ])
        ->model(OpenAI::model('gpt-4o'))
        ->run();

    $body = $client->sentBody();

    expect($body['messages'][0]['content'][1])->toBe([
        'type' => 'image_url',
        'image_url' => ['url' => 'https://example.com/photo.png'],
    ]);
});

it('blocks unsupported file input before sending an OpenAI request', function () {
    $client = new FakeHttpClient(200, json_encode([]));
    configureWith($client);
    OpenAI::create(['apiKey' => 'sk-test']);

    Generate::text()
        ->messages([
            \AiSdk\Message::user([
                Content::text('Read this.'),
                Content::file('JVBERi0=', mimeType: 'application/pdf', filename: 'report.pdf', encoding: \AiSdk\InputEncoding::Base64),
            ]),
        ])
        ->model(OpenAI::model('gpt-4o'))
        ->run();
})->throws(\AiSdk\Exceptions\CapabilityNotSupportedException::class);

it('loads model capabilities from resources models json', function () {
    OpenAI::create(['apiKey' => 'sk-test']);

    $model = OpenAI::model('gpt-4o');

    expect($model->supports(\AiSdk\Capability::ImageInput))->toBeTrue()
        ->and($model->supports(\AiSdk\Capability::FileInput))->toBeFalse();
});

it('does not silently ignore explicit reasoning token budgets', function () {
    $client = new FakeHttpClient(200, json_encode([]));
    configureWith($client);
    OpenAI::create(['apiKey' => 'sk-test']);

    Generate::text('Think briefly.')
        ->model(OpenAI::model('o3-mini'))
        ->reasoning(Reasoning::budget(4096))
        ->run();
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class);
