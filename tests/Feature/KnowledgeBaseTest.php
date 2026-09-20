<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_their_knowledge_bases(): void
    {
        $user = User::factory()->create();

        KnowledgeBase::factory()->create([
            'user_id' => $user->id,
            'name' => 'My Knowledge Base',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/knowledge-bases');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'My Knowledge Base',
            ]);
    }

    public function test_user_can_create_knowledge_base(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->postJson('/knowledge-bases', [
                'name' => 'Laravel Docs',
                'description' => 'Laravel documentation.',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('knowledge_base.name', 'Laravel Docs');

        $this->assertDatabaseHas('knowledge_bases', [
            'user_id' => $user->id,
            'name' => 'Laravel Docs',
            'description' => 'Laravel documentation.',
        ]);
    }

    public function test_user_can_show_their_knowledge_base(): void
    {
        $user = User::factory()->create();

        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson("/knowledge-bases/{$knowledgeBase->id}");

        $response
            ->assertOk()
            ->assertJsonPath('knowledge_base.id', $knowledgeBase->id);
    }

    public function test_user_can_update_their_knowledge_base(): void
    {
        $user = User::factory()->create();

        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson("/knowledge-bases/{$knowledgeBase->id}", [
                'name' => 'Updated Knowledge Base',
                'description' => 'Updated description.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'knowledge_base.name',
                'Updated Knowledge Base'
            );

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => $knowledgeBase->id,
            'name' => 'Updated Knowledge Base',
        ]);
    }

    public function test_user_can_delete_their_knowledge_base(): void
    {
        $user = User::factory()->create();

        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->deleteJson("/knowledge-bases/{$knowledgeBase->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('knowledge_bases', [
            'id' => $knowledgeBase->id,
        ]);
    }

    public function test_user_cannot_access_another_users_knowledge_base(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $knowledgeBase = KnowledgeBase::factory()->create([
            'user_id' => $owner->id,
        ]);

        $this
            ->actingAs($otherUser)
            ->getJson("/knowledge-bases/{$knowledgeBase->id}")
            ->assertNotFound();

        $this
            ->actingAs($otherUser)
            ->putJson("/knowledge-bases/{$knowledgeBase->id}", [
                'name' => 'Hacked',
                'description' => 'Should not work.',
            ])
            ->assertNotFound();

        $this
            ->actingAs($otherUser)
            ->deleteJson("/knowledge-bases/{$knowledgeBase->id}")
            ->assertNotFound();
    }

    public function test_user_cannot_see_another_users_knowledge_bases(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        KnowledgeBase::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Private Knowledge Base',
        ]);

        $response = $this
            ->actingAs($otherUser)
            ->getJson('/knowledge-bases');

        $response
            ->assertOk()
            ->assertJsonMissing([
                'name' => 'Private Knowledge Base',
            ]);
    }

    public function test_knowledge_base_name_is_required(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->postJson('/knowledge-bases', [
                'description' => 'No name.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }
}
