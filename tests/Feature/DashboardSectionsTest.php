<?php

namespace Tests\Feature;

use App\Dashboard\Models\Chart;
use App\Dashboard\Models\Dashboard;
use App\Dashboard\Models\Metric;
use App\Dashboard\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sections group + nest widgets; the layout endpoint persists order and section
 * assignment; chart width/height and section_id round-trip through the builder.
 */
class DashboardSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function dashboard(): Dashboard
    {
        return Dashboard::create(['name' => 'Exec']);
    }

    public function test_sections_and_subsections_are_created_and_listed(): void
    {
        $admin = $this->admin();
        $dash = $this->dashboard();

        $top = $this->actingAs($admin)->postJson("/dashboards/{$dash->id}/sections", ['title' => 'General']);
        $top->assertCreated();
        $topId = collect($top->json())->firstWhere('title', 'General')['id'];

        $this->actingAs($admin)->postJson("/dashboards/{$dash->id}/sections", ['title' => 'General 2', 'parent_id' => $topId])
            ->assertCreated();

        $list = $this->actingAs($admin)->getJson("/dashboards/{$dash->slug}/sections");
        $list->assertOk()->assertJsonCount(2);

        $sub = collect($list->json())->firstWhere('title', 'General 2');
        $this->assertSame($topId, $sub['parent_id']);
    }

    public function test_rename_does_not_reparent_a_subsection(): void
    {
        $admin = $this->admin();
        $dash = $this->dashboard();
        $top = Section::create(['dashboard_id' => $dash->id, 'title' => 'SDR', 'position' => 0]);
        $sub = Section::create(['dashboard_id' => $dash->id, 'parent_id' => $top->id, 'title' => 'SDR 1', 'position' => 0]);

        $this->actingAs($admin)->putJson("/sections/{$sub->id}", ['title' => 'SDR One'])->assertOk();

        $sub->refresh();
        $this->assertSame('SDR One', $sub->title);
        $this->assertSame($top->id, $sub->parent_id);   // parent preserved
    }

    public function test_deleting_a_section_frees_its_widgets(): void
    {
        $admin = $this->admin();
        $dash = $this->dashboard();
        $section = Section::create(['dashboard_id' => $dash->id, 'title' => 'General', 'position' => 0]);

        $metric = Metric::create([
            'dashboard_id' => $dash->id, 'user_id' => $admin->id, 'section_id' => $section->id, 'title' => 'Leads',
            'mode' => 'simple', 'format' => 'number', 'decimals' => 0,
        ]);

        $this->actingAs($admin)->deleteJson("/sections/{$section->id}")->assertOk();

        $this->assertDatabaseMissing('dashboard_sections', ['id' => $section->id]);
        $this->assertNull($metric->fresh()->section_id);   // freed, not deleted
        $this->assertNotNull($metric->fresh());
    }

    public function test_layout_endpoint_persists_order_and_section_assignment(): void
    {
        $admin = $this->admin();
        $dash = $this->dashboard();
        $section = Section::create(['dashboard_id' => $dash->id, 'title' => 'SDR', 'position' => 0]);

        $m1 = Metric::create(['dashboard_id' => $dash->id, 'user_id' => $admin->id, 'title' => 'A', 'mode' => 'simple', 'format' => 'number', 'decimals' => 0, 'position' => 0]);
        $m2 = Metric::create(['dashboard_id' => $dash->id, 'user_id' => $admin->id, 'title' => 'B', 'mode' => 'simple', 'format' => 'number', 'decimals' => 0, 'position' => 1]);

        $this->actingAs($admin)->postJson("/dashboards/{$dash->id}/layout", [
            'sections' => [['id' => $section->id, 'parent_id' => null, 'position' => 5]],
            'metrics' => [
                ['id' => $m1->id, 'section_id' => $section->id, 'position' => 1],
                ['id' => $m2->id, 'section_id' => null, 'position' => 0],
            ],
            'charts' => [],
        ])->assertNoContent();

        $this->assertSame($section->id, $m1->fresh()->section_id);
        $this->assertSame(1, $m1->fresh()->position);
        $this->assertNull($m2->fresh()->section_id);
        $this->assertSame(5, $section->fresh()->position);
    }

    public function test_layout_endpoint_ignores_ids_from_other_dashboards(): void
    {
        $admin = $this->admin();
        $dash = $this->dashboard();
        $other = Dashboard::create(['name' => 'Other']);
        $foreignMetric = Metric::create(['dashboard_id' => $other->id, 'user_id' => $admin->id, 'title' => 'X', 'mode' => 'simple', 'format' => 'number', 'decimals' => 0, 'position' => 0]);

        $this->actingAs($admin)->postJson("/dashboards/{$dash->id}/layout", [
            'metrics' => [['id' => $foreignMetric->id, 'section_id' => null, 'position' => 9]],
        ])->assertNoContent();

        // Untouched — position stays 0.
        $this->assertSame(0, $foreignMetric->fresh()->position);
    }

    public function test_chart_stores_width_height_and_section(): void
    {
        $admin = $this->admin();
        $dash = $this->dashboard();
        $section = Section::create(['dashboard_id' => $dash->id, 'title' => 'General', 'position' => 0]);

        $res = $this->actingAs($admin)->postJson("/dashboards/{$dash->id}/charts", [
            'title' => 'By Stage', 'type' => 'bar', 'section_id' => $section->id,
            'sheet' => 'Opportunities', 'label_column' => 'Stage', 'aggregate' => 'count', 'limit' => 10,
            'width' => 'half', 'height' => 320,
            'series' => [['sheet' => 'Opportunities', 'agg' => 'count', 'label' => 'Count']],
        ]);

        $res->assertCreated()
            ->assertJsonPath('width', 'half')
            ->assertJsonPath('height', 320)
            ->assertJsonPath('section_id', $section->id);

        $this->assertDatabaseHas('charts', ['title' => 'By Stage', 'width' => 'half', 'height' => 320, 'section_id' => $section->id]);
    }
}
