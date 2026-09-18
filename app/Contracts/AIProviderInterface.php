<?php

namespace App\Contracts;

interface AIProviderInterface
{
    public function chat(
        array $messages,
        array $options = []
    ): array;

    public function streamChat(
        array $messages,
        array $options = []
    ): \Generator;
}
