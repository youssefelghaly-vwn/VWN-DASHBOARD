<?php

namespace Tests\Feature;

use App\Dashboard\Models\Metric;
use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use App\Metric\Services\MetricService;
use App\Models\User;
use App\Support\FilterOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Date-window filters over synced rows.
 *
 * GoHighLevel date custom fields arrive as plain strings ("2026-09-09"), so
 * before this the only way to ask "how many follow-ups land in the next 7 days"
 * was to type an exact date and edit the metric every morning. These pin the
 * window arithmetic, which is the part that silently goes wrong at month ends
 * and off-by-one boundaries.
 */
class DateRangeFilterTest extends TestCase
{
    use RefreshDatabase;

    /** A Wednesday, mid-month, so week/month boundaries are unambiguous. */
    private const TODAY = '2026-09-16 09:30:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function integration(): Integration
    {
        return Integration::create([
            'provider' => 'gohighlevel',
            'name' => 'GHL',
            'status' => 'connected',
            'credentials' => ['access_token' => 't', 'location_id' => 'loc1'],
            'config' => ['datasets' => ['Opportunities']],
        ]);
    }

    /** One Opportunities row per given "Follow Up" cell value. */
    private function rowsWithFollowUp(Integration $integration, array $values): void
    {
        foreach ($values as $i => $value) {
            IntegrationRecord::create([
                'integration_id' => $integration->id,
                'dataset' => 'Opportunities',
                'external_id' => 'o'.$i,
                'payload' => ['Contact' => 'C'.$i, 'Follow Up' => $value],
            ]);
        }
    }

    /** count_if over the Follow Up column with one date operator. */
    private function countWhere(Integration $integration, string $operator, string $value = ''): float
    {
        return app(MetricService::class)->computeSimple([
            'integration_id' => $integration->id,
            'sheet' => 'Opportunities',
            'agg' => 'count_if',
            'filters' => [['column' => 'Follow Up', 'operator' => $operator, 'value' => $value]],
        ]);
    }

    public function test_relative_windows_pick_the_right_days(): void
    {
        $integration = $this->integration();

        $this->rowsWithFollowUp($integration, [
            '2026-09-16', // today
            '2026-09-15', // yesterday
            '2026-09-10', // 6 days ago — inside a 7-day look-back
            '2026-09-09', // 7 days ago — outside it
            '2026-09-18', // in 2 days
            '2026-09-30', // this month, but outside a 7-day look-ahead
            '2026-08-31', // last month
        ]);

        $this->assertSame(1.0, $this->countWhere($integration, 'date_today'));
        $this->assertSame(1.0, $this->countWhere($integration, 'date_yesterday'));

        // "Last 7 days" is a 7-day window ENDING today: 09-10 .. 09-16.
        $this->assertSame(3.0, $this->countWhere($integration, 'date_last_n_days', '7'));

        // "Next 7 days" is a 7-day window STARTING today: 09-16 .. 09-22.
        $this->assertSame(2.0, $this->countWhere($integration, 'date_next_n_days', '7'));

        // Mon 14th – Sun 20th.
        $this->assertSame(3.0, $this->countWhere($integration, 'date_this_week'));

        $this->assertSame(6.0, $this->countWhere($integration, 'date_this_month'));
        $this->assertSame(1.0, $this->countWhere($integration, 'date_last_month'));
    }

    public function test_explicit_dates_and_ranges(): void
    {
        $integration = $this->integration();

        $this->rowsWithFollowUp($integration, [
            '2026-09-08',
            '2026-09-09',
            '2026-09-10',
            '2026-09-11',
        ]);

        $this->assertSame(1.0, $this->countWhere($integration, 'date_on', '2026-09-09'));

        // Both ends inclusive.
        $this->assertSame(3.0, $this->countWhere($integration, 'date_between', '2026-09-08..2026-09-10'));
        $this->assertSame(3.0, $this->countWhere($integration, 'date_between', '2026-09-08, 2026-09-10'));

        // Exclusive, so the named day is not swept in by "before"/"after".
        $this->assertSame(1.0, $this->countWhere($integration, 'date_before', '2026-09-09'));
        $this->assertSame(2.0, $this->countWhere($integration, 'date_after', '2026-09-09'));

        // A half-filled range still works on the side that parses.
        $this->assertSame(2.0, $this->countWhere($integration, 'date_between', '2026-09-10..'));
    }

