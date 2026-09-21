<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingAs($user)
            ->postJson("/knowledge-bases/{$knowledgeBase->id}/documents", [
                'title' => 'Laravel Documentation',
                'original_filename' => 'laravel.txt',
                'mime_type' => 'text/plain',
                'file_size' => 1024,
                'content' => 'Laravel documentation content.',
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

        $this->actingAs($user)
            ->postJson(
                "/knowledge-bases/{$knowledgeBase->id}/documents",
                [
                    'original_filename' => 'document.txt',
                    'mime_type' => 'text/plain',
                    'file_size' => 100,
                    'content' => 'Content.',
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
}
