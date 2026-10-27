<?php

declare(strict_types=1);

use AiSdk\Content;
use AiSdk\Files\FileReference;
use AiSdk\Generate;
use AiSdk\Message;
use AiSdk\OpenAI;
use AiSdk\OpenAI\Tests\Fakes\FakeHttpClient;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

it('routes stored attachments through Responses for synchronous and streamed requests', function (bool $stream) {
    $response = ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ok']]]]];
    $body = $stream ? 'data: ' . json_encode(['type' => 'response.output_text.delta', 'delta' => 'ok']) . "\n\n" . 'data: ' . json_encode(['type' => 'response.completed', 'response' => $response]) . "\n\n" : json_encode($response);
    $http = new FakeHttpClient(200, $body, $stream ? 'text/event-stream' : 'application/json');
    $factory = new Psr17Factory();
    OpenAI::create(['apiKey' => 'test', 'sdk' => new Sdk($http, $factory, $factory)]);
    $pending = Generate::text()->model(OpenAI::model('opaque'))->messages([Message::user([Content::storedFile(new FileReference('openai', 'file-a'))])])->providerOptions('openai', ['api' => 'responses'])->cache(\AiSdk\PromptCache::key('stable-files'));
    $result = $stream ? $pending->stream()->run() : $pending->run();
    expect($result->text)->toBe('ok')->and($http->lastRequest->getUri()->getPath())->toBe('/v1/responses')
        ->and($http->sentBody()['prompt_cache_key'])->toBe('stable-files')->and($http->sentBody())->not->toHaveKey('api')->and($http->sentBody()['input'][0]['content'][0])->toBe(['type' => 'input_file', 'file_id' => 'file-a']);
    OpenAI::reset();
})->with([false, true]);
