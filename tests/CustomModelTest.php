<?php

declare(strict_types=1);

use AiSdk\Capability;
use AiSdk\CapabilitySupportState;
use AiSdk\Content;
use AiSdk\Generate;
use AiSdk\ModelDefinition;
use AiSdk\OpenAI;
use AiSdk\OpenAI\Tests\Fakes\FakeHttpClient;
use AiSdk\Support\Sdk;

afterEach(function () {
    Generate::reset();
    OpenAI::reset();
});

function configureCustomOpenAIWith(FakeHttpClient $client): void
{
    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    Generate::configure(new Sdk(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

it('uses a registered custom model for text generation', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'chatcmpl_custom',
        'object' => 'chat.completion',
        'created' => 1710000000,
        'model' => 'gpt-custom',
        'choices' => [['index' => 0, 'message' => ['content' => 'Hello from custom'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3],
    ]));
    configureCustomOpenAIWith($client);

    OpenAI::create(['apiKey' => 'sk-test']);
    OpenAI::registerModel(new ModelDefinition(
        id: 'gpt-custom',
        capabilities: [Capability::TextGeneration, Capability::Streaming, Capability::TextInput],
    ));

    $result = Generate::text('Hi')->model(OpenAI::model('gpt-custom'))->run();

    expect($result->text)->toBe('Hello from custom')
        ->and($result->usage->inputTokens)->toBe(12);

    $body = $client->sentBody();
    expect($body['model'])->toBe('gpt-custom')
        ->and($body['messages'][0]['role'])->toBe('user');
});

it('registers a custom model with the terse facade signature', function () {
    OpenAI::create(['apiKey' => 'sk-test']);

    OpenAI::registerModel('gpt-custom', capabilities: [
        Capability::TextGeneration,
        Capability::Streaming,
        Capability::ToolCalling,
    ]);

    $model = OpenAI::model('gpt-custom');

    expect($model->supports(Capability::TextGeneration))->toBeTrue()
        ->and($model->supports(Capability::Streaming))->toBeTrue()
        ->and($model->supports(Capability::ToolCalling))->toBeTrue()
        ->and($model->supports(Capability::StructuredOutput))->toBeFalse();
});

it('reports declared capabilities for a registered custom model', function () {
    OpenAI::create(['apiKey' => 'sk-test']);
    OpenAI::create(['apiKey' => 'sk-test']);
    OpenAI::registerModel(new ModelDefinition(
        id: 'gpt-custom',
        capabilities: [Capability::TextGeneration, Capability::Streaming, Capability::TextInput],
    ));

    $model = OpenAI::model('gpt-custom');

    expect($model->supports(Capability::TextGeneration))->toBeTrue()
        ->and($model->supports(Capability::Streaming))->toBeTrue()
        ->and($model->supports(Capability::TextInput))->toBeTrue()
        ->and($model->supports(Capability::ImageInput))->toBeFalse()
        ->and($model->capability(Capability::TextGeneration)->state)->toBe(CapabilitySupportState::Supported)
        ->and($model->capability(Capability::Streaming)->state)->toBe(CapabilitySupportState::Supported)
        ->and($model->capability(Capability::ImageInput)->state)->toBe(CapabilitySupportState::NotSupported);
});

it('throws a capability exception for undeclared capabilities on a registered custom model', function () {
    $client = new FakeHttpClient(200, json_encode([]));
    configureCustomOpenAIWith($client);

    OpenAI::create(['apiKey' => 'sk-test']);
    OpenAI::registerModel(new ModelDefinition(
        id: 'gpt-custom',
        capabilities: [Capability::TextGeneration, Capability::Streaming, Capability::TextInput],
    ));

    Generate::text()
        ->messages([
            \AiSdk\Message::user([
                Content::text('Describe this.'),
                Content::image('https://example.com/photo.png'),
            ]),
        ])
        ->model(OpenAI::model('gpt-custom'))
        ->run();
})->throws(\AiSdk\Exceptions\CapabilityNotSupportedException::class);

it('assumes selected capabilities for an unknown custom model handle', function () {
    OpenAI::create(['apiKey' => 'sk-test']);

    $model = OpenAI::model('my-new-model')->assume([
        Capability::ToolCalling,
        Capability::StructuredOutput,
    ]);

    expect($model->supports(Capability::TextGeneration))->toBeTrue()
        ->and($model->supports(Capability::ToolCalling))->toBeTrue()
        ->and($model->supports(Capability::StructuredOutput))->toBeTrue()
        ->and($model->supports(Capability::ImageInput))->toBeFalse()
        ->and($model->capability(Capability::ToolCalling)->source)->toBe('user-assumed');
});

it('can allow all unknown capabilities for an unknown custom model handle', function () {
    OpenAI::create(['apiKey' => 'sk-test']);

    $model = OpenAI::model('my-new-model')->allowUnknownCapabilities();

    expect($model->supports(Capability::ToolCalling))->toBeTrue()
        ->and($model->supports(Capability::StructuredOutput))->toBeTrue()
        ->and($model->supports(Capability::ImageInput))->toBeTrue()
        ->and($model->capability(Capability::ImageInput)->source)->toBe('user-allowed-unknown-capabilities');
});

it('allows text generation for unknown unregistered models', function () {
    OpenAI::create(['apiKey' => 'sk-test']);

    $model = OpenAI::model('totally-unknown-model');

    expect($model->supports(Capability::TextGeneration))->toBeTrue()
        ->and($model->capability(Capability::TextGeneration)->source)->toBe('unknown-model-fallback');
});
