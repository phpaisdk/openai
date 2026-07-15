# aisdk/openai

Official OpenAI provider for the PHP AI SDK.

## Installation

```bash
composer require aisdk/openai
```

Live sessions need a transport. Install the ready-made implementation, or use
any application transport that implements the two contracts from `aisdk/core`:

```bash
composer require aisdk/transport
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
| Transcription | Native |
| Embeddings | Native |
| Live voice | WebSocket, WebRTC, and SIP control |
| Live transcription | WebSocket and WebRTC |
| Live translation | WebSocket and WebRTC |
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
    ->model(OpenAI::model('text-embedding-3-small'))
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
    ->model(OpenAI::model('gpt-image-1'))
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
    ->model(OpenAI::model('gpt-4o-mini-tts'))
    ->input('Today is a wonderful day to build something people love.')
    ->voice('coral')
    ->format('mp3')
    ->providerOptions('openai', [
        'instructions' => 'Speak in a cheerful and positive tone.',
    ])
    ->run();

$result->output->save(__DIR__.'/speech.mp3');
```

## Transcription

```php
use AiSdk\Content;
use AiSdk\Generate;
use AiSdk\OpenAI;

$result = Generate::transcription(Content::audio(__DIR__.'/meeting.mp3'))
    ->model(OpenAI::model('gpt-4o-transcribe'))
    ->providerOptions('openai', ['language' => 'en'])
    ->run();

echo $result->output->text;
```

## Live Voice Agents

`AiSdk\Live` is part of core. `aisdk/openai` supplies OpenAI authentication,
session configuration, event encoding, normalized events, client credentials,
WebRTC signalling, and SIP lifecycle operations. The optional
`aisdk/transport` package only supplies the network connection.

With the ready-made WebSocket transport:

```php
use AiSdk\Live;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\ResponseCompleted;
use AiSdk\OpenAI;
use AiSdk\Transport;
use function Amp\async;

$session = Live::voice()
    ->model(OpenAI::model('gpt-realtime-2.1'))
    ->instructions('Be concise and helpful.')
    ->voice('marin')
    ->turnDetection('disabled')
    ->inputAudioFormat('pcm16')
    ->outputAudioFormat('pcm16')
    ->connect(Transport::auto());

// Read and write concurrently. This example treats STDIN and STDOUT as raw
// 24 kHz mono PCM16, which makes it easy to connect an application audio I/O.
$sender = async(function () use ($session): void {
    while (! feof(STDIN)) {
        $bytes = fread(STDIN, 4096);
        if ($bytes !== false && $bytes !== '') {
            $session->sendAudio($bytes);
        }
    }

    $session->commitAudio();
    $session->requestResponse();
});

foreach ($session->events() as $event) {
    if ($event instanceof AudioDelta) {
        fwrite(STDOUT, $event->bytes);
    }

    if ($event instanceof ResponseCompleted) {
        break;
    }
}

$sender->await();
$session->close();
```

Without `aisdk/transport`, pass your own implementation directly. Core does
not require Amp or any particular event loop:

```php
use AiSdk\Live;
use AiSdk\OpenAI;
use App\Ai\AppWebSocketTransport;

$session = Live::voice()
    ->model(OpenAI::model('gpt-realtime-2.1'))
    ->voice('marin')
    ->connect(new AppWebSocketTransport());
```

