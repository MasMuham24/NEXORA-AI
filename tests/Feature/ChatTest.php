<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_send_chat_message_and_get_ai_reply(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Hello! I am an AI assistant.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello, introduce yourself.',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'AI response generated.')
            ->assertJsonPath('data.user_message.role', 'user')
            ->assertJsonPath('data.user_message.content', 'Hello, introduce yourself.')
            ->assertJsonPath('data.assistant_message.role', 'assistant')
            ->assertJsonPath('data.assistant_message.content', 'Hello! I am an AI assistant.');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello, introduce yourself.',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hello! I am an AI assistant.',
        ]);
    }

    public function test_default_model_is_used_when_no_model_sent(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Reply from default model.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.user_message.metadata.model', 'default-model-abc')
            ->assertJsonPath('data.assistant_message.metadata.model', 'default-model-abc');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pateway.ai/v1/chat/completions'
            && $request['model'] === 'default-model-abc'
        );
    }

    public function test_claude_model_routes_to_anthropic_messages_endpoint(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'claude-haiku-4-5-20251001');

        Http::fake([
            'https://api.pateway.ai/v1/anthropic/v1/messages' => Http::response([
                'type' => 'message',
                'model' => 'claude-haiku-4-5-20251001',
                'content' => [['type' => 'text', 'text' => 'Confirmed by Claude.']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.assistant_message.content', 'Confirmed by Claude.')
            ->assertJsonPath('data.assistant_message.metadata.model', 'claude-haiku-4-5-20251001');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pateway.ai/v1/anthropic/v1/messages'
            && $request['model'] === 'claude-haiku-4-5-20251001'
            && $request['max_tokens'] === 2048
            && $request['stream'] === false
            && str_starts_with($request['messages'][0]['role'], 'user')
        );
    }

    public function test_claude_model_streams_via_anthropic_messages_endpoint(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'claude-haiku-4-5-20251001');

        Http::fake([
            'https://api.pateway.ai/v1/anthropic/v1/messages' => Http::response(
                "event: message_start\n"
                ."data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\"}}\n\n"
                ."event: content_block_delta\n"
                ."data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Thinking\"}}\n\n"
                ."data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\" fast.\"}}\n\n"
                ."data: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
                'stream' => true,
            ]);

        $response->assertOk();

        $content = $response->streamedContent();
        $this->assertStringContainsString('data: {"content":"Thinking"}', $content);
        $this->assertStringContainsString('data: {"content":" fast."}', $content);
        $this->assertStringContainsString('data: [DONE]', $content);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Thinking fast.',
        ]);
    }

    public function test_custom_model_overrides_default(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Reply from custom model.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
                'model' => 'custom-model-xyz',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.user_message.metadata.model', 'custom-model-xyz')
            ->assertJsonPath('data.assistant_message.metadata.model', 'custom-model-xyz');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pateway.ai/v1/chat/completions'
            && $request['model'] === 'custom-model-xyz'
        );
    }

    public function test_empty_model_returns_clear_error(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', null);

        Http::fake();

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'AI model is not configured. Set PATEWAY_MODEL or provide a model.');

        Http::assertNothingSent();
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_other_user_cannot_chat_in_conversation(): void
    {
        Http::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hi',
            ]);

        $response->assertNotFound();
    }

    public function test_chat_returns_502_when_ai_request_fails(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([], 500),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hi',
            ]);

        $response->assertStatus(502)
            ->assertJsonPath('message', 'AI request failed.');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hi',
        ]);
        $this->assertSame(1, $conversation->messages()->count());
    }

    public function test_assistant_message_can_be_regenerated(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Regenerated reply.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Hello',
            'metadata' => ['provider' => 'pateway', 'model' => 'default-model-abc'],
        ]);

        $oldAssistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Old reply.',
            'metadata' => ['provider' => 'pateway', 'model' => 'default-model-abc'],
        ]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/messages/{$oldAssistant->id}/regenerate");

        $response->assertOk()
            ->assertJsonPath('message', 'AI response regenerated.')
            ->assertJsonPath('data.previous_message.id', $oldAssistant->id)
            ->assertJsonPath('data.assistant_message.content', 'Regenerated reply.')
            ->assertJsonPath('data.assistant_message.metadata.model', 'default-model-abc')
            ->assertJsonPath('data.assistant_message.metadata.regenerated', true)
            ->assertJsonPath('data.assistant_message.metadata.regenerated_from', $oldAssistant->id);

        Http::assertSent(function (Request $request) use ($userMessage) {
            if ($request->url() !== 'https://api.pateway.ai/v1/chat/completions') {
                return false;
            }

            $payload = $request->data();
            $hasUserMessage = collect($payload['messages'])->first(fn ($m) => $m['role'] === 'user' && $m['content'] === $userMessage->content
            );

            return $hasUserMessage !== null && $request['model'] === 'default-model-abc';
        });

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Old reply.',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Regenerated reply.',
        ]);
    }

    public function test_regenerate_fails_when_message_is_not_assistant(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Hello',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/messages/{$userMessage->id}/regenerate");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Only assistant messages can be regenerated.');
    }

    public function test_regenerate_fails_when_message_belongs_to_other_conversation(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $otherConversation = Conversation::factory()->create(['user_id' => $user->id]);

        $otherMessage = $otherConversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Reply.',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/messages/{$otherMessage->id}/regenerate");

        $response->assertNotFound();
    }

    public function test_regenerate_fails_when_user_cannot_access_conversation(): void
    {
        Http::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $owner->id]);

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Reply.',
        ]);

        $response = $this->actingAs($other)
            ->postJson("/conversations/{$conversation->id}/messages/{$assistantMessage->id}/regenerate");

        $response->assertNotFound();
    }

    public function test_regenerate_returns_502_when_ai_fails(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([], 500),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Hello',
        ]);

        $oldAssistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Old reply.',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/messages/{$oldAssistant->id}/regenerate");

        $response->assertStatus(502)
            ->assertJsonPath('message', 'AI request failed.');
    }

    public function test_user_message_can_be_edited_and_resent(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'New reply after edit.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Original question',
        ]);

        $oldAssistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Old reply.',
        ]);

        $response = $this->actingAs($user)
            ->putJson("/conversations/{$conversation->id}/messages/{$userMessage->id}", [
                'content' => 'Updated question',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Message edited and AI response regenerated.')
            ->assertJsonPath('data.user_message.id', $userMessage->id)
            ->assertJsonPath('data.user_message.content', 'Updated question')
            ->assertJsonPath('data.assistant_message.content', 'New reply after edit.')
            ->assertJsonPath('data.assistant_message.metadata.model', 'default-model-abc')
            ->assertJsonPath('data.assistant_message.metadata.edited', true)
            ->assertJsonPath('data.assistant_message.metadata.edited_message', $userMessage->id);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://api.pateway.ai/v1/chat/completions') {
                return false;
            }

            $payload = $request->data();
            $lastMessage = end($payload['messages']);

            return $lastMessage === ['role' => 'user', 'content' => 'Updated question']
                && $request['model'] === 'default-model-abc';
        });

        $this->assertSame(2, $conversation->messages()->count());
        $this->assertDatabaseMissing('messages', ['id' => $oldAssistant->id]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'New reply after edit.',
        ]);
    }

    public function test_edit_supports_custom_model(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Reply via custom model.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Original question',
        ]);

        $response = $this->actingAs($user)
            ->putJson("/conversations/{$conversation->id}/messages/{$userMessage->id}", [
                'content' => 'Updated question',
                'model' => 'custom-model-xyz',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.assistant_message.metadata.model', 'custom-model-xyz');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pateway.ai/v1/chat/completions'
            && $request['model'] === 'custom-model-xyz'
        );
    }

    public function test_edit_fails_when_message_is_not_user(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Reply.',
        ]);

        $response = $this->actingAs($user)
            ->putJson("/conversations/{$conversation->id}/messages/{$assistantMessage->id}", [
                'content' => 'Edited',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Only user messages can be edited.');
    }

    public function test_edit_fails_when_message_belongs_to_other_conversation(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $otherConversation = Conversation::factory()->create(['user_id' => $user->id]);

        $otherMessage = $otherConversation->messages()->create([
            'role' => 'user',
            'content' => 'Question',
        ]);

        $response = $this->actingAs($user)
            ->putJson("/conversations/{$conversation->id}/messages/{$otherMessage->id}", [
                'content' => 'Edited',
            ]);

        $response->assertNotFound();
    }

    public function test_edit_fails_when_user_cannot_access_conversation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $owner->id]);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Question',
        ]);

        $response = $this->actingAs($other)
            ->putJson("/conversations/{$conversation->id}/messages/{$userMessage->id}", [
                'content' => 'Edited',
            ]);

        $response->assertNotFound();
    }

    public function test_edit_returns_502_when_ai_fails(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([], 500),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $updatedAtBefore = $conversation->fresh()->updated_at;

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Original question',
        ]);

        $oldAssistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Old reply.',
        ]);

        $this->travel(10)->minutes();

        $response = $this->actingAs($user)
            ->putJson("/conversations/{$conversation->id}/messages/{$userMessage->id}", [
                'content' => 'Updated question',
            ]);

        $response->assertStatus(502)
            ->assertJsonPath('message', 'AI request failed.');

        $this->assertSame(2, $conversation->messages()->count());
        $this->assertDatabaseHas('messages', [
            'id' => $userMessage->id,
            'content' => 'Updated question',
        ]);
        $this->assertDatabaseHas('messages', [
            'id' => $oldAssistant->id,
            'content' => 'Old reply.',
        ]);
        $this->assertEquals($updatedAtBefore, $conversation->fresh()->updated_at);
    }

    public function test_edit_ai_context_excludes_messages_after_edited_message(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'New reply.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'First',
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'First reply.',
        ]);
        $edited = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Second question',
        ]);
        $subsequent = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Subsequent reply.',
        ]);

        $this->actingAs($user)
            ->putJson("/conversations/{$conversation->id}/messages/{$edited->id}", [
                'content' => 'Edited second question',
            ])->assertOk();

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://api.pateway.ai/v1/chat/completions') {
                return false;
            }

            $messages = $request->data()['messages'];

            return count($messages) === 3
                && $messages[0] === ['role' => 'user', 'content' => 'First']
                && $messages[1] === ['role' => 'assistant', 'content' => 'First reply.']
                && $messages[2] === ['role' => 'user', 'content' => 'Edited second question'];
        });

        $this->assertDatabaseMissing('messages', ['id' => $subsequent->id]);
    }

    private function fakeStreamingResponse(?string &$sentModel = null): void
    {
        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => function (Request $request) use (&$sentModel) {
                $sentModel = $request['model'];

                return Http::response(
                    "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
                    ."data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n"
                    ."data: [DONE]\n\n",
                    200,
                    ['Content-Type' => 'text/event-stream']
                );
            },
        ]);
    }

    public function test_streaming_chat_returns_sse_headers(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        $this->fakeStreamingResponse();

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
                'stream' => true,
            ]);

        $response->assertOk()->assertHeader('X-Accel-Buffering', 'no');

        $this->assertStringContainsString(
            'text/event-stream',
            $response->headers->get('Content-Type')
        );

        $this->assertStringContainsString(
            'no-cache',
            $response->headers->get('Cache-Control')
        );
    }

    public function test_streaming_chat_produces_chunks_and_saves_assistant_message(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        $this->fakeStreamingResponse();

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
                'stream' => true,
            ]);

        $content = $response->streamedContent();

        $this->assertStringContainsString('data: {"content":"Hello"}', $content);
        $this->assertStringContainsString('data: {"content":" world"}', $content);
        $this->assertStringContainsString('data: [DONE]', $content);

        $this->assertSame(2, $conversation->messages()->count());
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hello world',
            'metadata->stream' => true,
        ]);
    }

    public function test_streaming_chat_uses_default_model(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        $sentModel = null;
        $this->fakeStreamingResponse($sentModel);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
                'stream' => true,
            ]);

        $content = $response->streamedContent();
        $this->assertStringContainsString('data: [DONE]', $content);

        $this->assertSame('default-model-abc', $sentModel);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'metadata->model' => 'default-model-abc',
            'metadata->stream' => true,
        ]);
    }

    public function test_streaming_chat_uses_custom_model(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        $sentModel = null;
        $this->fakeStreamingResponse($sentModel);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
                'model' => 'custom-model-xyz',
                'stream' => true,
            ]);

        $content = $response->streamedContent();
        $this->assertStringContainsString('data: [DONE]', $content);

        $this->assertSame('custom-model-xyz', $sentModel);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'metadata->model' => 'custom-model-xyz',
            'metadata->stream' => true,
        ]);
    }

    public function test_streaming_chat_emits_error_event_when_ai_fails(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([], 500),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
                'stream' => true,
            ]);

        $content = $response->streamedContent();

        $this->assertStringContainsString('"error":"AI request failed."', $content);
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hello',
        ]);
    }

    public function test_streaming_chat_fails_when_user_cannot_access_conversation(): void
    {
        Http::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
                'stream' => true,
            ]);

        $response->assertNotFound();
    }

    public function test_invalid_model_format_is_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
                'model' => 'model/with/slashes',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['model']);
    }

    public function test_streaming_chat_handles_malformed_json_chunks_gracefully(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response(
                "data: not-valid-json\n\n"
                ."data: {\"choices\":[{\"delta\":{\"content\":\"Valid\"}}]}\n\n"
                ."data: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
                'stream' => true,
            ]);

        $content = $response->streamedContent();

        $this->assertStringContainsString('data: {"content":"Valid"}', $content);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Valid',
        ]);
    }

    public function test_conversation_auto_titles_on_first_message(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Reply.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => null]);

        $this->assertNull($conversation->title);

        $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Explain the Laravel service layer pattern',
            ]);

        $conversation->refresh();
        $this->assertNotNull($conversation->title);
        $this->assertEquals('Explain the Laravel service layer pattern', $conversation->title);
    }

    public function test_conversation_title_truncated_at_60_chars(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Reply.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => null]);

        $longMessage = str_repeat('A', 100);

        $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => $longMessage,
            ]);

        $conversation->refresh();
        $this->assertEquals(60, strlen($conversation->title));
    }

    public function test_conversation_not_retitled_on_subsequent_messages(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Reply.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Original Title']);

        $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Second message',
            ]);

        $conversation->refresh();
        $this->assertEquals('Original Title', $conversation->title);
    }

    public function test_messages_returned_in_chronological_order(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $msg1 = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'First',
            'created_at' => now()->subMinutes(5),
        ]);

        $msg2 = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Second',
            'created_at' => now()->subMinutes(4),
        ]);

        $msg3 = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Third',
            'created_at' => now()->subMinutes(3),
        ]);

        $response = $this->actingAs($user)
            ->getJson("/conversations/{$conversation->id}/messages");

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertEquals([$msg1->id, $msg2->id, $msg3->id], $ids);
    }

    public function test_conversation_search_returns_matching_conversations(): void
    {
        $user = User::factory()->create();

        $conv1 = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Laravel Tips']);
        $conv2 = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Vue.js Guide']);
        $conv3 = Conversation::factory()->create(['user_id' => $user->id, 'title' => 'Laravel Performance']);

        $response = $this->actingAs($user)
            ->getJson('/conversations');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->toArray();
        $this->assertContains('Laravel Tips', $titles);
        $this->assertContains('Vue.js Guide', $titles);
        $this->assertContains('Laravel Performance', $titles);
    }

    public function test_edit_message_and_subsequent_messages_deleted(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'New reply.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Original',
        ]);

        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Old reply',
        ]);

        $followUp = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Follow up',
        ]);

        $this->actingAs($user)
            ->putJson("/conversations/{$conversation->id}/messages/{$userMsg->id}", [
                'content' => 'Updated',
            ]);

        $this->assertDatabaseMissing('messages', ['id' => $assistantMsg->id]);
        $this->assertDatabaseMissing('messages', ['id' => $followUp->id]);
        $this->assertDatabaseHas('messages', [
            'id' => $userMsg->id,
            'content' => 'Updated',
        ]);
    }

    public function test_regenerate_creates_new_assistant_message(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Fresh reply.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Question',
        ]);

        $oldAssistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Old reply',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/messages/{$oldAssistant->id}/regenerate");

        $response->assertOk();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Fresh reply.',
        ]);

        $newAssistant = $conversation->messages()->where('role', 'assistant')->latest()->first();
        $this->assertNotEquals($oldAssistant->id, $newAssistant->id);
        $this->assertTrue($newAssistant->metadata['regenerated'] ?? false);
    }

    public function test_chat_prevents_duplicate_submissions(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Reply.']],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response1 = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
            ]);

        $response2 = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
            ]);

        $response1->assertOk();
        $response2->assertOk();

        $this->assertEquals(2, $conversation->messages()->where('role', 'user')->count());
    }

    public function test_streaming_partial_assistant_persisted_on_failure(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response(
                "data: {\"choices\":[{\"delta\":{\"content\":\"Partial\"}}]}\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Hello',
                'stream' => true,
            ]);

        $content = $response->streamedContent();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Partial',
        ]);

        $msg = $conversation->messages()->where('role', 'assistant')->first();
        $this->assertTrue($msg->metadata['stream'] ?? false);
    }

    public function test_chat_limits_ai_context_to_configured_message_count(): void
    {
        config(['ai.context_messages' => 3]);
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create([
            'user_id' => $user->id,
        ]);
        $conversation->messages()->createMany([
            ['role' => 'user', 'content' => 'Message 1'],
            ['role' => 'assistant', 'content' => 'Message 2'],
            ['role' => 'user', 'content' => 'Message 3'],
            ['role' => 'assistant', 'content' => 'Message 4'],
        ]);

        Http::fake([
            '*' => Http::response([
                'id' => 'msg_test',
                'model' => 'claude-haiku-4-20250414',
                'content' => [
                    ['text' => 'Final response'],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = $this->actingAs($user)->postJson("/conversations/{$conversation->id}/chat",
            [
                'content' => 'New message',
            ]);
        $response->assertOk();
        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'];

            return count($messages) === 4
                && $messages[0]['content'] === 'Message 2'
            && $messages[1]['content'] === 'Message 3'
            && $messages[2]['content'] === 'Message 4'
            && $messages[3]['content'] === 'New message';
        });
    }

    public function test_edit_limits_ai_context_to_configured_message_count(): void
    {
        config(['ai.context_messages' => 2]);
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Edited response.',
                        ],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();

        $conversation = Conversation::factory()->create([
            'user_id' => $user->id,
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Message 1',
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Message 2',
        ]);

        $message3 = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Message 3',
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Message 4',
        ]);

        $response = $this->actingAs($user)
            ->putJson(
                "/conversations/{$conversation->id}/messages/{$message3->id}",
                [
                    'content' => 'Edited Message 3',
                ]
            );

        $response->assertOk();

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://api.pateway.ai/v1/chat/completions') {
                return false;
            }

            $messages = $request->data()['messages'];

            return count($messages) === 2
                && $messages[0] === [
                    'role' => 'assistant',
                    'content' => 'Message 2',
                ]
                && $messages[1] === [
                    'role' => 'user',
                    'content' => 'Edited Message 3',
                ];
        });
    }

    public function test_deleting_message_updates_conversation_timestamp(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $message = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Delete me',
        ]);

        $this->travel(10)->minutes();

        $before = $conversation->fresh()->updated_at;

        $response = $this->actingAs($user)
            ->deleteJson("/conversations/{$conversation->id}/messages/{$message->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Message deleted.');

        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertNotEquals($before, $conversation->fresh()->updated_at);
    }

    public function test_deleting_message_bumps_conversation_to_top_of_index(): void
    {
        $user = User::factory()->create();
        $older = Conversation::factory()->create(['user_id' => $user->id, 'updated_at' => now()->subHour()]);
        $conversation = Conversation::factory()->create(['user_id' => $user->id, 'updated_at' => now()->subHours(2)]);

        $message = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Delete me',
        ]);

        $this->actingAs($user)->deleteJson("/conversations/{$conversation->id}/messages/{$message->id}")->assertOk();

        $ids = collect($this->actingAs($user)->getJson('/conversations')->json('data'))->pluck('id')->all();

        $this->assertEquals([$conversation->id, $older->id], $ids);
    }

    public function test_deleting_middle_message_is_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $middle = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Middle message',
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Newer reply',
        ]);

        $response = $this->actingAs($user)
            ->deleteJson("/conversations/{$conversation->id}/messages/{$middle->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Only the latest message can be deleted.');

        $this->assertDatabaseHas('messages', ['id' => $middle->id]);
    }

    public function test_deleting_older_assistant_message_is_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $older = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Older reply',
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Newer message',
        ]);

        $response = $this->actingAs($user)
            ->deleteJson("/conversations/{$conversation->id}/messages/{$older->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Only the latest message can be deleted.');

        $this->assertDatabaseHas('messages', ['id' => $older->id]);
    }

    public function test_deleting_latest_assistant_message_succeeds(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Question',
        ]);

        $latest = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Latest reply',
        ]);

        $this->actingAs($user)
            ->deleteJson("/conversations/{$conversation->id}/messages/{$latest->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Message deleted.');

        $this->assertDatabaseMissing('messages', ['id' => $latest->id]);
    }

    public function test_regenerate_limits_ai_context_to_configured_message_count(): void
    {
        config(['ai.context_messages' => 2]);
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'default-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Regenerated response.',
                        ],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();

        $conversation = Conversation::factory()->create([
            'user_id' => $user->id,
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Message 1',
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Message 2',
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Message 3',
        ]);

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Message 4',
        ]);

        $response = $this->actingAs($user)
            ->postJson(
                "/conversations/{$conversation->id}/messages/{$assistantMessage->id}/regenerate"
            );

        $response->assertOk();

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://api.pateway.ai/v1/chat/completions') {
                return false;
            }

            $messages = $request->data()['messages'];

            return count($messages) === 2
                && $messages[0] === [
                    'role' => 'assistant',
                    'content' => 'Message 2',
                ]
                && $messages[1] === [
                    'role' => 'user',
                    'content' => 'Message 3',
                ];
        });
    }
}
