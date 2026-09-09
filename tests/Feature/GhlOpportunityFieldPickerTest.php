<?php

namespace Tests\Feature;

use App\Integration\Jobs\SyncIntegrationJob;
use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use App\Integration\Providers\GoHighLevelProvider;
use App\Integration\Services\SyncService;
use App\Metric\Services\MetricService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GoHighLevel omits a custom field from an opportunity entirely when it has no
 * value, and columns used to be whatever the synced rows happened to contain —
 * so a field nobody had filled in was unpickable, and a column could vanish
 * when the last record carrying it was cleared.
 *
 * These tests pin the fix: the admin DECLARES the columns, sync emits exactly
 * those (blank ones included), and the two "no selection" states stay distinct —
 * a missing config key is legacy behaviour, an empty array is "base only".
 */
class GhlOpportunityFieldPickerTest extends TestCase
{
    use RefreshDatabase;

    /** Field ids as GHL hands them out. */
    private const CF_OUTREACH = 'vZVi0DXoQn2QRdlzQlNM';

    private const CF_LINKEDIN = 'AmPJj1JK8uQpdxKXMeP3';

    /** Defined in GHL, but no opportunity has ever carried a value for it. */
    private const CF_UNUSED = 'Nv3rF1ll3dF13ldXXXXX';

    /** A DATE field ("Email 1 TS"), which GHL delivers under a type-named key. */
    private const CF_DATE = '3nUAsRfrzScSmTITrvEn';

    private function integration(array $config = ['datasets' => ['Opportunities']]): Integration
    {
        return Integration::create([
            'provider' => 'gohighlevel',
            'name' => 'GHL',
            'status' => 'connected',
            'credentials' => ['access_token' => 'token', 'location_id' => 'loc1'],
            'config' => $config,
        ]);
    }

    private function fakeGhl(): void
    {
        $base = config('integrations.gohighlevel.api_base');

        Http::fake([
            "{$base}/users/*" => Http::response(['users' => [
                ['id' => 'DXqdiSz3iVfoHQKWinlU', 'firstName' => 'Zainab', 'lastName' => 'Makarfi', 'email' => 'z@x.com'],
            ]]),
            "{$base}/opportunities/pipelines*" => Http::response(['pipelines' => [
                ['id' => 'Z7Y8O9aeai7Xoa5mCP48', 'name' => 'Linked In Campaign Pipeline', 'stages' => [
                    ['id' => '501b84db-b916-4764-8aef-51a5d4a65fe7', 'name' => 'Replied/Connected'],
                ]],
            ]]),
            "{$base}/locations/loc1/customFields*" => Http::response(['customFields' => [
                ['id' => self::CF_OUTREACH, 'name' => 'Outreach Stages', 'dataType' => 'CHECKBOX', 'model' => 'opportunity'],
                ['id' => self::CF_LINKEDIN, 'name' => 'LinkedIn URL', 'dataType' => 'TEXT', 'model' => 'opportunity'],
                ['id' => self::CF_UNUSED, 'name' => 'Never Filled', 'dataType' => 'TEXT', 'model' => 'opportunity'],
                ['id' => self::CF_DATE, 'name' => 'Email 1 TS', 'dataType' => 'DATE', 'model' => 'opportunity'],
            ]]),
            "{$base}/opportunities/search*" => Http::response([
                'opportunities' => [[
                    'id' => 'ZyGZLQrVCSun3cqtm6mx',
                    'name' => 'Mariano Rodriguez',
                    'monetaryValue' => 0,
                    'pipelineId' => 'Z7Y8O9aeai7Xoa5mCP48',
                    'pipelineStageId' => '501b84db-b916-4764-8aef-51a5d4a65fe7',
                    'assignedTo' => 'DXqdiSz3iVfoHQKWinlU',
                    'status' => 'open',
                    'createdAt' => '2026-07-28T18:36:55.702Z',
                    'updatedAt' => '2026-08-01T00:34:40.371Z',
                    'lastStatusChangeAt' => '2026-07-30T09:12:00.000Z',
                    'effectiveProbability' => '0.35',
                    'contactId' => 'ct-1',
                    'attributions' => [['utmSessionSource' => 'linkedin', 'medium' => 'social']],
                    'contact' => [
                        'name' => 'Mariano Rodriguez',
                        'companyName' => 'LawRank',
                        'email' => 'm@lawrank.com',
                        'score' => [['score' => 88]],
                    ],
                    'customFields' => [
                        ['fieldValueString' => 'http://linkedin.com/in/mariano', 'id' => self::CF_LINKEDIN, 'type' => 'string'],
                        ['fieldValueArray' => ['1st Email', '1st Linked-IN'], 'id' => self::CF_OUTREACH, 'type' => 'array'],
                        // Not fieldValueString — GHL names the key after the type.
                        ['fieldValueDate' => '2026-09-09', 'id' => self::CF_DATE, 'type' => 'date'],
                    ],
                ]],
                'meta' => ['total' => 1],
            ]),
        ]);
    }

