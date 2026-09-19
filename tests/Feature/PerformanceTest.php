<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeProvider(string $content): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'perf-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $content]]],
            ]),
        ]);
    }

    public function test_conversation_index_is_deterministically_ordered_on_updated_at_ties(): void
    {
        $user = User::factory()->create();
        $first = Conversation::factory()->create(['user_id' => $user->id, 'updated_at' => '2026-01-01 00:00:00']);
        $second = Conversation::factory()->create(['user_id' => $user->id, 'updated_at' => '2026-01-01 00:00:00']);

        $response = $this->actingAs($user)->getJson('/conversations');

        $response->assertOk();
        $this->assertEquals(
            [$second->id, $first->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_conversation_index_is_paginated_without_overlap(): void
    {
        $user = User::factory()->create();
        Conversation::factory()->count(25)->create(['user_id' => $user->id]);

        $page1 = $this->actingAs($user)->getJson('/conversations?page=1');
        $page1->assertOk()->assertJsonCount(20, 'data');
        $this->assertSame(25, $page1->json('total'));

        $page2 = $this->actingAs($user)->getJson('/conversations?page=2');
        $page2->assertOk()->assertJsonCount(5, 'data');

        $ids1 = collect($page1->json('data'))->pluck('id')->all();
        $ids2 = collect($page2->json('data'))->pluck('id')->all();

        $this->assertEmpty(array_intersect($ids1, $ids2));
    }

    public function test_conversation_detail_loads_messages_in_a_single_query(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'A']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'B']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'C']);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($user)->getJson("/conversations/{$conversation->id}")->assertOk();

        $messageQueries = array_filter(
            DB::getQueryLog(),
            fn ($query) => str_contains($query['query'], 'from "messages"')
        );

        $this->assertCount(1, $messageQueries);
    }

    public function test_messages_are_paginated_with_stable_ordering(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        for ($i = 0; $i < 55; $i++) {
            $conversation->messages()->create(['role' => 'user', 'content' => "M{$i}"]);
        }

        $page1 = $this->actingAs($user)->getJson("/conversations/{$conversation->id}/messages?page=1");
        $page1->assertOk()->assertJsonCount(50, 'data');
        $this->assertSame(55, $page1->json('total'));

        $page2 = $this->actingAs($user)->getJson("/conversations/{$conversation->id}/messages?page=2");
        $page2->assertOk()->assertJsonCount(5, 'data');

        $ids1 = collect($page1->json('data'))->pluck('id')->all();
        $ids2 = collect($page2->json('data'))->pluck('id')->all();

        for ($i = 1; $i < count($ids1); $i++) {
            $this->assertGreaterThan($ids1[$i - 1], $ids1[$i]);
        }
        for ($i = 1; $i < count($ids2); $i++) {
            $this->assertGreaterThan($ids2[$i - 1], $ids2[$i]);
        }
        $this->assertGreaterThan($ids1[count($ids1) - 1], $ids2[0]);
    }

    public function test_messages_with_equal_timestamps_fall_back_to_id_order(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $a = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'A',
            'created_at' => '2026-01-02 00:00:00',
        ]);
        $b = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'B',
            'created_at' => '2026-01-02 00:00:00',
        ]);
        $c = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'C',
            'created_at' => '2026-01-02 00:00:00',
        ]);

        $response = $this->actingAs($user)->getJson("/conversations/{$conversation->id}/messages");

        $response->assertOk();
        $this->assertEquals(
            [$a->id, $b->id, $c->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_malformed_non_stream_response_returns_safe_502(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'perf-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response(['choices' => []], 200),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/conversations/{$conversation->id}/chat", [
            'content' => 'Hi',
        ]);

        $response->assertStatus(502)
            ->assertJsonPath('message', 'AI request failed.');

        $this->assertSame(1, $conversation->messages()->count());
    }

    public function test_malformed_stream_without_done_marker_persists_partial_content(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'perf-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response(
                "data: {\"choices\":[{\"delta\":{\"content\":\"Part one\"}}]}\n\n"
                . "data: {\"choices\":[{\"delta\":{\"content\":\" part two\"}}]}\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/conversations/{$conversation->id}/chat", [
            'content' => 'Hi',
            'stream' => true,
        ]);

        $response->assertOk();

        $content = $response->streamedContent();
        $this->assertStringContainsString('data: {"content":"Part one"}', $content);
        $this->assertStringContainsString('data: {"content":" part two"}', $content);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Part one part two',
        ]);
    }

    public function test_stream_empty_body_persists_no_assistant_message(): void
    {
        config()->set('ai.provider', 'pateway');
        config()->set('ai.providers.pateway.model', 'perf-model-abc');

        Http::fake([
            'https://api.pateway.ai/v1/chat/completions' => Http::response('', 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/conversations/{$conversation->id}/chat", [
            'content' => 'Hi',
            'stream' => true,
        ]);

        $response->assertOk();
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hi',
        ]);
    }

    public function test_ai_timeout_config_values_are_available(): void
    {
        $this->fakeProvider('Ping');

        $this->assertSame(60, config('ai.timeout'));
        $this->assertSame(120, config('ai.stream_timeout'));
        $this->assertSame(2048, config('ai.providers.pateway.max_tokens'));

        Http::assertNothingSent();
    }
}