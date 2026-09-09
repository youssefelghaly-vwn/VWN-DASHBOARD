<?php

namespace Tests\Feature;

use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use App\Metric\Services\MetricService;
use App\Models\User;
use App\Support\FilterOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Boolean columns reach the dashboard spelled several ways: "Yes"/"No" from a
 * provider's own ternary (CloudTalk's Answered, GoHighLevel's Deleted),
 * "true"/"false" from CastsValues::str() on a real bool, and 1/0 from a
 * spreadsheet. Asking `equals true` of a column holding "Yes" quietly returns
 * zero, so these operators ask the question instead.
 */
class BooleanFilterOperatorTest extends TestCase
{
    use RefreshDatabase;

    private function integration(): Integration
    {
        return Integration::create([
            'provider' => 'cloudtalk',
            'name' => 'CloudTalk',
            'status' => 'connected',
            'credentials' => ['access_key_id' => 'k', 'access_key_secret' => 's'],
            'config' => ['datasets' => ['Calls']],
        ]);
    }

    /** One Calls row per given payload. */
    private function rows(Integration $integration, array $payloads): void
    {
        foreach ($payloads as $i => $payload) {
            IntegrationRecord::create([
                'integration_id' => $integration->id,
                'dataset' => 'Calls',
                'external_id' => 'c'.$i,
                'payload' => $payload,
            ]);
        }
    }

    private function countWhere(Integration $integration, string $column, string $operator): float
    {
        return app(MetricService::class)->computeSimple([
            'integration_id' => $integration->id,
            'sheet' => 'Calls',
            'agg' => 'count_if',
            'filters' => [['column' => $column, 'operator' => $operator, 'value' => '']],
        ]);
    }

    /** The reported case: CloudTalk writes Yes/No, and `equals true` finds nothing. */
    public function test_it_matches_the_yes_no_spelling_a_provider_writes(): void
    {
        $integration = $this->integration();
        $this->rows($integration, [
            ['Agent' => 'Nada Ahmed', 'Answered' => 'Yes'],
            ['Agent' => 'Nada Ahmed', 'Answered' => 'Yes'],
            ['Agent' => 'Nada Ahmed', 'Answered' => 'No'],
        ]);

        $this->assertSame(2.0, $this->countWhere($integration, 'Answered', 'is_true'));
        $this->assertSame(1.0, $this->countWhere($integration, 'Answered', 'is_false'));

        // What the admin tried first, and why it looked broken.
        $this->assertSame(0.0, app(MetricService::class)->computeSimple([
            'integration_id' => $integration->id,
            'sheet' => 'Calls',
            'agg' => 'count_if',
            'filters' => [['column' => 'Answered', 'operator' => 'eq', 'value' => 'true']],
        ]));
    }

    public function test_it_matches_every_spelling_a_source_might_use(): void
    {
        $integration = $this->integration();
        $this->rows($integration, [
            ['Flag' => 'Yes'], ['Flag' => 'true'], ['Flag' => 'TRUE'], ['Flag' => 'y'],
            ['Flag' => 'on'], ['Flag' => 1], ['Flag' => '1'], ['Flag' => true],
            ['Flag' => 'No'], ['Flag' => 'false'], ['Flag' => 'n'], ['Flag' => 'off'],
            ['Flag' => 0], ['Flag' => '0'], ['Flag' => false],
        ]);

        $this->assertSame(8.0, $this->countWhere($integration, 'Flag', 'is_true'));
        $this->assertSame(7.0, $this->countWhere($integration, 'Flag', 'is_false'));
    }

    /**
     * A real boolean false is the trap: (string) false is '', so anything that
     * stringifies before testing reads it as an empty cell. Google Sheets
     * writes its rows through untouched, so a TRUE/FALSE column really does
     * land real booleans in a payload.
     *
     * truthiness() therefore tests the raw value before any cast. The existing
     * `empty` operator still counts a false as empty — left alone on purpose,
     * since changing it would silently move the numbers on dashboards that
     * already filter that way. Pinned here so the quirk is a decision on
     * record rather than a surprise.
     */
    public function test_a_real_boolean_false_reads_as_false(): void
    {
        $integration = $this->integration();
        $this->rows($integration, [['Flag' => false], ['Flag' => '']]);

        $this->assertSame(1.0, $this->countWhere($integration, 'Flag', 'is_false'));
        $this->assertSame(0.0, $this->countWhere($integration, 'Flag', 'is_true'));

        // Pre-existing, unchanged: the string cast makes false look empty.
        $this->assertSame(2.0, $this->countWhere($integration, 'Flag', 'empty'));
    }

    /**
     * The two are NOT complements. CloudTalk's Answered has a third state for a
     * call the record could not classify, and it must not be counted as a miss
     * just because someone asked "is false".
     */
    public function test_a_value_that_states_no_boolean_matches_neither(): void
    {
        $integration = $this->integration();
        $this->rows($integration, [
            ['Answered' => 'Yes'],
            ['Answered' => 'No'],
            ['Answered' => 'Unknown'],
            ['Answered' => ''],
            ['Answered' => 'Nada Ahmed'],
        ]);

        $this->assertSame(1.0, $this->countWhere($integration, 'Answered', 'is_true'));
        $this->assertSame(1.0, $this->countWhere($integration, 'Answered', 'is_false'));
    }

    /** `is true` must not degrade into "any non-empty cell". */
    public function test_free_text_is_never_true(): void
    {
        $integration = $this->integration();
        $this->rows($integration, [['Agent' => 'Nada Ahmed'], ['Agent' => 'Shevin Pollydore']]);

        $this->assertSame(0.0, $this->countWhere($integration, 'Agent', 'is_true'));
        $this->assertSame(0.0, $this->countWhere($integration, 'Agent', 'is_false'));
    }

    /** A missing column is not false — it is a question with no answer. */
    public function test_a_missing_column_matches_neither(): void
    {
        $integration = $this->integration();
        $this->rows($integration, [['Agent' => 'Nada Ahmed']]);

        $this->assertSame(0.0, $this->countWhere($integration, 'Answered', 'is_true'));
        $this->assertSame(0.0, $this->countWhere($integration, 'Answered', 'is_false'));
    }

    /** Non-zero counts read as true, so "had any calls" works without a gt 0. */
    public function test_numbers_follow_the_zero_convention(): void
    {
        $integration = $this->integration();
        $this->rows($integration, [['Calls' => 3], ['Calls' => 1], ['Calls' => 0], ['Calls' => 2.5]]);

        $this->assertSame(3.0, $this->countWhere($integration, 'Calls', 'is_true'));
        $this->assertSame(1.0, $this->countWhere($integration, 'Calls', 'is_false'));
    }

    public function test_the_operators_are_offered_and_accepted(): void
    {
        $this->assertContains('is_true', FilterOperators::keys());
        $this->assertContains('is_false', FilterOperators::keys());
        $this->assertSame('none', FilterOperators::inputKinds()['is_true']);

        $admin = User::factory()->create(['is_admin' => true]);
        $integration = $this->integration();
        $this->rows($integration, [['Answered' => 'Yes'], ['Answered' => 'No']]);

        $this->actingAs($admin)
            ->postJson('/metrics/preview', [
                'title' => 'Answered calls',
                'mode' => 'simple',
                'format' => 'number',
                'decimals' => 0,
                'integration_id' => $integration->id,
                'sheet' => 'Calls',
                'agg' => 'count_if',
                'filters' => [['column' => 'Answered', 'operator' => 'is_true', 'value' => '']],
            ])
            ->assertOk()
            ->assertJsonPath('value', 1)
            ->assertJsonPath('error', null);
    }
}