    /** The single synced opportunity payload. */
    private function opportunityRow(Integration $integration): array
    {
        return IntegrationRecord::where('integration_id', $integration->id)
            ->where('dataset', 'Opportunities')
            ->first()->payload;
    }

    /* ===================== the catalogue ===================== */

    public function test_sync_writes_a_field_catalogue_of_native_and_custom_fields(): void
    {
        $this->fakeGhl();

        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $catalogue = collect($integration->rows('Opportunity Fields'))->keyBy('Key');

        $this->assertSame('Never Filled', $catalogue['cf:'.self::CF_UNUSED]['Label']);
        $this->assertSame('Custom', $catalogue['cf:'.self::CF_UNUSED]['Kind']);
        $this->assertSame('TEXT', $catalogue['cf:'.self::CF_UNUSED]['Type']);

        $this->assertSame('Last Status Change', $catalogue['native:lastStatusChangeAt']['Label']);
        $this->assertSame('Native', $catalogue['native:lastStatusChangeAt']['Kind']);
        $this->assertSame('DATE', $catalogue['native:lastStatusChangeAt']['Type']);
        $this->assertArrayHasKey('native:attributions.0.utmSessionSource', $catalogue);

        // It is a derived catalogue, not an admin-selectable dataset.
        $this->assertNotContains('Opportunity Fields', GoHighLevelProvider::DATASETS);
    }

    /* ===================== declared row shape ===================== */

    public function test_a_selected_field_with_no_values_anywhere_still_becomes_a_column(): void
    {
        $this->fakeGhl();

        $integration = $this->integration([
            'datasets' => ['Opportunities'],
            'opportunity_fields' => ['cf:'.self::CF_UNUSED],
        ]);
        app(SyncService::class)->run($integration);

        $row = $this->opportunityRow($integration);

        $this->assertArrayHasKey('Never Filled', $row);
        $this->assertSame('', $row['Never Filled']);

        // And the builder dropdowns list it without waiting for a value to exist.
        $columns = $integration->provider()->schema($integration)['Opportunities'];
        $this->assertContains('Never Filled', $columns);
        $this->assertContains('Pipeline', $columns);
    }

    public function test_an_unselected_field_is_dropped_even_when_a_record_carries_a_value(): void
    {
        $this->fakeGhl();

        $integration = $this->integration([
            'datasets' => ['Opportunities'],
            'opportunity_fields' => ['cf:'.self::CF_UNUSED],
        ]);
        app(SyncService::class)->run($integration);

        $row = $this->opportunityRow($integration);

        $this->assertArrayNotHasKey('LinkedIn URL', $row);
        $this->assertArrayNotHasKey('Outreach Stages', $row);
        $this->assertNotContains('LinkedIn URL', $integration->provider()->schema($integration)['Opportunities']);
    }

    public function test_a_selected_field_keeps_its_real_value(): void
    {
        $this->fakeGhl();

        $integration = $this->integration([
            'datasets' => ['Opportunities'],
            'opportunity_fields' => ['cf:'.self::CF_OUTREACH, 'cf:'.self::CF_UNUSED],
        ]);
        app(SyncService::class)->run($integration);

        $row = $this->opportunityRow($integration);

        $this->assertSame('1st Email, 1st Linked-IN', $row['Outreach Stages']);
        $this->assertSame('', $row['Never Filled']);
    }

