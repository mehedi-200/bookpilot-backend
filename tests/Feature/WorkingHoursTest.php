<?php

namespace Tests\Feature;

use App\Models\ClosedDate;
use App\Models\User;
use Database\Seeders\BusinessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessSeeder::class);
    }

    private function validDays(): array
    {
        return collect(range(0, 6))->map(fn ($day) => [
            'day_of_week' => $day,
            'is_closed' => $day === 5, // Friday closed
            'open_time' => $day === 5 ? null : '10:00',
            'close_time' => $day === 5 ? null : '20:00',
        ])->all();
    }

    public function test_index_returns_seven_days_and_closed_dates(): void
    {
        ClosedDate::create(['date' => now()->addDays(3)->toDateString(), 'reason' => 'Eid']);

        $this->actingAs(User::factory()->create())->getJson('/api/working-hours')
            ->assertOk()
            ->assertJsonCount(7, 'data.days')
            ->assertJsonCount(1, 'data.closed_dates')
            ->assertJsonPath('data.closed_dates.0.reason', 'Eid');
    }

    public function test_admin_can_update_all_seven_days(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/working-hours', ['days' => $this->validDays()])
            ->assertOk()
            ->assertJsonPath('data.days.5.is_closed', true)
            ->assertJsonPath('data.days.1.open_time', '10:00');
    }

    public function test_close_before_open_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $days = $this->validDays();
        $days[1]['open_time'] = '18:00';
        $days[1]['close_time'] = '09:00';

        $this->actingAs($admin)->putJson('/api/working-hours', ['days' => $days])
            ->assertStatus(422);
    }

    public function test_missing_day_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/working-hours', [
            'days' => array_slice($this->validDays(), 0, 6),
        ])->assertStatus(422);
    }

    public function test_staff_cannot_update_hours(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/api/working-hours', ['days' => $this->validDays()])
            ->assertStatus(403);
    }

    public function test_closed_dates_add_and_remove_with_guards(): void
    {
        $admin = User::factory()->admin()->create();

        $id = $this->actingAs($admin)->postJson('/api/closed-dates', [
            'date' => now()->addWeek()->toDateString(),
            'reason' => 'Holiday',
        ])->assertStatus(201)->json('data.id');

        // Duplicate rejected
        $this->actingAs($admin)->postJson('/api/closed-dates', [
            'date' => now()->addWeek()->toDateString(),
        ])->assertStatus(422);

        // Past date rejected
        $this->actingAs($admin)->postJson('/api/closed-dates', [
            'date' => now()->subDay()->toDateString(),
        ])->assertStatus(422);

        $this->actingAs($admin)->deleteJson("/api/closed-dates/{$id}")->assertOk();
        $this->assertDatabaseCount('closed_dates', 0);
    }
}
