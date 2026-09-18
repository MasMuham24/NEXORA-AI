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

        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://api.pateway.ai/v1/chat/completions'
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

        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://api.pateway.ai/v1/anthropic/v1/messages'
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
                . "data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\"}}\n\n"
                . "event: content_block_delta\n"
                . "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Thinking\"}}\n\n"
                . "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\" fast.\"}}\n\n"
                . "data: [DONE]\n\n",
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

        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://api.pateway.ai/v1/chat/completions'
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
            $hasUserMessage = collect($payload['messages'])->first(fn ($m) =>
                $m['role'] === 'user' && $m['content'] === $userMessage->content
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

        Http::assertSent(function (Request $request) use ($userMessage) {
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

        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://api.pateway.ai/v1/chat/completions'
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

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Original question',
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Old reply.',
        ]);

        $response = $this->actingAs($user)
            ->putJson("/conversations/{$conversation->id}/messages/{$userMessage->id}", [
                'content' => 'Updated question',
            ]);

        $response->assertStatus(502)
            ->assertJsonPath('message', 'AI request failed.');

        $this->assertSame(1, $conversation->messages()->count());
    }

    private function fakeStreamingResponse(?string &$sentModel = null): void
    {
        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => function (Request $request) use (&$sentModel) {
                $sentModel = $request['model'];

                return Http::response(
                    "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
                    . "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n"
                    . "data: [DONE]\n\n",
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
}