    public function test_native_fields_are_read_with_their_declared_cast(): void
    {
        $this->fakeGhl();

        $integration = $this->integration([
            'datasets' => ['Opportunities'],
            'opportunity_fields' => [
                'native:lastStatusChangeAt',
                'native:effectiveProbability',
                'native:attributions.0.utmSessionSource',
                'native:contact.score.0.score',
                'native:lostReasonId',
            ],
        ]);
        app(SyncService::class)->run($integration);

        $row = $this->opportunityRow($integration);

        $this->assertSame('2026-07-30', $row['Last Status Change']);
        $this->assertSame(0.35, $row['Effective Probability']);
        $this->assertSame('linkedin', $row['UTM Session Source']);
        $this->assertSame(88, $row['Contact Score']);
        // Absent from the payload, but selected — so still a key.
        $this->assertSame('', $row['Lost Reason ID']);
    }

    public function test_a_custom_field_deleted_in_ghl_is_skipped_rather_than_named_after_its_id(): void
    {
        $this->fakeGhl();

        $integration = $this->integration([
            'datasets' => ['Opportunities'],
            'opportunity_fields' => ['cf:'.self::CF_UNUSED, 'cf:gone-from-ghl'],
        ]);
        app(SyncService::class)->run($integration);

        $row = $this->opportunityRow($integration);

        $this->assertArrayHasKey('Never Filled', $row);
        $this->assertArrayNotHasKey('cf:gone-from-ghl', $row);
        $this->assertArrayNotHasKey('gone-from-ghl', $row);
    }

    /**
     * The end-to-end case a date column exists for: pick a DATE custom field,
     * sync, then count the rows whose day falls in a window.
     *
     * The value arrives under fieldValueDate rather than fieldValueString. A
     * chain that only knew the string/array keys wrote "" here, so the column
     * appeared in the builder, rendered blank, and made every date filter
     * report zero — indistinguishable from nobody having filled the field in.
     */
    public function test_a_date_custom_field_syncs_and_answers_a_date_filter(): void
    {
        Carbon::setTestNow('2026-09-09 01:54:00');

        $this->fakeGhl();

        $integration = $this->integration([
            'datasets' => ['Opportunities'],
            'opportunity_fields' => ['cf:'.self::CF_DATE],
        ]);
        app(SyncService::class)->run($integration);

        $this->assertSame('2026-09-09', $this->opportunityRow($integration)['Email 1 TS']);

        foreach (['date_today', 'date_this_week', 'date_this_month'] as $operator) {
            $this->assertSame(1.0, app(MetricService::class)->computeSimple([
                'integration_id' => $integration->id,
                'sheet' => 'Opportunities',
                'agg' => 'count_if',
                'filters' => [['column' => 'Email 1 TS', 'operator' => $operator, 'value' => '']],
            ]), $operator.' should have matched the synced row');
        }

        Carbon::setTestNow();
    }

    /** The same key tolerance on the legacy path, so unpicked fields behave too. */
    public function test_a_date_custom_field_carries_its_value_without_a_selection(): void
    {
        $this->fakeGhl();

        $integration = $this->integration(['datasets' => ['Opportunities']]);
        app(SyncService::class)->run($integration);

        $this->assertSame('2026-09-09', $this->opportunityRow($integration)['Email 1 TS']);
    }

    /* ============ the legacy / empty distinction ============ */

    public function test_no_saved_selection_keeps_the_legacy_observed_columns(): void
    {
        $this->fakeGhl();

        $integration = $this->integration(['datasets' => ['Opportunities']]);
        app(SyncService::class)->run($integration);

        $row = $this->opportunityRow($integration);

        // Whatever the records carry — exactly as before the picker existed.
        $this->assertSame('http://linkedin.com/in/mariano', $row['LinkedIn URL']);
        $this->assertSame('1st Email, 1st Linked-IN', $row['Outreach Stages']);
        $this->assertArrayNotHasKey('Never Filled', $row);

        $this->assertContains('LinkedIn URL', $integration->provider()->schema($integration)['Opportunities']);
    }

