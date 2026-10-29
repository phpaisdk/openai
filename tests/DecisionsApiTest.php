<?php

declare(strict_types=1);

use AiSdk\Content;
use AiSdk\Decisions\Question;
use AiSdk\Generate;
use AiSdk\OpenAI;
use AiSdk\OpenAI\Tests\Fakes\FakeHttpClient;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

it('uses the native decisions endpoint with JSON evidence and typed questions', function () {
    $http = new FakeHttpClient(200, json_encode(['answers' => [
        ['name' => 'urgent', 'type' => 'predicate', 'probability' => 0.8],
        ['name' => 'team', 'type' => 'choice', 'choice' => 'billing', 'confidence' => 0.8, 'probabilities' => [['value' => 'billing', 'probability' => 0.9], ['value' => 'sales', 'probability' => 0.1]]],
        ['name' => 'severity', 'type' => 'score', 'score' => 0.25, 'confidence' => 0.5, 'probabilities' => [['value' => 0, 'probability' => 0.75], ['value' => 1, 'probability' => 0.25]]],
    ], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0, 'input_tokens_details' => ['cached_tokens' => 5, 'cache_write_tokens' => 2]]]));
    $factory = new Psr17Factory();
    OpenAI::create(['apiKey' => 'test', 'sdk' => new Sdk($http, $factory, $factory)]);
    $result = Generate::decision(['ticket' => 'double charge'])->model(OpenAI::model('opaque-model'))
        ->questions(Question::predicate('urgent', 'Urgent?'), Question::choice('team', 'Route', ['billing' => 'Payment', 'sales' => 'Sales']), Question::score('severity', 'Severity', ['Low', 'High']))
        ->images(Content::image('https://example.test/photo.png'))
        ->providerOptions('openai', ['prompt_cache_key' => 'shared-input'])->run();
    expect($http->lastRequest->getUri()->getPath())->toBe('/v1/decisions')
        ->and($http->sentBody()['model'])->toBe('opaque-model')
        ->and($http->sentBody()['input'][0]['content'][0])->toBe(['type' => 'input_json', 'data' => ['ticket' => 'double charge']])
        ->and($http->sentBody()['questions'][2]['levels'])->toBe([['label' => 'Low'], ['label' => 'High']])
        ->and($result->answer('urgent')->yes())->toBeTrue()->and($result->answer('severity')->value)->toBe(0.25)
        ->and($result->usage->inputTokens)->toBe(10)->and($result->usage->cachedInputTokens)->toBe(5);
    OpenAI::reset();
});

it('retains a native refusal and rejects malformed probabilities', function () {
    $response = \AiSdk\OpenAI\Decisions\DecisionResponseParser::parse(['answers' => [['name' => 'a', 'type' => 'refusal']]]);
    expect($response->answers['a']->kind)->toBe('refusal');
    expect(fn() => \AiSdk\OpenAI\Decisions\DecisionResponseParser::parse(['answers' => [['name' => 'a', 'type' => 'predicate', 'probability' => 'yes']]]))->toThrow(\AiSdk\Exceptions\InvalidResponseException::class);
});

it('validates native limits and supports multimodal question instructions', function () {
    $http = new FakeHttpClient(200, json_encode(['answers' => [['name' => 'same', 'type' => 'predicate', 'probability' => 0.8]]]));
    $factory = new Psr17Factory();
    OpenAI::create(['apiKey' => 'test', 'sdk' => new Sdk($http, $factory, $factory)]);
    Generate::decision('reference')->model(OpenAI::model('opaque'))->questions(Question::predicate('same', [Content::text('Does this match?'), Content::image('https://example.test/a.png')]))->run();
    expect($http->sentBody()['questions'][0]['instructions'][1])->toBe(['type' => 'input_image', 'image_url' => 'https://example.test/a.png']);
    expect(fn() => Generate::decision('input')->model(OpenAI::model('opaque'))->questions(Question::score('score', 'Rate', array_fill(0, 11, 'Level')))->run())->toThrow(AiSdk\Exceptions\InvalidArgumentException::class);
    expect($http->requests)->toHaveCount(1);
    OpenAI::reset();
});
