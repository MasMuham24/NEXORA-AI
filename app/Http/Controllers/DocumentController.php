<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Services\DocumentExtractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DocumentController extends Controller
{
    public function index(Request $request, KnowledgeBase $knowledgeBase)
    {
        abort_unless(
            $knowledgeBase->user_id === $request->user()->id,
            404
        );

        $documents = $knowledgeBase->documents()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($documents);
    }

    public function store(Request $request, KnowledgeBase $knowledgeBase, DocumentExtractionService $extractionService)
    {
        abort_unless($knowledgeBase->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:txt,docx,pdf'],
        ]);

        $file = $validated['file'];
        $storedPath = $file->store("documents/{$knowledgeBase->id}", 'local');

        $document = $knowledgeBase->documents()->create([
            'title' => $validated['title'],
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'file_path' => Storage::disk('local')->path($storedPath),
            'extraction_status' => 'pending',
        ]);

        try {
            $content = $extractionService->extract($document);
            $document->update([
                'content' => $content,
                'extraction_status' => 'completed',
                'extraction_error' => null,
            ]);
        } catch (RuntimeException $exception) {
            $document->update([
                'extraction_status' => 'failed',
                'extraction_error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Document created.',
            'document' => $document->fresh(),
        ], 201);
    }

    public function show(
        Request $request,
        KnowledgeBase $knowledgeBase,
        Document $document
    ) {
        abort_unless(
            $knowledgeBase->user_id === $request->user()->id &&
            $document->knowledge_base_id === $knowledgeBase->id,
            404
        );

        return response()->json([
            'document' => $document,
        ]);
    }

    public function update(
        Request $request,
        KnowledgeBase $knowledgeBase,
        Document $document
    ) {
        abort_unless(
            $knowledgeBase->user_id === $request->user()->id &&
            $document->knowledge_base_id === $knowledgeBase->id,
            404
        );

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
        ]);

        $document->update($validated);

        return response()->json([
            'message' => 'Document updated.',
            'document' => $document->fresh(),
        ]);
    }

    public function destroy(
        Request $request,
        KnowledgeBase $knowledgeBase,
        Document $document
    ) {
        abort_unless(
            $knowledgeBase->user_id === $request->user()->id &&
            $document->knowledge_base_id === $knowledgeBase->id,
            404
        );

        $document->delete();

        return response()->json([
            'message' => 'Document deleted.',
        ]);
    }
}