    public function test_an_empty_saved_selection_emits_base_columns_only(): void
    {
        $this->fakeGhl();

        $integration = $this->integration([
            'datasets' => ['Opportunities'],
            'opportunity_fields' => [],
        ]);
        app(SyncService::class)->run($integration);

        $row = $this->opportunityRow($integration);

        $this->assertArrayNotHasKey('LinkedIn URL', $row);
        $this->assertArrayNotHasKey('Outreach Stages', $row);
        $this->assertSame('Linked In Campaign Pipeline', $row['Pipeline']);
        $this->assertCount(14, $row);
    }

    /* ===================== the save endpoint ===================== */

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /** An integration whose catalogue has been synced, ready for the picker. */
    private function syncedIntegration(): Integration
    {
        $this->fakeGhl();

        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        return $integration;
    }

    public function test_saving_a_selection_persists_it_and_queues_a_resync(): void
    {
        $integration = $this->syncedIntegration();
        Queue::fake();

        $this->actingAs($this->admin())
            ->put("/integrations/{$integration->id}/opportunity-fields", [
                'fields' => ['cf:'.self::CF_UNUSED, 'native:contactId'],
            ])
            ->assertRedirect();

        $this->assertSame(
            ['cf:'.self::CF_UNUSED, 'native:contactId'],
            $integration->fresh()->setting('opportunity_fields')
        );

        // Columns are baked in at write time, so the selection needs a resync.
        Queue::assertPushed(SyncIntegrationJob::class);
    }

    /**
     * An all-unchecked checkbox group submits no key at all. That has to land as
     * [] — not as "nothing submitted, keep the old set", which is what the
     * generic update() action would have done.
     */
    public function test_an_empty_submission_saves_an_empty_selection(): void
    {
        $integration = $this->syncedIntegration();
        $integration->updateConfig(['opportunity_fields' => ['cf:'.self::CF_UNUSED]]);
        Queue::fake();

        $this->actingAs($this->admin())
            ->put("/integrations/{$integration->id}/opportunity-fields", [])
            ->assertRedirect();

        $config = $integration->fresh()->config;

        $this->assertSame([], $config['opportunity_fields']);
        $this->assertArrayHasKey('opportunity_fields', $config, 'an empty selection must persist, not fall back to legacy');
    }

    public function test_a_key_outside_the_catalogue_is_rejected(): void
    {
        $integration = $this->syncedIntegration();
        Queue::fake();

        $this->actingAs($this->admin())
            ->put("/integrations/{$integration->id}/opportunity-fields", [
                'fields' => ['cf:'.self::CF_UNUSED, 'native:../../etc/passwd'],
            ])
            ->assertSessionHasErrors('fields.1');

        $this->assertNull($integration->fresh()->setting('opportunity_fields'));
        Queue::assertNothingPushed();
    }

    /* ===================== the settings screen ===================== */

    public function test_the_settings_screen_renders_the_catalogue_with_the_selection_ticked(): void
    {
        $integration = $this->syncedIntegration();
        $integration->updateConfig(['opportunity_fields' => ['cf:'.self::CF_UNUSED]]);

        $this->actingAs($this->admin())
            ->get('/integrations')
            ->assertOk()
            ->assertSee('Opportunity columns')
            ->assertSee('Never Filled')
            ->assertSee('Last Status Change')
            ->assertSee('value="cf:'.self::CF_UNUSED.'"', false);
    }

    /** Never synced: a blank checkbox list would just look broken. */
    public function test_the_settings_screen_points_at_sync_when_no_catalogue_exists(): void
    {
        $integration = $this->integration();

        $this->actingAs($this->admin())
            ->get('/integrations')
            ->assertOk()
            ->assertSee('No field catalogue yet');

        $this->assertSame([], $integration->rows('Opportunity Fields'));
    }

    public function test_non_admins_cannot_save_a_selection(): void
    {
        $integration = $this->syncedIntegration();

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->put("/integrations/{$integration->id}/opportunity-fields", ['fields' => []])
            ->assertForbidden();
    }
}
