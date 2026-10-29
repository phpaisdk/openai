# aisdk/openai: guide for coding agents

Official OpenAI provider for the PHP AI SDK. Part of the framework-agnostic [PHP AI SDK](https://phpaisdk.com); build on `aisdk/core`, keep provider wire details in this package.

## Using it

```php
use AiSdk\Generate;
use AiSdk\OpenAI;

OpenAI::create(['apiKey' => '...']); // optional: environment variables below are read automatically

$result = Generate::text('Hello')
    ->model(OpenAI::model('model-id'))
    ->run();
```

- Entry point: `AiSdk\OpenAI` (`create()`, `default()`, `reset()`, `model()`), source in `src/Root/OpenAI.php`.
- Namespace: `AiSdk\OpenAI\` in `src/`.
- Provider key for call options: `->providerOptions('openai', [...])`.
- Common environment variables (see README.md for the full list): `OPENAI_API_KEY`, `OPENAI_BASE_URL`.

## Supported capabilities

| Capability | Support |
|---|---|
| Text generation | Native |
| Streaming | Native |
| Tool calling | Native |
| Structured output | Native (`json_schema`) |
| Reasoning | Native (`reasoning_effort`) |
| Image generation | Native |
| Speech generation | Native |
| Transcription | Native |
| Embeddings | Native |
| Live voice | WebSocket, WebRTC, and SIP control |
| Live transcription | WebSocket and WebRTC |
| Live translation | WebSocket and WebRTC |
| Text input | Native |
| Image input | Native |
| Audio input | Native |
| File input | Native |

## Rules for changes

1. **Model ids are opaque strings.** Use `OpenAI::model('id')`. Never validate an id against a list, never ship a model catalog, and never add capability-specific public factories such as `textModel()` or `imageModel()`. The operation (`Generate::text()`, `Generate::image()`, ...) resolves the right adapter when it runs.
2. **Provider wire behavior lives here, portable behavior lives in `aisdk/core`.** Do not leak this provider's request/response shapes into core, and do not put portable logic here.
3. **The request/response mapping is shared** through `aisdk/openai-compatible`. If a change would apply to other OpenAI-compatible providers, make it there; keep only this provider's quirks (endpoint, headers, parameter names via a request profile) in this package.
4. **Do not invent generation defaults.** Send `maxTokens`, `temperature` and `topP` only when the `TextModelRequest` carries a value. Apply a provider default only where the API requires the field.
5. **Credentials come from config or environment**, never from code. Do not log request headers or bodies that contain keys.
6. **Unsupported features fail fast** with a typed exception from `AiSdk\Exceptions` before any HTTP call, rather than sending a request the provider will reject.

## Layout

| Path | Purpose |
|---|---|
| `src/Root/OpenAI.php` | Public facade |
| `src/OpenAIProvider.php` | Provider: resolves a model id to a capability adapter |
| `src/OpenAIOptions.php` | Configuration (config array and environment) |
| `src/Models/` | `OpenAIEmbeddingModel`, `OpenAIImageModel`, `OpenAILiveModel`, `OpenAISpeechModel`, `OpenAITextModel`, `OpenAITranscriptionModel` |
| `src/Live/` | Live (real-time) sessions |
| `src/Support/` | Request builders, parsers and helpers |
| `src/Webhooks/` | Request builders, parsers and helpers |
| `tests/` | Pest tests |

## Commands

```bash
composer lint      # pint --test
composer analyze   # phpstan level 8
composer test      # pest
composer test:all  # all of the above
```

## Testing

- Tests never touch the network. Use `tests/Fakes/FakeHttpClient.php` with a PSR-17 factory and `Generate::configure(new Sdk(...))`; see an existing test for the pattern.
- Cover request shaping (assert the JSON body), response parsing, streaming, and error mapping for every capability you change.
- Do not edit `vendor/`. This package is installed against a released `aisdk/core`; if a test needs unreleased core behavior, run it against the local core checkout rather than loosening assertions.

## Docs

- Package docs: https://phpaisdk.com/docs/openai.md
- All documentation for agents: https://phpaisdk.com/llms.txt (full text: https://phpaisdk.com/llms-full.txt)
- Core concepts (providers, models, tools, streaming): https://phpaisdk.com/docs/introduction.md

## Changelog

Keep `CHANGELOG.md` current: add short, user-facing entries (what users can do now, dependency compatibility) for every release. Release notes on GitHub should match.
