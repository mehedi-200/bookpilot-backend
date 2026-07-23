<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_ping_returns_success_shape(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'BookPilot API is up',
                'data' => ['pong' => true],
            ]);
    }

    public function test_unknown_api_route_returns_json_404_shape(): void
    {
        $this->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found.',
            ]);
    }

    public function test_validation_error_returns_422_shape_with_errors_bag(): void
    {
        Route::post('/api/_validation-probe', function (\Illuminate\Http\Request $request) {
            $request->validate(['name' => 'required']);
        });

        $this->postJson('/api/_validation-probe', [])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['success', 'message', 'errors' => ['name']]);
    }

    public function test_unauthenticated_api_request_returns_401_shape(): void
    {
        $this->getJson('/api/user')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }
}
