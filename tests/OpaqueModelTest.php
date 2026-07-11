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

it('passes an opaque OpenAI model id directly to the provider', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Hello'], 'finish_reason' => 'stop']],
    ]));
    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    Generate::configure(new Sdk($client, $factory, $factory));
    OpenAI::create(['apiKey' => 'sk-test']);

    Generate::text('Hi')->model(OpenAI::model('vendor/private-model'))->run();

    expect($client->sentBody()['model'])->toBe('vendor/private-model');
});