The core repository includes a complete
[`AppWebSocketTransport`](https://github.com/phpaisdk/core/blob/main/examples/AppWebSocketTransport.php)
implementation using `amphp/websocket-client`. A custom transport moves text
and binary frames only; OpenAI protocol handling remains in this package.

### Live tools

Registered tools with handlers run automatically. Tool calls without a local
handler remain available for manual authorization or execution:

```php
use AiSdk\Live\ToolCallEvent;
use AiSdk\Schema;
use AiSdk\Tool;

$weather = Tool::make('weather', 'Get current weather')
    ->input(Schema::string(name: 'city')->required())
    ->run(fn (string $city): array => ['forecast' => "Sunny in {$city}"]);

$session = Live::voice()
    ->model(OpenAI::model('gpt-realtime-2.1'))
    ->tool($weather)
    ->connect(Transport::auto());

foreach ($session->events() as $event) {
    if ($event instanceof ToolCallEvent && $event->name === 'approval') {
        $session->sendToolResult($event->callId, ['approved' => true]);
    }
}
```

Parallel tool outputs are coordinated by the provider adapter. The next model
response is requested once, only after every call in that response has an
output.

## Live Transcription and Translation

Streaming transcription sends audio over the Realtime connection and emits
normalized transcript events:

```php
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptDelta;

$session = Live::transcribe()
    ->model(OpenAI::model('gpt-realtime-whisper'))
    ->language('en')
    ->audioFormat('pcm16')
    ->connect(Transport::auto());

$session->sendAudio($pcmBytes);
$session->commitAudio();

foreach ($session->events() as $event) {
    if ($event instanceof TranscriptDelta) {
        echo $event->delta;
    }
    if ($event instanceof TranscriptCompleted) {
        echo "\nFinal: {$event->text}\n";
    }
}
```

OpenAI's dedicated Live translation protocol is exposed separately:

```php
$session = Live::translate()
    ->model(OpenAI::model('gpt-realtime-translate'))
    ->from('en')
    ->to('es')
    ->inputAudioFormat('pcm16')
    ->outputAudioFormat('pcm16')
    ->connect(Transport::auto());

$session->sendAudio($pcmBytes);
$session->close(); // Sends session.close; keep reading until LiveClosed.

foreach ($session->events() as $event) {
    // AudioDelta and transcript events are normalized by core.
}
```

## WebRTC

For browser media, use the browser's native `RTCPeerConnection`; no PHP
WebRTC stack is required. Your server can issue a scoped ephemeral secret:

```php
$secret = Live::voice()
    ->model(OpenAI::model('gpt-realtime-2.1'))
    ->instructions('Help the signed-in user.')
    ->voice('marin')
    ->clientSecret();

return json_encode([
    'value' => $secret->value,
    'expires_at' => $secret->expiresAt,
]);
```

The browser uses that secret for OpenAI's WebRTC `/v1/realtime/calls` flow.
Here is the complete browser side of that ephemeral-secret flow:

```javascript
const token = await fetch('/openai/realtime-token').then((response) => response.json());
const peer = new RTCPeerConnection();
const events = peer.createDataChannel('oai-events');
const remoteAudio = new Audio();
remoteAudio.autoplay = true;

peer.ontrack = ({ streams }) => {
    remoteAudio.srcObject = streams[0];
};

events.onmessage = ({ data }) => {
    const event = JSON.parse(data);
    console.log(event);
};

const microphone = await navigator.mediaDevices.getUserMedia({ audio: true });
peer.addTrack(microphone.getAudioTracks()[0], microphone);

const offer = await peer.createOffer();
await peer.setLocalDescription(offer);

const response = await fetch('https://api.openai.com/v1/realtime/calls', {
    method: 'POST',
    headers: {
        Authorization: `Bearer ${token.value}`,
        'Content-Type': 'application/sdp',
    },
    body: offer.sdp,
});

if (! response.ok) {
    throw new Error(await response.text());
}

await peer.setRemoteDescription({
    type: 'answer',
    sdp: await response.text(),
});
```

Alternatively, keep signalling on your server and exchange an SDP offer with
the provider adapter:

```php
$answer = Live::voice()
    ->model(OpenAI::model('gpt-realtime-2.1'))
    ->voice('marin')
    ->webRtc($offerSdp);

return json_encode(['sdp' => $answer->sdp, 'call_id' => $answer->callId]);
```

`clientSecret()` and `webRtc()` are also available on
`Live::transcribe()`. Dedicated translation WebRTC is handled through
`Live::translate()->webRtc($offerSdp)`.

## SIP Calls and Server Controls

Verify the exact raw webhook body before using its call ID. Then accept the
call and optionally attach a WebSocket control session. OpenAI continues to
carry the SIP media; the control connection observes events, updates the
session, and handles tools.

```php
$rawBody = file_get_contents('php://input');
$event = OpenAI::verifyWebhook(
    $rawBody,
    getallheaders(),
    $_ENV['OPENAI_WEBHOOK_SECRET'],
);

if (($event['type'] ?? null) === 'realtime.call.incoming') {
    $call = Live::voice()
        ->model(OpenAI::model('gpt-realtime-2.1'))
        ->instructions('You are the phone support agent.')
        ->voice('marin')
        ->call($event['data']['call_id']);

    $call->accept();
    $control = $call->connect(Transport::auto());

    foreach ($control->events() as $controlEvent) {
        // Handle normalized events and tools on the server.
    }

    $call->hangup();
}
```

Webhook verification enforces the signed raw bytes and the documented
five-minute timestamp window. Do not decode and re-encode the body before
calling `verifyWebhook()`.

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

The default suite is fixture- and conformance-based. Credentialed Live network
verification is separate and is not run by `composer test`.

## Links

- [Core Package](https://github.com/phpaisdk/core)
- [OpenAI Realtime Guide](https://developers.openai.com/api/docs/guides/realtime)
- [OpenAI Realtime Transcription](https://developers.openai.com/api/docs/guides/realtime-transcription)
- [OpenAI WebRTC](https://developers.openai.com/api/docs/guides/realtime-webrtc)
- [OpenAI Server Controls](https://developers.openai.com/api/docs/guides/realtime-server-controls)
- [OpenAI Speech-to-Text Guide](https://developers.openai.com/api/docs/guides/speech-to-text)
- [OpenAI Embeddings Guide](https://developers.openai.com/api/docs/guides/embeddings)
- [Project Documentation](https://github.com/phpaisdk)
