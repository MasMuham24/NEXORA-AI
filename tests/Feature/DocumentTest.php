<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_documents(): void
    {
        $user = User::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        Document::factory()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'title' => 'Laravel Documentation',
        ]);

        $this->actingAs($user)
            ->getJson("/knowledge-bases/{$knowledgeBase->id}/documents")
            ->assertOk()
            ->assertJsonFragment([
                'title' => 'Laravel Documentation',
            ]);
    }

    public function test_user_can_create_document(): void
    {
        $user = User::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'laravel.txt',
            'Laravel documentation content.'
        );

        $this->actingAs($user)
            ->postJson("/knowledge-bases/{$knowledgeBase->id}/documents", [
                'title' => 'Laravel Documentation',
                'file' => $file,
            ])
            ->assertCreated()
            ->assertJsonPath(
                'document.title',
                'Laravel Documentation'
            );

        $this->assertDatabaseHas('documents', [
            'knowledge_base_id' => $knowledgeBase->id,
            'title' => 'Laravel Documentation',
        ]);
    }

    public function test_user_can_show_document(): void
    {
        $user = User::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $document = Document::factory()->create([
            'knowledge_base_id' => $knowledgeBase->id,
        ]);

        $this->actingAs($user)
            ->getJson(
                "/knowledge-bases/{$knowledgeBase->id}/documents/{$document->id}"
            )
            ->assertOk()
            ->assertJsonPath('document.id', $document->id);
    }

    public function test_user_can_update_document(): void
    {
        $user = User::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $document = Document::factory()->create([
            'knowledge_base_id' => $knowledgeBase->id,
        ]);

        $this->actingAs($user)
            ->putJson(
                "/knowledge-bases/{$knowledgeBase->id}/documents/{$document->id}",
                [
                    'title' => 'Updated Document',
                    'content' => 'Updated content.',
                ]
            )
            ->assertOk()
            ->assertJsonPath(
                'document.title',
                'Updated Document'
            );

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'title' => 'Updated Document',
            'content' => 'Updated content.',
        ]);
    }

    public function test_user_can_delete_document(): void
    {
        $user = User::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $document = Document::factory()->create([
            'knowledge_base_id' => $knowledgeBase->id,
        ]);

        $this->actingAs($user)
            ->deleteJson(
                "/knowledge-bases/{$knowledgeBase->id}/documents/{$document->id}"
            )
            ->assertOk();

        $this->assertDatabaseMissing('documents', [
            'id' => $document->id,
        ]);
    }

    public function test_user_cannot_access_another_users_documents(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $owner->id,
        ]);

        $document = Document::factory()->create([
            'knowledge_base_id' => $knowledgeBase->id,
        ]);

        $this->actingAs($otherUser)
            ->getJson(
                "/knowledge-bases/{$knowledgeBase->id}/documents/{$document->id}"
            )
            ->assertNotFound();

        $this->actingAs($otherUser)
            ->putJson(
                "/knowledge-bases/{$knowledgeBase->id}/documents/{$document->id}",
                [
                    'title' => 'Hacked',
                    'content' => 'Should not work.',
                ]
            )
            ->assertNotFound();

        $this->actingAs($otherUser)
            ->deleteJson(
                "/knowledge-bases/{$knowledgeBase->id}/documents/{$document->id}"
            )
            ->assertNotFound();
    }

    public function test_document_cannot_be_accessed_through_another_knowledge_base(): void
    {
        $user = User::factory()->create();

        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $anotherKnowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $document = Document::factory()->create([
            'knowledge_base_id' => $knowledgeBase->id,
        ]);

        $this->actingAs($user)
            ->getJson(
                "/knowledge-bases/{$anotherKnowledgeBase->id}/documents/{$document->id}"
            )
            ->assertNotFound();
    }

    public function test_document_title_is_required(): void
    {
        $user = User::factory()->create();

        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'document.txt',
            'Document content.'
        );

        $this->actingAs($user)
            ->postJson(
                "/knowledge-bases/{$knowledgeBase->id}/documents",
                [
                    'file' => $file,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    }

    public function test_deleting_knowledge_base_deletes_its_documents(): void
    {
        $user = User::factory()->create();

        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $document = Document::factory()->create([
            'knowledge_base_id' => $knowledgeBase->id,
        ]);

        $knowledgeBase->delete();

        $this->assertDatabaseMissing('documents', [
            'id' => $document->id,
        ]);
    }

    public function test_txt_upload_extracts_content_and_sets_status_completed(): void
    {
        $user = User::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $content = 'This is test content for extraction.';
        $file = UploadedFile::fake()->createWithContent('test.txt', $content);

        $response = $this->actingAs($user)
            ->postJson("/knowledge-bases/{$knowledgeBase->id}/documents", [
                'title' => 'Test Document',
                'file' => $file,
            ]);

        $response->assertCreated()
            ->assertJsonPath('document.extraction_status', 'completed')
            ->assertJsonPath('document.content', $content);

        $this->assertDatabaseHas('documents', [
            'knowledge_base_id' => $knowledgeBase->id,
            'title' => 'Test Document',
            'extraction_status' => 'completed',
        ]);
    }

    public function test_unsupported_file_extension_is_rejected(): void
    {
        $user = User::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'malware.exe',
            'binary content'
        )->mimeType('application/octet-stream');

        $this->actingAs($user)
            ->postJson("/knowledge-bases/{$knowledgeBase->id}/documents", [
                'title' => 'Malware',
                'file' => $file,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_file_metadata_comes_from_uploaded_file(): void
    {
        $user = User::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $file = UploadedFile::fake()
            ->createWithContent('real-name.txt', 'content')
            ->size(1024);

        $this->actingAs($user)
            ->postJson("/knowledge-bases/{$knowledgeBase->id}/documents", [
                'title' => 'Metadata Test',
                'file' => $file,
            ])
            ->assertCreated();

        $document = Document::where('title', 'Metadata Test')->first();

        $this->assertEquals('real-name.txt', $document->original_filename);
        $this->assertEquals('text/plain', $document->mime_type);
        $this->assertNotNull($document->file_path);
    }

    public function test_failed_extraction_sets_status_failed_and_error(): void
    {
        $user = User::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'empty.txt',
            ''
        )->mimeType('text/plain');

        $this->actingAs($user)
            ->postJson("/knowledge-bases/{$knowledgeBase->id}/documents", [
                'title' => 'Empty File',
                'file' => $file,
            ])
            ->assertCreated();

        $document = Document::where('title', 'Empty File')->first();
        $this->assertEquals('failed', $document->extraction_status);
        $this->assertNotNull($document->extraction_error);
    }

    public function test_user_cannot_upload_to_another_users_knowledge_base(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $owner->id,
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'test.txt',
            'content'
        );

        $this->actingAs($otherUser)
            ->postJson("/knowledge-bases/{$knowledgeBase->id}/documents", [
                'title' => 'Unauthorized Upload',
                'file' => $file,
            ])
            ->assertNotFound();
    }
}
