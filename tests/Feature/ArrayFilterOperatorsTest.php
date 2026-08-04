<?php

namespace Tests\Feature;

use App\Dashboard\Models\Metric;
use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use App\Metric\Services\MetricService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * has_all / has_any / not_has_any match comma-separated multi-value cells
 * (e.g. Outreach Stages) token-by-token, order-independent.
 */
class ArrayFilterOperatorsTest extends TestCase
{
    use RefreshDatabase;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->integration = Integration::create([
            'provider' => 'gohighlevel', 'name' => 'GHL', 'status' => 'connected',
        ]);

        // 3 rows with different Outreach Stages combinations.
        $stages = [
            '1st Email, 1st Linked-IN',
            '1st Linked-IN, 1st Email, 1st Call',   // superset, different order
            '1st Call, 1st SMS',
        ];

        foreach ($stages as $i => $s) {
            IntegrationRecord::create([
                'integration_id' => $this->integration->id,
                'dataset' => 'Opportunities',
                'external_id' => (string) $i,
                'payload' => ['Outreach Stages' => $s],
            ]);
        }
    }

    private function countWith(string $operator, string $value): float
    {
        $metric = new Metric([
            'title' => 'X', 'mode' => 'simple', 'integration_id' => $this->integration->id,
            'sheet' => 'Opportunities', 'agg' => 'count_if',
            'filter_column' => 'Outreach Stages', 'filter_operator' => $operator, 'filter_value' => $value,
            'format' => 'number', 'decimals' => 0,
        ]);

        return app(MetricService::class)->build($metric)['value'];
    }

    public function test_has_all_requires_every_token(): void
    {
        // Rows 0 and 1 both contain BOTH "1st Email" and "1st Linked-IN".
        $this->assertSame(2.0, $this->countWith('has_all', '1st Email, 1st Linked-IN'));

        // Only row 1 has all three.
        $this->assertSame(1.0, $this->countWith('has_all', '1st Email, 1st Linked-IN, 1st Call'));
    }

    public function test_has_any_matches_at_least_one_token(): void
    {
        // "1st Call" appears in rows 1 and 2.
        $this->assertSame(2.0, $this->countWith('has_any', '1st Call'));

        // "1st Email" OR "1st SMS" → rows 0, 1 (email) and 2 (sms) = all three.
        $this->assertSame(3.0, $this->countWith('has_any', '1st Email, 1st SMS'));
    }

    public function test_not_has_any_is_the_inverse(): void
    {
        // Rows WITHOUT "1st Call" → only row 0.
        $this->assertSame(1.0, $this->countWith('not_has_any', '1st Call'));
    }
}
