<?php

namespace App\Services\AI\Providers;

use App\Contracts\AIProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIProvider implements AIProviderInterface
{
    protected ?string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?string $baseUrl = null
    ) {
        $this->apiKey = $apiKey ?? config('ai.providers.openai.api_key');
        $this->model = $model ?? config('ai.providers.openai.model', 'gpt-4o-mini');
        $this->baseUrl = rtrim($baseUrl ?? config('ai.providers.openai.base_url', 'https://api.openai.com/v1'), '/');
    }

    public function chat(array $messages, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenAI API key is not configured. Set OPENAI_API_KEY.');
        }

        if (empty($this->model)) {
            throw new RuntimeException('OpenAI model is not configured. Set OPENAI_MODEL.');
        }

        $payload = array_merge([
            'model' => $this->model,
            'messages' => $messages,
        ], $options);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout((int) config('ai.timeout', 60))
                ->post("{$this->baseUrl}/chat/completions", $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Unable to connect to OpenAI API.', 0, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenAI API request failed with status ' . $response->status() . '.'
            );
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('OpenAI API returned an invalid or empty response.');
        }

        return $data;
    }

    public function streamChat(array $messages, array $options = []): \Generator
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenAI API key is not configured. Set OPENAI_API_KEY.');
        }

        if (empty($this->model)) {
            throw new RuntimeException('OpenAI model is not configured. Set OPENAI_MODEL.');
        }

        $payload = array_merge([
            'model' => $this->model,
            'messages' => $messages,
            'stream' => true,
        ], $options);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout((int) config('ai.stream_timeout', 120))
                ->withOptions(['stream' => true])
                ->send('POST', "{$this->baseUrl}/chat/completions", [
                    'json' => $payload,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Unable to connect to OpenAI API.', 0, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenAI API request failed with status ' . $response->status() . '.'
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
                $content = $chunk['choices'][0]['delta']['content'] ?? null;

                if (is_string($content) && $content !== '') {
                    yield $content;
                }
            }
        }
    }
}
