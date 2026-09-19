<?php

namespace App\Services\AI\Providers;

use App\Contracts\AIProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PatewayProvider implements AIProviderInterface
{
    protected ?string $apiKey;
    protected ?string $model;
    protected string $baseUrl;
    protected int $maxTokens;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?string $baseUrl = null,
        ?int $maxTokens = null
    ) {
        $this->apiKey = $apiKey ?? config('ai.providers.pateway.api_key');
        $this->model = $model ?? config('ai.providers.pateway.model', 'default');
        $this->baseUrl = rtrim($baseUrl ?? config('ai.providers.pateway.base_url', 'https://api.pateway.ai/v1'), '/');
        $this->maxTokens = $maxTokens ?? config('ai.providers.pateway.max_tokens', 2048);
    }

    public function chat(array $messages, array $options = []): array
    {
        $this->guardConfig();

        $model = $options['model'] ?? $this->model;
        $payload = $this->buildPayload($messages, $model, $options, false);

        try {
            $response = $this->request('POST', $this->endpointFor($model), $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Unable to connect to Pateway API.', 0, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Pateway API request failed with status ' . $response->status() . '.'
            );
        }

        $data = $response->json();

        if ($this->protocolFor($model) === 'anthropic') {
            $content = $data['content'][0]['text'] ?? null;

            if (! is_string($content) || trim($content) === '') {
                throw new RuntimeException('Pateway API returned an invalid or empty response.');
            }

            return [
                'id' => $data['id'] ?? null,
                'model' => $data['model'] ?? $model,
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content]],
                ],
                'usage' => $data['usage'] ?? null,
            ];
        }

        $content = $data['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Pateway API returned an invalid or empty response.');
        }

        return $data;
    }

    public function streamChat(array $messages, array $options = []): \Generator
    {
        $this->guardConfig();

        $model = $options['model'] ?? $this->model;
        $protocol = $this->protocolFor($model);

        try {
            $response = $this->requestRaw('POST', $this->endpointFor($model), $this->buildPayload($messages, $model, $options, true));
        } catch (ConnectionException $e) {
            throw new RuntimeException('Unable to connect to Pateway API.', 0, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Pateway API request failed with status ' . $response->status() . '.'
            );
        }

        $body = $response->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));

                if ($data === '[DONE]') {
                    return;
                }

                $chunk = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
                $content = null;

                if ($protocol === 'anthropic') {
                    if (($chunk['type'] ?? '') === 'content_block_delta' && ($chunk['delta']['type'] ?? '') === 'text_delta') {
                        $content = $chunk['delta']['text'] ?? null;
                    }
                } else {
                    $content = $chunk['choices'][0]['delta']['content'] ?? null;
                }

                if (is_string($content) && $content !== '') {
                    yield $content;
                }
            }
        }
    }

    protected function guardConfig(): void
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Pateway API key is not configured. Set PATEWAY_API_KEY.');
        }

        if (empty($this->model)) {
            throw new RuntimeException('Pateway model is not configured. Set PATEWAY_MODEL.');
        }
    }

    protected function protocolFor(string $model): string
    {
        return str_starts_with(strtolower($model), 'claude') ? 'anthropic' : 'openai';
    }

    protected function endpointFor(string $model): string
    {
        return $this->protocolFor($model) === 'anthropic'
            ? $this->baseUrl . '/anthropic/v1/messages'
            : $this->baseUrl . '/chat/completions';
    }

    protected function buildPayload(array $messages, string $model, array $options, bool $stream): array
    {
        $base = [
            'model' => $model,
            'messages' => $messages,
            'stream' => $stream,
        ];

        if ($this->protocolFor($model) === 'anthropic' || ! str_starts_with(strtolower($model), 'gpt')) {
            $base['max_tokens'] = $this->maxTokens;
        }

        return array_merge($base, $options);
    }

    protected function request(string $method, string $url, array $payload): Response
    {
        return Http::withToken($this->apiKey)
            ->timeout((int) config('ai.timeout', 60))
            ->acceptJson()
            ->send($method, $url, ['json' => $payload]);
    }

    protected function requestRaw(string $method, string $url, array $payload): Response
    {
        return Http::withToken($this->apiKey)
            ->timeout((int) config('ai.stream_timeout', 120))
            ->acceptJson()
            ->withOptions(['stream' => true])
            ->send($method, $url, ['json' => $payload]);
    }
}