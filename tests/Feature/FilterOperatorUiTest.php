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
}
