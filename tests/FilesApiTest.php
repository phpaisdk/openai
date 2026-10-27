<?php

declare(strict_types=1);

use AiSdk\Files\FileUpload;
use AiSdk\OpenAI;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

it('uploads lists retrieves downloads and deletes provider files with the documented protocol', function () {
    $http = new class implements ClientInterface {
        public array $requests = [];
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->requests[] = $request;
            $path = $request->getUri()->getPath();
            $file = ['id' => 'file-a', 'filename' => 'a.txt', 'bytes' => 3, 'mime_type' => 'text/plain', 'downloadable' => true];
            if (str_ends_with($path, '/content')) {
                return new Response(200, [], 'abc');
            }
            if ($request->getMethod() === 'DELETE') {
                return new Response(204);
            }
            $result = $request->getMethod() === 'GET' && $path === '/v1/files'
                ? ['data' => [$file], 'has_more' => true, 'last_id' => 'next'] : $file;

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($result));
        }
    };
    $factory = new Psr17Factory();
    $provider = OpenAI::create(['apiKey' => 'test', 'sdk' => new Sdk($http, $factory, $factory)]);
    $files = $provider->files();
    $file = $files->upload(new FileUpload('abc', 'a.txt', 'text/plain'), []);
    expect($file->reference->provider)->toBe('openai')->and($file->bytes)->toBe(3);
    $body = (string) $http->requests[0]->getBody();
    expect($body)->toContain('filename="a.txt"', 'abc')
        ->and($http->requests[0]->getHeaderLine('Authorization'))->toBe('Bearer test');
    expect($body)->toContain('user_data');
    $page = $files->list('cursor/+?', 2);
    expect($page->nextCursor)->toBe('next')->and($http->requests[1]->getUri()->getQuery())->toContain('after=cursor%2F%2B%3F');
    expect($files->retrieve('file-a')->filename)->toBe('a.txt');
    expect((string) $files->download('file-a'))->toBe('abc');
    $files->delete('file-a');
    expect($http->requests[4]->getMethod())->toBe('DELETE');
    OpenAI::reset();
});
