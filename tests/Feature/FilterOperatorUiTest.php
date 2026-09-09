<?php

namespace Tests\Feature;

use App\Dashboard\Models\Dashboard;
use App\Models\User;
use App\Support\FilterOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The builders render their operator list from FilterOperators rather than from
 * hand-written markup, which is what used to let the UI and the controllers'
 * validation drift apart. This fails if a partial stops being included.
 */
class FilterOperatorUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_offers_every_operator_and_its_helpers(): void
    {
        $dashboard = Dashboard::create(['name' => 'Test']);
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get("/dashboards/{$dashboard->slug}")->assertOk();

        foreach (FilterOperators::ALL as $key => $spec) {
            $response->assertSee('value="'.$key.'"', false);
            $response->assertSee($spec['label'], false);
        }

        // Date pickers and the N-days box need the input-kind map.
        $response->assertSee('window.FILTER_OPERATOR_INPUT', false);
        $response->assertSee('window.filterInput', false);
        $response->assertSee('date_between', false);
    }

    /** The value box suggests what a column really holds, at every call site. */
    public function test_the_value_box_offers_the_columns_real_values(): void
    {
        $dashboard = Dashboard::create(['name' => 'Test']);
        $admin = User::factory()->create(['is_admin' => true]);

        $body = $this->actingAs($admin)->get("/dashboards/{$dashboard->slug}")->assertOk()->getContent();

        $this->assertStringContainsString('valueOptions(builder.key, cond.column)', $body);
        $this->assertStringContainsString('valueOptions(metric.simple.key, metric.simple.filter_column)', $body);
        // One <datalist> per call site: the chart builder's conditions, plus a
        // single filter and an extra-conditions row inside each of the two
        // metric-source instances (the simple metric and a formula variable).
        $this->assertSame(5, substr_count($body, '<datalist'));
        // Ids are generated per row, so x-for rows cannot share one list.
        $this->assertStringContainsString('x-id="[\'filter-value\']"', $body);
        $this->assertStringNotContainsString('<datalist id="', $body);
    }
}
