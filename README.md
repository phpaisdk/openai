# aisdk/openai

Official OpenAI provider for the PHP AI SDK.

## Installation

```bash
composer require aisdk/openai
```

## Basic Usage

```php
use AiSdk\Generate;
use AiSdk\OpenAI;

$result = Generate::text()
    ->model(OpenAI::model('gpt-4o'))
    ->instructions('Write short, clear answers.')
    ->prompt('Explain closures in PHP.')
    ->run();

echo $result->text;
```

Default model shorthand:

```php
Generate::model(OpenAI::model('gpt-4o'));

$result = Generate::text('Explain closures in PHP.')->run();
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|---|---|---|
| `OPENAI_API_KEY` | API key for authentication | Required |
| `OPENAI_BASE_URL` | Base URL for API requests | `https://api.openai.com/v1` |
| `OPENAI_ORGANIZATION` | Organization ID header | None |

### Programmatic Configuration

```php
$provider = OpenAI::create([
    'apiKey' => 'sk-...',
    'baseUrl' => 'https://api.openai.com/v1',
    'organization' => 'org-...',
    'headers' => ['X-Custom-Header' => 'value'],
]);
```

## Supported Capabilities

| Capability | Support |
|---|---|
| Text generation | Native |
| Streaming | Native |
| Tool calling | Native |
| Structured output | Native (`json_schema`) |
| Reasoning | Native (`reasoning_effort`) |
| Image generation | Native |
| Speech generation | Native |
| Embeddings | Native |
| Text input | Native |
| Image input | Native |
| Audio input | Native |
| File input | Native |

## Streaming

```php
use AiSdk\Generate;
use AiSdk\OpenAI;

$stream = Generate::text('Tell me a story.')
    ->model(OpenAI::model('gpt-4o'))
    ->stream();

foreach ($stream->chunks() as $chunk) {
    echo $chunk;
}

$result = $stream->run();
```

## Embeddings

Generate one or more text embeddings in the same request:

```php
use AiSdk\Generate;
use AiSdk\OpenAI;

$result = Generate::embedding(['Search query', 'Document text'])
    ->model(OpenAI::embedding('text-embedding-3-small'))
    ->dimensions(256)
    ->providerOptions('openai', ['user' => 'user-123'])
    ->run();

$queryVector = $result->embeddings[0]->vector;
$documentVector = $result->embeddings[1]->vector;
```

`dimensions()` is supported by OpenAI's `text-embedding-3` models. Model IDs remain opaque, so OpenAI validates whether the selected model accepts the requested dimensions.

## Image Generation

```php
use AiSdk\Generate;
use AiSdk\OpenAI;

$result = Generate::image()
    ->model(OpenAI::image('gpt-image-1'))
    ->prompt('A clean app icon for a PHP AI SDK')
    ->size('1024x1024')
    ->run();

$result->output->save(__DIR__.'/icon.png');
```

## Speech Generation

```php
use AiSdk\Generate;
use AiSdk\OpenAI;

$result = Generate::speech()
    ->model(OpenAI::speech('gpt-4o-mini-tts'))
    ->input('Today is a wonderful day to build something people love.')
    ->voice('coral')
    ->format('mp3')
    ->providerOptions('openai', [
        'instructions' => 'Speak in a cheerful and positive tone.',
    ])
    ->run();

$result->output->save(__DIR__.'/speech.mp3');
```

## Structured Output

```php
use AiSdk\Generate;
use AiSdk\OpenAI;
use AiSdk\Schema;

$result = Generate::text()
    ->model(OpenAI::model('gpt-4o'))
    ->prompt('Extract the city and country from: Lahore, Pakistan.')
    ->output(Schema::object(
        name: 'address',
        properties: [
            Schema::string(name: 'city')->required(),
            Schema::string(name: 'country')->required(),
        ],
    ))
    ->run();
```

## Tools

```php
use AiSdk\Generate;
use AiSdk\OpenAI;
use AiSdk\Schema;
use AiSdk\Tool;

$weather = Tool::make('weather', 'Get current weather')
    ->input(Schema::string(name: 'city')->required())
    ->run(fn (string $city): string => "Sunny in {$city}");

$result = Generate::text()
    ->model(OpenAI::model('gpt-4o'))
    ->prompt('What is the weather in Lahore?')
    ->tool($weather)
    ->run();
```

## Model IDs and Capabilities

OpenAI model IDs pass through unchanged and do not need to be registered. The package does not ship a model inventory; the OpenAI API remains the authority on whether a particular model accepts a requested feature.

Capabilities describe what the OpenAI adapter can serialize. The OpenAI API returns a normalized SDK exception if the selected model or requested feature is rejected.

## Provider-Specific Options

Raw provider options can be passed as an escape hatch:

```php
$result = Generate::text('Hello')
    ->model(OpenAI::model('gpt-4o'))
    ->providerOptions('openai', [
        'raw' => ['seed' => 42, 'service_tier' => 'default'],
    ])
    ->run();
```

## Testing

```bash
composer test
```

## Links

- [Core Package](https://github.com/phpaisdk/core)
- [OpenAI Embeddings Guide](https://developers.openai.com/api/docs/guides/embeddings)
- [Project Documentation](https://github.com/phpaisdk)
