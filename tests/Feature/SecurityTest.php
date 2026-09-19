<?php

namespace Tests\Feature;

use App\Contracts\AIProviderInterface;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_KEY = 'sk-secure-test-0123456789';

    protected function fakePateway(string $assistantContent = 'Secure reply.'): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'secure-model-abc');
        config()->set('ai.providers.pateway.api_key', self::SECRET_KEY);

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $assistantContent]]],
            ]),
        ]);
    }

    // ------------------------------------------------- a u t h e n t i c a t i o n

    public function test_unauthenticated_user_cannot_list_conversations(): void
    {
        $this->getJson('/conversations')->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_view_conversation(): void
    {
        $this->getJson('/conversations/1')->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_read_messages(): void
    {
        $this->getJson('/conversations/1/messages')->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_chat(): void
    {
        $this->postJson('/conversations/1/chat', ['content' => 'Hi'])->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_stream_chat(): void
    {
        $this->postJson('/conversations/1/chat', ['content' => 'Hi', 'stream' => true])->assertStatus(401);
    }

    public function test_authenticated_user_is_redirected_away_from_guest_routes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/login')->assertStatus(302);
        $this->actingAs($user)->get('/register')->assertStatus(302);
        $this->actingAs($user)->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(302);

        $this->actingAs($user)->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(302);

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
    }

    public function test_password_is_never_stored_in_plaintext(): void
    {
        $this->postJson('/register', [
            'name' => 'New Operator',
            'email' => 'op@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $user = User::where('email', 'op@example.com')->firstOrFail();

        $this->assertNotSame('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/user')->assertOk();
        $sessionIdBefore = session()->getId();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNotSame($sessionIdBefore, session()->getId());
        $this->getJson('/conversations')->assertStatus(401);
    }

    public function test_user_endpoint_hides_sensitive_fields_and_api_keys(): void
    {
        config()->set('ai.providers.pateway.api_key', self::SECRET_KEY);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/user');

        $response->assertOk()->assertJsonPath('name', $user->name);

        $json = $response->json();

        $this->assertArrayNotHasKey('password', $json);
        $this->assertArrayNotHasKey('remember_token', $json);
        $this->assertArrayNotHasKey('api_key', $json);
        $this->assertStringNotContainsString(self::SECRET_KEY, $response->getContent());
    }

    // ------------------------------------------------- a u t h o r i z a t i o n

    public function test_conversation_index_is_scoped_to_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Conversation::factory()->create(['user_id' => $owner->id, 'title' => 'Owned Secret']);
        Conversation::factory()->create(['user_id' => $other->id, 'title' => 'Other Users Data']);

        $response = $this->actingAs($owner)->getJson('/conversations');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->all();

        $this->assertContains('Owned Secret', $titles);
        $this->assertNotContains('Other Users Data', $titles);
    }

    public function test_cross_user_conversation_access_is_blocked(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)->getJson("/conversations/{$conversation->id}")->assertNotFound();
        $this->actingAs($other)->putJson("/conversations/{$conversation->id}", ['title' => 'Hi'])  ->assertNotFound();
        $this->actingAs($other)->deleteJson("/conversations/{$conversation->id}")->assertNotFound();

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    public function test_cross_user_message_access_is_blocked(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $owner->id]);
        $message = $conversation->messages()->create(['role' => 'user', 'content' => 'Secret']);

        $this->actingAs($other)->getJson("/conversations/{$conversation->id}/messages")->assertNotFound();
        $this->actingAs($other)->postJson("/conversations/{$conversation->id}/messages", [
            'role' => 'user', 'content' => 'Hi',
        ])->assertNotFound();
        $this->actingAs($other)->putJson("/conversations/{$conversation->id}/messages/{$message->id}", [
            'content' => 'X',
        ])->assertNotFound();
        $this->actingAs($other)->deleteJson("/conversations/{$conversation->id}/messages/{$message->id}")->assertNotFound();
        $this->actingAs($other)->postJson("/conversations/{$conversation->id}/messages/{$message->id}/regenerate")->assertNotFound();

        $this->assertDatabaseHas('messages', ['id' => $message->id]);
    }

    public function test_message_access_is_scoped_to_its_conversation(): void
    {
        $user = User::factory()->create();
        $convA = Conversation::factory()->create(['user_id' => $user->id]);
        $convB = Conversation::factory()->create(['user_id' => $user->id]);
        $msgB = $convB->messages()->create(['role' => 'user', 'content' => 'Data']);

        $this->actingAs($user)->deleteJson("/conversations/{$convA->id}/messages/{$msgB->id}")->assertNotFound();
        $this->actingAs($user)->putJson("/conversations/{$convA->id}/messages/{$msgB->id}", [
            'content' => 'Hi',
        ])->assertNotFound();
        $this->actingAs($user)->postJson("/conversations/{$convA->id}/messages/{$msgB->id}/regenerate")->assertNotFound();

        $this->assertDatabaseHas('messages', ['id' => $msgB->id]);
    }

    public function test_invalid_conversation_id_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/conversations/999999')->assertNotFound();
        $this->actingAs($user)->putJson('/conversations/999999', ['title' => 'X'])->assertNotFound();
        $this->actingAs($user)->deleteJson('/conversations/999999')->assertNotFound();
        $this->actingAs($user)->postJson('/conversations/999999/chat', ['content' => 'Hi'])->assertNotFound();
    }

    public function test_non_numeric_conversation_id_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/conversations/abc')->assertNotFound();
    }

    public function test_invalid_message_id_returns_404(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->deleteJson("/conversations/{$conversation->id}/messages/999999")->assertNotFound();
        $this->actingAs($user)->putJson("/conversations/{$conversation->id}/messages/999999", [
            'content' => 'X',
        ])->assertNotFound();
        $this->actingAs($user)->postJson("/conversations/{$conversation->id}/messages/999999/regenerate")->assertNotFound();
    }

    // ------------------------------------------------- i n p u t   v a l i d a t i o n

    public function test_invalid_message_payload_is_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson("/conversations/{$conversation->id}/messages", [
            'role' => 'hacker',
            'content' => 'X',
        ])->assertStatus(422)->assertJsonValidationErrors(['role']);

        $this->actingAs($user)->postJson("/conversations/{$conversation->id}/messages", [
            'role' => 'user',
            'content' => 'X',
            'metadata' => 'not-an-array',
        ])->assertStatus(422)->assertJsonValidationErrors(['metadata']);
    }

    public function test_oversized_message_content_is_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $giant = str_repeat('A', 100001);

        $this->actingAs($user)->postJson("/conversations/{$conversation->id}/chat", [
            'content' => $giant,
        ])->assertStatus(422)->assertJsonValidationErrors(['content']);

        $this->actingAs($user)->postJson("/conversations/{$conversation->id}/messages", [
            'role' => 'user',
            'content' => $giant,
        ])->assertStatus(422)->assertJsonValidationErrors(['content']);

        $userMessage = $conversation->messages()->create(['role' => 'user', 'content' => 'Small']);
        $this->actingAs($user)->putJson("/conversations/{$conversation->id}/messages/{$userMessage->id}", [
            'content' => $giant,
        ])->assertStatus(422)->assertJsonValidationErrors(['content']);
    }

    public function test_oversized_conversation_title_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/conversations', [
            'title' => str_repeat('A', 256),
        ])->assertStatus(422)->assertJsonValidationErrors(['title']);
    }

    // ------------------------------------------------- m a s s   a s s i g n m e n t

    public function test_conversation_user_id_cannot_be_tampered_via_payload(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/conversations', [
            'title' => 'Legit Title',
            'user_id' => $other->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('conversations', [
            'id' => $response->json('conversation.id'),
            'user_id' => $user->id,
            'title' => 'Legit Title',
        ]);
    }

    public function test_message_conversation_id_cannot_be_tampered_via_payload(): void
    {
        $user = User::factory()->create();
        $convA = Conversation::factory()->create(['user_id' => $user->id]);
        $convB = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/conversations/{$convA->id}/messages", [
            'role' => 'user',
            'content' => 'Hi',
            'conversation_id' => $convB->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('messages', [
            'id' => $response->json('data.id'),
            'conversation_id' => $convA->id,
        ]);
    }

    // ------------------------------------------------- m a r k d o w n   /   X S S

    public function test_malicious_html_content_round_trips_without_server_side_execution(): void
    {
        $this->fakePateway('<img src=x onerror=alert(1)> reply');

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $payload = '<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a>';

        $response = $this->actingAs($user)->postJson("/conversations/{$conversation->id}/chat", [
            'content' => $payload,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user_message.content', $payload);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $payload,
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '<img src=x onerror=alert(1)> reply',
        ]);

        Http::assertSent(function (Request $request) use ($payload) {
            return $request->url() === 'https://api.pateway.ai/v1/chat/completions'
                && collect($request->data()['messages'])
                    ->contains(fn ($m) => $m['role'] === 'user' && $m['content'] === $payload);
        });
    }

    public function test_markdown_code_blocks_and_links_survive_as_plain_content(): void
    {
        $this->fakePateway('```js\nconsole.log("ok");\n```');

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $payload = "Use [link](https://example.com) and\n\n```js\nconst a = 1;\n```";

        $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", ['content' => $payload])
            ->assertOk();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $payload,
        ]);
    }

    // ------------------------------------------------- p r o v i d e r   s e c u r i t y

    public function test_provider_failure_message_is_sanitized_for_logs(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'secure-model-abc');
        config()->set('ai.providers.pateway.api_key', self::SECRET_KEY);

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'error' => ['message' => self::SECRET_KEY . ' upstream echo'],
            ], 500),
        ]);

        $provider = app(AIProviderInterface::class);

        try {
            $provider->chat([['role' => 'user', 'content' => 'Hi']], ['model' => 'secure-model-abc']);
            $this->fail('Expected a RuntimeException from the provider.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET_KEY, $e->getMessage());
            $this->assertStringNotContainsString('upstream echo', $e->getMessage());
        }
    }

    public function test_provider_failure_returns_safe_user_facing_error(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'secure-model-abc');
        config()->set('ai.providers.pateway.api_key', self::SECRET_KEY);

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'error' => ['message' => self::SECRET_KEY . ' disaster'],
            ], 500),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/conversations/{$conversation->id}/chat", [
            'content' => 'Hi',
        ]);

        $response->assertStatus(502)
            ->assertJsonPath('message', 'AI request failed.')
            ->assertDontSee(self::SECRET_KEY, false)
            ->assertDontSee('Stack Trace', false)
            ->assertDontSee('vendor/', false)
            ->assertDontSee('Exception', false);
    }

    public function test_api_key_is_never_exposed_in_chat_response(): void
    {
        $this->fakePateway('Vault Response');

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/conversations/{$conversation->id}/chat", [
            'content' => 'Hello',
        ]);

        $response->assertOk()
            ->assertDontSee(self::SECRET_KEY, false)
            ->assertDontSee('api_key', false)
            ->assertDontSee('Bearer', false);
    }

    // ------------------------------------------------- r a t e   l i m i t i n g

    public function test_ai_chat_endpoints_are_rate_limited(): void
    {
        $this->fakePateway('Rate reply');

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($user)
                ->postJson("/conversations/{$conversation->id}/chat", [
                    'content' => "Message {$i}",
                ])->assertOk();
        }

        $this->actingAs($user)
            ->postJson("/conversations/{$conversation->id}/chat", [
                'content' => 'Too many',
            ])->assertStatus(429);
    }

    public function test_rate_limit_is_scoped_to_the_user_not_the_conversation_id(): void
    {
        $this->fakePateway('Rate reply');

        $user = User::factory()->create();
        $convA = Conversation::factory()->create(['user_id' => $user->id]);
        $convB = Conversation::factory()->create(['user_id' => $user->id]);

        for ($i = 0; $i < 59; $i++) {
            $target = $i % 2 === 0 ? $convA : $convB;
            $this->actingAs($user)
                ->postJson("/conversations/{$target->id}/chat", [
                    'content' => "Message {$i}",
                ])->assertOk();
        }

        $this->actingAs($user)
            ->postJson("/conversations/{$convA->id}/chat", [
                'content' => 'Threshold',
            ])->assertOk();

        $this->actingAs($user)
            ->postJson("/conversations/{$convB->id}/chat", [
                'content' => 'Over limit',
            ])->assertStatus(429);
    }
}