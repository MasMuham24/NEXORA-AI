<?php

namespace App\Services\AI;

use App\Models\Conversation;

class ContextBuilder
{
    public function build(Conversation $conversation, ?int $beforeMessageId = null
    ): array {
        $query = $conversation->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($beforeMessageId !== null) {
            $query->where('id', '<=', $beforeMessageId);
        }

        return $query
            ->limit(config('ai.context_messages', 50))
            ->get()
            ->reverse()
            ->map(fn ($message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();
    }
}
