# PHP AI SDK — OpenAI

Text, image, speech, transcription, and embedding generation, plus real-time voice with OpenAI. Part of the framework-agnostic [PHP AI SDK](https://phpaisdk.com).

## Installation

```bash
composer require aisdk/openai
```

Set `OPENAI_API_KEY` in your environment.

## Usage

```php
use AiSdk\Generate;
use AiSdk\OpenAI;

$result = Generate::text('Explain closures in PHP in one sentence.')
    ->model(OpenAI::model('gpt-6.1-sol'))
    ->run();

echo $result->text;
```

For streaming, call `->stream()` instead of `->run()`. Install `aisdk/transport` for server-side live sessions.

## Documentation

[Read the documentation](https://phpaisdk.com/docs/0.x/providers/openai) for configuration, supported features, and more examples.

[Contributing](https://github.com/phpaisdk/.github/blob/main/CONTRIBUTING.md) · [Support](https://github.com/phpaisdk/.github/blob/main/SUPPORT.md)

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
