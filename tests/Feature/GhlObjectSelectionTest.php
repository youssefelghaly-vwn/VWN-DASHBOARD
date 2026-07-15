<?php

namespace Tests\Feature;

use App\Models\GhlConnection;
use App\Models\SheetSetting;
use App\Models\User;
use App\Services\Ghl\GhlClient;
use App\Services\GhlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A GhlClient stand-in that records every path requested and returns canned
 * shapes, so we can assert WHICH endpoints the object selection actually hits
 * — without any real HTTP.
 */
class RecordingGhlClient extends GhlClient
{
    public array $calls = [];

    public function get(GhlConnection $connection, string $path, array $query = []): array
    {
        $this->calls[] = $path;

        return match (true) {
            str_contains($path, '/opportunities/pipelines') => ['pipelines' => []],
            str_contains($path, '/customFields')            => ['customFields' => []],
            str_contains($path, '/calendars/')              => ['calendars' => []],
            default                                         => [],
        };
    }

    public function paginate(GhlConnection $connection, string $path, string $collectionKey, array $query = [], array $opts = []): array
    {
        $this->calls[] = $path;

        return match ($collectionKey) {
            'users'    => [['id' => 'u1', 'firstName' => 'Ann', 'lastName' => 'Lee', 'email' => 'a@x.com']],
            'contacts' => [
                ['firstName' => 'Bob', 'email' => 'b@x.com', 'customFields' => []],
                ['firstName' => 'Cy',  'email' => 'c@x.com', 'customFields' => []],
            ],
            default => [],
        };
    }
}

class GhlObjectSelectionTest extends TestCase
{
    use RefreshDatabase;

    private RecordingGhlClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new RecordingGhlClient();
        $this->app->instance(GhlClient::class, $this->client);

        GhlConnection::create([
            'location_id'   => 'loc123',
            'access_token'  => 'pit-token-value-1234567890',
            'location_name' => 'Test Location',
        ]);
    }

    private function useSelection(array $objects): void
    {
        SheetSetting::create([
            'source'      => 'ghl',
            'cache_ttl'   => 60,
            'ghl_objects' => $objects,
        ]);
    }

    public function test_only_selected_objects_are_returned(): void
    {
        $this->useSelection(['Contacts', 'Users']);

        $ghl  = $this->app->make(GhlService::class);
        $data = $ghl->all(fresh: true);

        $sheets = array_values(array_diff(array_keys($data), ['_meta', '_errors']));

        $this->assertSame(['Contacts', 'Users'], $sheets);
        $this->assertCount(2, $data['Contacts']);
        $this->assertSame(['Contacts', 'Users'], array_keys($ghl->schema()));
    }

    public function test_deselected_appointments_skips_the_calendar_sweep(): void
    {
        $this->useSelection(['Contacts', 'Users']);

        $this->app->make(GhlService::class)->all(fresh: true);

        // The expensive calendar endpoints are never touched.
        $this->assertNotContains('/calendars/', $this->client->calls);
        $this->assertNotContains('/calendars/events', $this->client->calls);
        // Opportunities-only prerequisites are skipped too.
        $this->assertNotContains('/opportunities/pipelines', $this->client->calls);
        $this->assertNotContains('/opportunities/search', $this->client->calls);
        // Users ARE fetched — Contacts needs the id→name map.
        $this->assertContains('/users/', $this->client->calls);
    }

    public function test_selecting_appointments_triggers_calendar_sweep(): void
    {
        $this->useSelection(['Appointments']);

        $this->app->make(GhlService::class)->all(fresh: true);

        $this->assertContains('/calendars/', $this->client->calls);
        $this->assertContains('/users/', $this->client->calls); // needed for assigned-user names
        $this->assertNotContains('/opportunities/pipelines', $this->client->calls);
        $this->assertNotContains('/contacts/', $this->client->calls);
    }

    public function test_meta_records_timing_and_counts_per_object(): void
    {
        $this->useSelection(['Contacts', 'Users']);

        $ghl  = $this->app->make(GhlService::class);
        $meta = $ghl->all(fresh: true)['_meta'];

        $this->assertSame(['Contacts', 'Users'], $meta['selected']);
        $this->assertArrayHasKey('Contacts', $meta['resources']);
        $this->assertArrayHasKey('Users', $meta['resources']);
        $this->assertSame(2, $meta['resources']['Contacts']['count']);
        $this->assertTrue($meta['resources']['Contacts']['ok']);
        $this->assertArrayHasKey('ms', $meta['resources']['Contacts']);

        // lastMeta() reads the durable copy without triggering another fetch.
        $this->assertSame($meta['selected'], $ghl->lastMeta()['selected']);
    }

    public function test_settings_update_persists_object_selection(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->put('/admin/settings', [
            'source'      => 'ghl',
            'cache_ttl'   => 120,
            'ghl_objects' => ['Users', 'Contacts'],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(['Contacts', 'Users'], SheetSetting::current()->ghlObjects());
        $this->assertSame(120, SheetSetting::current()->cache_ttl);
    }

    public function test_settings_update_requires_at_least_one_object(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->put('/admin/settings', [
            'source'    => 'ghl',
            'cache_ttl' => 120,
        ]);

        $response->assertSessionHasErrors('ghl_objects');
    }
}
