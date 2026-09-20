<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeBase;
use Illuminate\Http\Request;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request)
    {
        $knowledgeBases = $request->user()
            ->knowledgeBases()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($knowledgeBases);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $knowledgeBase = $request->user()
            ->knowledgeBases()
            ->create($validated);

        return response()->json([
            'message' => 'Knowledge base created.',
            'knowledge_base' => $knowledgeBase,
        ], 201);
    }

    public function show(Request $request, KnowledgeBase $knowledgeBase)
    {
        abort_unless(
            $knowledgeBase->user_id === $request->user()->id,
            404
        );

        return response()->json([
            'knowledge_base' => $knowledgeBase,
        ]);
    }

    public function update(Request $request, KnowledgeBase $knowledgeBase)
    {
        abort_unless(
            $knowledgeBase->user_id === $request->user()->id,
            404
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $knowledgeBase->update($validated);

        return response()->json([
            'message' => 'Knowledge base updated.',
            'knowledge_base' => $knowledgeBase->fresh(),
        ]);
    }

    public function destroy(Request $request, KnowledgeBase $knowledgeBase)
    {
        abort_unless(
            $knowledgeBase->user_id === $request->user()->id,
            404
        );

        $knowledgeBase->delete();

        return response()->json([
            'message' => 'Knowledge base deleted.',
        ]);
    }
}