    public function test_month_windows_survive_a_short_month(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $integration = $this->integration();
        $this->rowsWithFollowUp($integration, ['2026-02-01', '2026-02-28', '2026-03-31', '2026-01-31']);

        // Naive subMonth() on the 31st lands in March and reports zero.
        $this->assertSame(2.0, $this->countWhere($integration, 'date_last_month'));
        $this->assertSame(1.0, $this->countWhere($integration, 'date_this_month'));
    }

    /**
     * The reason cellDate() does not use Carbon::parse(): it reads "1st Email"
     * as a day of the month, which would drop multi-select cells into date
     * windows they have nothing to do with.
     */
    public function test_non_date_cells_never_match_a_date_window(): void
    {
        $integration = $this->integration();

        $this->rowsWithFollowUp($integration, [
            '1st Email, 1st Linked-IN',
            'Blank',
            'May',
            '',
            'http://linkedin.com/in/someone',
            '2026-02-31', // well-shaped but not a real day
            '2026-09-16', // the only genuine match
        ]);

        $this->assertSame(1.0, $this->countWhere($integration, 'date_this_month'));
        $this->assertSame(1.0, $this->countWhere($integration, 'date_today'));
    }

    public function test_iso_timestamps_and_epoch_milliseconds_are_read_as_days(): void
    {
        $integration = $this->integration();

        $this->rowsWithFollowUp($integration, [
            '2026-09-16T18:25:36.233Z',            // native GHL timestamp
            (string) Carbon::parse('2026-09-16 23:00:00')->getTimestampMs(),
            '09/16/2026',                          // as a human might type it
            '2026-09-17T00:00:00.000Z',
        ]);

        $this->assertSame(3.0, $this->countWhere($integration, 'date_today'));
    }

    /**
     * Every shape a date can reach a cell in. A shape the parser does not know
     * is the worst kind of bug here: the column exists, shows a date, and the
     * filter silently reports zero.
     */
    public function test_every_supported_date_shape_reads_as_the_same_day(): void
    {
        $integration = $this->integration();

        $this->rowsWithFollowUp($integration, [
            '2026-09-16',
            '2026-09-16T18:25:36.233Z',
            '2026-9-16',
            '09/16/2026',
            (string) Carbon::parse('2026-09-16 12:00:00')->getTimestampMs(),   // epoch ms
            (string) Carbon::parse('2026-09-16 12:00:00')->getTimestamp(),     // epoch seconds
            'Sep 16, 2026',
            'September 16, 2026',
            '16 Sep 2026',
            '16th September 2026',
        ]);

        $this->assertSame(10.0, $this->countWhere($integration, 'date_today'));
    }

    /** The month-name patterns must not turn ordinary text into a date. */
    public function test_month_name_parsing_does_not_swallow_ordinary_text(): void
    {
        $integration = $this->integration();

        $this->rowsWithFollowUp($integration, [
            '1st Email',
            '1st Email, 1st Linked-IN',
            'May',
            'March Madness',
            'Sept', // a month with no day and no year is not a date
            '2026-09-16',
        ]);

        $this->assertSame(1.0, $this->countWhere($integration, 'date_this_month'));
    }

