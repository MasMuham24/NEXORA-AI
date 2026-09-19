<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $conversations = $request->user()
            ->conversations()
            ->withCount('messages')
            ->latest('updated_at')
            ->paginate(20);

        return response()->json($conversations);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $conversation = $request->user()
            ->conversations()
            ->create($validated);

        return response()->json([
            'message' => 'Conversation created.',
            'conversation' => $conversation,
        ], 201);
    }

    public function show(Request $request, Conversation $conversation)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $conversation->load(['messages' => fn ($query) => $query->orderBy('created_at')->orderBy('id')]);

        return response()->json([
            'conversation' => $conversation,
        ]);
    }

    public function update(Request $request, Conversation $conversation)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $conversation->update($validated);

        return response()->json([
            'message' => 'Conversation updated.',
            'conversation' => $conversation->fresh(),
        ]);
    }

    public function destroy(Request $request, Conversation $conversation)
    {
        abort_unless(
            $conversation->user_id === $request->user()->id,
            404
        );

        $conversation->delete();

        return response()->json([
            'message' => 'Conversation deleted.',
        ]);
    }
}
