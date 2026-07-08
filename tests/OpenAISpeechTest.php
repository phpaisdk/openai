<?php

declare(strict_types=1);

use AiSdk\Capability;
use AiSdk\Generate;
use AiSdk\OpenAI;
use AiSdk\OpenAI\Tests\Fakes\FakeHttpClient;
use AiSdk\Support\Sdk;

afterEach(function () {
    Generate::reset();
    OpenAI::reset();
});

function configureOpenAISpeechWith(FakeHttpClient $client): void
{
    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    Generate::configure(new Sdk(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

it('generates speech through the OpenAI audio endpoint', function () {
    $client = new FakeHttpClient(200, 'audio-bytes', 'audio/mpeg');
    configureOpenAISpeechWith($client);

    OpenAI::create(['apiKey' => 'sk-test']);

    $result = Generate::speech('Read this aloud.')
        ->model(OpenAI::speech('gpt-4o-mini-tts'))
        ->voice('verse')
        ->format('mp3')
        ->providerOptions('openai', ['speed' => 1.1])
        ->run();

    expect($result->output->data)->toBe('audio-bytes')
        ->and($result->output->mimeType)->toBe('audio/mpeg')
        ->and($result->providerMetadata['openai']['model'])->toBe('gpt-4o-mini-tts')
        ->and($result->providerMetadata['openai']['format'])->toBe('mp3');

    $body = $client->sentBody();
    expect((string) $client->lastRequest?->getUri())->toBe('https://api.openai.com/v1/audio/speech')
        ->and($body['model'])->toBe('gpt-4o-mini-tts')
        ->and($body['input'])->toBe('Read this aloud.')
        ->and($body['voice'])->toBe('verse')
        ->and($body['response_format'])->toBe('mp3')
        ->and($body['speed'])->toBe(1.1)
        ->and($client->lastRequest?->getHeaderLine('Accept'))->toBe('audio/mpeg');
});

it('uses the requested OpenAI speech format for the response accept header', function () {
    $client = new FakeHttpClient(200, 'wav-bytes', 'audio/wav');
    configureOpenAISpeechWith($client);

    OpenAI::create(['apiKey' => 'sk-test']);

    Generate::speech('Read this as wav.')
        ->model(OpenAI::speech('gpt-4o-mini-tts'))
        ->format('wav')
        ->run();

    expect($client->sentBody()['response_format'])->toBe('wav')
        ->and($client->lastRequest?->getHeaderLine('Accept'))->toBe('audio/wav');
});

it('loads speech generation support from resources models json', function () {
    OpenAI::create(['apiKey' => 'sk-test']);

    expect(OpenAI::speech('gpt-4o-mini-tts')->supports(Capability::SpeechGeneration))->toBeTrue()
        ->and(OpenAI::model('gpt-4o')->supports(Capability::SpeechGeneration))->toBeFalse();
});
