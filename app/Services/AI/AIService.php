<?php

namespace App\Services\AI;

use App\Contracts\AIProviderInterface;
use RuntimeException;

class AIService
{
    public function __construct(
        protected AIProviderInterface $provider
    ) {}

    public function chat(
        array $messages,
        array $options = []
    ): array {
        return $this->provider->chat(
            $messages,
            $options
        );
    }

    public function streamChat(
        array $messages,
        array $options = []
    ): \Generator {
        return $this->provider->streamChat(
            $messages,
            $options
        );
    }

    public function extractContent(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('AI response contains no valid content.');
        }

        return $content;
    }
}