    /**
     * A half-configured filter must not read as "no filter" — that would show a
     * confident full count. gt/lt already exclude everything on an unparseable
     * needle; date operators match that.
     */
    public function test_a_window_with_no_usable_bound_matches_nothing(): void
    {
        $integration = $this->integration();
        $this->rowsWithFollowUp($integration, ['2026-09-16', '2026-09-17']);

        $this->assertSame(0.0, $this->countWhere($integration, 'date_on', ''));
        $this->assertSame(0.0, $this->countWhere($integration, 'date_last_n_days', ''));
        $this->assertSame(0.0, $this->countWhere($integration, 'date_last_n_days', '0'));
        $this->assertSame(0.0, $this->countWhere($integration, 'date_between', 'nonsense'));
    }

    public function test_date_windows_and_other_conditions_are_anded(): void
    {
        $integration = $this->integration();

        foreach ([['Alice', '2026-09-16'], ['Bob', '2026-09-16'], ['Alice', '2026-08-01']] as $i => [$owner, $due]) {
            IntegrationRecord::create([
                'integration_id' => $integration->id,
                'dataset' => 'Opportunities',
                'external_id' => 'o'.$i,
                'payload' => ['Owner' => $owner, 'Follow Up' => $due],
            ]);
        }

        $count = app(MetricService::class)->computeSimple([
            'integration_id' => $integration->id,
            'sheet' => 'Opportunities',
            'agg' => 'count_if',
            'filters' => [
                ['column' => 'Owner', 'operator' => 'eq', 'value' => 'Alice'],
                ['column' => 'Follow Up', 'operator' => 'date_today', 'value' => ''],
            ],
        ]);

        $this->assertSame(1.0, $count);
    }

    /* ===================== the API surface ===================== */

    public function test_the_metric_endpoint_accepts_a_date_operator(): void
    {
        $integration = $this->integration();
        $this->rowsWithFollowUp($integration, ['2026-09-16', '2026-08-01']);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->postJson('/metrics/preview', [
                'title' => 'Due this week',
                'mode' => 'simple',
                'format' => 'number',
                'decimals' => 0,
                'integration_id' => $integration->id,
                'sheet' => 'Opportunities',
                'agg' => 'count_if',
                'filters' => [['column' => 'Follow Up', 'operator' => 'date_this_week', 'value' => '']],
            ])
            ->assertOk()
            ->assertJsonPath('value', 1)
            ->assertJsonPath('error', null);
    }

    public function test_an_unknown_operator_is_still_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // The app only renders validation failures as JSON under api/*, so this
        // one comes back the way the builder actually receives it.
        $this->actingAs($admin)
            ->post('/metrics/preview', [
                'title' => 'Bad',
                'mode' => 'simple',
                'format' => 'number',
                'decimals' => 0,
                'sheet' => 'Opportunities',
                'agg' => 'count_if',
                'filters' => [['column' => 'Follow Up', 'operator' => 'date_whenever', 'value' => '']],
            ])
            ->assertSessionHasErrors('filters.0.operator');
    }

    /** Every operator the builders offer must be one the controllers accept. */
    public function test_the_operator_catalogue_is_the_single_source_of_truth(): void
    {
        $offered = collect(FilterOperators::groups())->flatMap(fn ($ops) => array_keys($ops))->all();

        $this->assertSame(FilterOperators::keys(), $offered);
        $this->assertSame(array_keys(FilterOperators::ALL), array_keys(FilterOperators::inputKinds()));
    }

    /** A metric saved before this change keeps behaving exactly as it did. */
    public function test_existing_non_date_operators_are_untouched(): void
    {
        $integration = $this->integration();
        $this->rowsWithFollowUp($integration, ['2026-09-16', '', 'Blank']);

        $metric = new Metric([
            'integration_id' => $integration->id,
            'mode' => 'simple',
            'sheet' => 'Opportunities',
            'agg' => 'count_if',
            'filter_column' => 'Follow Up',
            'filter_operator' => 'not_empty',
            'filter_value' => '',
            'format' => 'number',
            'decimals' => 0,
            'title' => 'Has a follow up',
        ]);

        $this->assertSame(2.0, app(MetricService::class)->build($metric)['value']);
    }
}
