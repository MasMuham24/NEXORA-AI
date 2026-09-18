<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_root_renders_login_screen(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('NEXORA');
        $response->assertSee('Establish Session', false);
    }

    public function test_guest_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('NEXORA');
        $this->get('/register')->assertOk()->assertSee('NEXORA');
    }

    public function test_authenticated_root_renders_workspace(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('WORKSPACE', false);
        $response->assertSee('composer', false);
    }
}