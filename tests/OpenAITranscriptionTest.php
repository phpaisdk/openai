<?php

declare(strict_types=1);

use AiSdk\Content;
use AiSdk\Generate;
use AiSdk\OpenAI;
use AiSdk\OpenAI\Tests\Fakes\FakeHttpClient;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    OpenAI::reset();
});

it('transcribes audio through the OpenAI transcription endpoint', function () {
    $client = new FakeHttpClient(200, json_encode([
        'text' => 'Hello world.',
        'language' => 'en',
        'duration' => 1.25,
    ], JSON_THROW_ON_ERROR));
    $factory = new Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    OpenAI::create(['apiKey' => 'sk-test']);

    $result = Generate::transcription(Content::audio('wav-bytes', 'audio/wav', 'clip.wav'))
        ->model(OpenAI::model('gpt-4o-transcribe'))
        ->providerOptions('openai', ['language' => 'en'])
        ->run();

    $body = (string) $client->lastRequest?->getBody();
    expect($result->output->text)->toBe('Hello world.')
        ->and($result->output->language)->toBe('en')
        ->and((string) $client->lastRequest?->getUri())->toBe('https://api.openai.com/v1/audio/transcriptions')
        ->and($client->lastRequest?->getHeaderLine('Content-Type'))->toStartWith('multipart/form-data; boundary=')
        ->and($body)->toContain('name="model"', 'gpt-4o-transcribe', 'name="language"', 'clip.wav', 'wav-bytes');
});
