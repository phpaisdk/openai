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

## Custom Model Registration

Register new OpenAI models without waiting for a package release:

```php
use AiSdk\Capability;
use AiSdk\OpenAI;

OpenAI::registerModel('gpt-4.2', capabilities: [
    Capability::TextGeneration,
    Capability::Streaming,
    Capability::ToolCalling,
    Capability::StructuredOutput,
    Capability::TextInput,
    Capability::ImageInput,
]);

$result = Generate::text('Hello')
    ->model(OpenAI::model('gpt-4.2'))
    ->run();
```

Unknown unregistered models are allowed for text generation. For unlisted advanced capabilities, opt in on the model handle:

```php
$model = OpenAI::model('gpt-4.2-preview')->assume([
    Capability::ToolCalling,
    Capability::StructuredOutput,
]);
```

The OpenAI API will return a normalized SDK exception if the model or requested feature is rejected by the provider.

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
- [Project Documentation](https://github.com/phpaisdk)
