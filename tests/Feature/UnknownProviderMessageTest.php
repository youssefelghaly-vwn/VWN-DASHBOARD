<?php

namespace Tests\Feature;

use App\Integration\Models\Integration;
use App\Integration\Providers\GoHighLevelProvider;
use App\Integration\Services\IntegrationManager;
use App\Integration\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A stored integration whose provider this process does not know is nearly
 * always a deploy artefact, not a typo: a queue worker holds the provider map
 * in memory from boot, so a newly registered provider connects fine in the web
 * process and then fails only when a queued sync runs. The message has to say
 * that, or it reads as "the code is missing" against code that plainly has it.
 */
class UnknownProviderMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_error_names_what_this_process_knows_and_why(): void
    {
        config(['integrations.providers' => ['gohighlevel' => GoHighLevelProvider::class]]);
        $this->app->forgetInstance(IntegrationManager::class);

        try {
            app(IntegrationManager::class)->get('cloudtalk');
            $this->fail('Expected an unknown-provider exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cloudtalk', $e->getMessage());
            $this->assertStringContainsString('gohighlevel', $e->getMessage());
            $this->assertStringContainsString('restart the queue workers', $e->getMessage());
        }
    }

    /** The message has to survive into the sync run, which is where it is read. */
    public function test_a_sync_of_an_unknown_provider_records_the_guidance(): void
    {
        $integration = Integration::create([
            'provider' => 'cloudtalk',
            'name' => 'CloudTalk',
            'status' => 'connected',
            'credentials' => ['access_key_id' => 'k', 'access_key_secret' => 's'],
        ]);

        config(['integrations.providers' => []]);
        $this->app->forgetInstance(IntegrationManager::class);

        $run = app(SyncService::class)->run($integration);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('restart the queue workers', $run->last_error);
    }
}
