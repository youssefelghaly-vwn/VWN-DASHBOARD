<?php

namespace Tests\Feature;

use App\Integration\Jobs\SyncIntegrationJob;
use App\Integration\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Auto-sync: the scheduled command queues a job per connected integration, and
 * the dashboard exposes a freshness snapshot + a "sync all" trigger.
 */
class AutoSyncTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_command_queues_one_job_per_connected_integration(): void
    {
        Queue::fake();

        Integration::create(['provider' => 'gohighlevel', 'name' => 'A', 'status' => 'connected']);
        Integration::create(['provider' => 'meta_ads', 'name' => 'B', 'status' => 'connected']);
        Integration::create(['provider' => 'google_sheets', 'name' => 'C', 'status' => 'disconnected']);

        $this->artisan('integrations:sync')->assertSuccessful();

        // Only the two connected integrations are queued.
        Queue::assertPushed(SyncIntegrationJob::class, 2);
    }

    public function test_sync_status_reports_the_latest_sync(): void
    {
        $admin = $this->admin();

        Integration::create(['provider' => 'gohighlevel', 'name' => 'A', 'status' => 'connected', 'last_synced_at' => now()->subMinutes(3)]);
        Integration::create(['provider' => 'meta_ads', 'name' => 'B', 'status' => 'connected', 'last_synced_at' => now()->subMinutes(30)]);

        $this->actingAs($admin)->getJson('/sync-status')
            ->assertOk()
            ->assertJsonPath('connected', 2)
            ->assertJsonPath('total', 2)
            ->assertJson(fn ($json) => $json->whereNot('at', null)->whereNot('human', null)->etc());
    }

    public function test_sync_all_queues_connected_integrations(): void
    {
        Queue::fake();
        $admin = $this->admin();

        Integration::create(['provider' => 'gohighlevel', 'name' => 'A', 'status' => 'connected']);
        Integration::create(['provider' => 'meta_ads', 'name' => 'B', 'status' => 'disconnected']);

        $this->actingAs($admin)->postJson('/sync-all')->assertOk();

        Queue::assertPushed(SyncIntegrationJob::class, 1);
    }
}
