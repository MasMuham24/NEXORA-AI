<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AIService;
use Illuminate\Http\Request;
use Throwable;

class MessageController extends Controller
{
    public function __construct(
        protected AIService $aiService
    ) {}

    public function index(Request $request, Conversation $conversation)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        return response()->json($conversation->messages()->latest('created_at')->paginate(50));
    }

    public function store(Request $request, Conversation $conversation)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $validated = $request->validate([
            'role' => ['required', 'in:user,assistant,system'],
            'content' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $message = $conversation->messages()->create($validated);
        $conversation->touch();
        return response()->json([
            'message' => 'Message created.',
            'data' => $message,
        ], 201);
    }

    public function destroy(Request $request, Conversation $conversation, $message)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $message = $conversation->messages()->findOrFail($message);
        $message->delete();
        return response()->json([
            'message' => 'Message deleted.',
        ]);
    }

    public function update(Request $request, Conversation $conversation, $message)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $userMessage = $conversation->messages()->findOrFail($message);

        if ($userMessage->role !== 'user') {
            return response()->json([
                'message' => 'Only user messages can be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'content' => ['required', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
        ]);

        $providerName = config('ai.provider', 'pateway');
        $model = $validated['model'] ?? config("ai.providers.{$providerName}.model");

        if (empty($model)) {
            return response()->json([
                'message' => 'AI model is not configured. Set PATEWAY_MODEL or provide a model.',
            ], 422);
        }

        $userMessage->update([
            'content' => $validated['content'],
        ]);

        $conversation->messages()
            ->where('id', '>', $userMessage->id)
            ->delete();

        $history = $conversation->messages()
            ->where('id', '<=', $userMessage->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();

        try {
            $response = $this->aiService->chat($history, [
                'model' => $model,
            ]);
            $content = $this->aiService->extractContent($response);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'AI request failed.',
            ], 502);
        }

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $content,
            'metadata' => [
                'provider' => $providerName,
                'model' => $model,
                'edited' => true,
                'edited_message' => $userMessage->id,
            ],
        ]);

        $conversation->touch();

        return response()->json([
            'message' => 'Message edited and AI response regenerated.',
            'data' => [
                'user_message' => $userMessage->fresh(),
                'assistant_message' => $assistantMessage,
            ],
        ]);
    }

    public function chat(Request $request, Conversation $conversation)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $validated = $request->validate([
            'content' => ['required', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
            'stream' => ['nullable', 'boolean'],
        ]);

        $providerName = config('ai.provider', 'pateway');
        $defaultModel = config("ai.providers.{$providerName}.model");
        $model = $validated['model'] ?? $defaultModel;

        if (empty($model)) {
            return response()->json([
                'message' => 'AI model is not configured. Set PATEWAY_MODEL or provide a model.',
            ], 422);
        }

        $metadata = [
            'provider' => $providerName,
            'model' => $model,
        ];

        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->push([
                'role' => 'user',
                'content' => $validated['content'],
            ])
            ->values()
            ->all();

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $validated['content'],
            'metadata' => $metadata,
        ]);

        $conversation->touch();

        if (! ($validated['stream'] ?? false)) {
            return $this->sendNonStreamingChat(
                $conversation,
                $history,
                $model,
                $providerName,
                $userMessage
            );
        }

        return $this->sendStreamingChat(
            $conversation,
            $history,
            $model,
            $providerName,
            $userMessage
        );
    }

    protected function sendNonStreamingChat(
        Conversation $conversation,
        array $history,
        string $model,
        string $providerName,
        Message $userMessage
    ) {
        try {
            $response = $this->aiService->chat($history, [
                'model' => $model,
            ]);
            $content = $this->aiService->extractContent($response);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'AI request failed.',
                'data' => [
                    'conversation' => $conversation->fresh(),
                    'user_message' => $userMessage,
                ],
            ], 502);
        }

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $content,
            'metadata' => [
                'provider' => $providerName,
                'model' => $model,
            ],
        ]);

        $conversation->touch();

        return response()->json([
            'message' => 'AI response generated.',
            'data' => [
                'conversation' => $conversation->fresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
            ],
        ]);
    }

    protected function sendStreamingChat(
        Conversation $conversation,
        array $history,
        string $model,
        string $providerName,
        Message $userMessage
    ) {
        return response()->stream(function () use (
            $conversation,
            $history,
            $model,
            $providerName
        ) {
            $full = '';

            try {
                foreach ($this->aiService->streamChat($history, [
                    'model' => $model,
                ]) as $chunk) {
                    $full .= $chunk;
                    echo 'data: ' . json_encode(['content' => $chunk]) . "\n\n";
                    flush();
                }

                echo "data: [DONE]\n\n";
                flush();
            } catch (Throwable $e) {
                report($e);
                echo 'data: ' . json_encode(['error' => 'AI request failed.']) . "\n\n";
                flush();
                return;
            }

            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $full,
                'metadata' => [
                    'provider' => $providerName,
                    'model' => $model,
                    'stream' => true,
                ],
            ]);

            $conversation->touch();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function regenerate(Request $request, Conversation $conversation, $message)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $assistantMessage = $conversation->messages()->findOrFail($message);

        if ($assistantMessage->role !== 'assistant') {
            return response()->json([
                'message' => 'Only assistant messages can be regenerated.',
            ], 422);
        }

        $userMessage = $conversation->messages()
            ->where('role', 'user')
            ->where('id', '<', $assistantMessage->id)
            ->orderByDesc('id')
            ->first();

        if (! $userMessage) {
            return response()->json([
                'message' => 'No user message found for this assistant message.',
            ], 422);
        }

        $providerName = config('ai.provider', 'pateway');
        $previousMetadata = $assistantMessage->metadata ?? [];
        $model = $previousMetadata['model'] ?? config("ai.providers.{$providerName}.model");

        if (empty($model)) {
            return response()->json([
                'message' => 'AI model is not configured. Set PATEWAY_MODEL or provide a model.',
            ], 422);
        }

        $history = $conversation->messages()
            ->where('id', '<=', $userMessage->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();

        try {
            $response = $this->aiService->chat($history, [
                'model' => $model,
            ]);
            $content = $this->aiService->extractContent($response);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'AI request failed.',
            ], 502);
        }

        $newAssistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $content,
            'metadata' => [
                'provider' => $providerName,
                'model' => $model,
                'regenerated' => true,
                'regenerated_from' => $assistantMessage->id,
            ],
        ]);

        $conversation->touch();

        return response()->json([
            'message' => 'AI response regenerated.',
            'data' => [
                'previous_message' => $assistantMessage,
                'assistant_message' => $newAssistantMessage,
            ],
        ]);
    }
}
