<?php

namespace Tests\Feature;

use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use App\Models\User;
use App\Sheet\Models\Sheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SheetControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_a_sheet_can_be_created_and_read_back_with_its_rows(): void
    {
        $admin = $this->admin();

        $integration = Integration::create([
            'provider' => 'google_sheets', 'name' => 'GS', 'status' => 'connected', 'credentials' => [],
        ]);
        IntegrationRecord::create([
            'integration_id' => $integration->id, 'dataset' => 'Leads',
            'payload' => ['Email' => 'a@x.com', 'Status' => 'Booked'],
        ]);

        $create = $this->actingAs($admin)->postJson('/sheets', [
            'name' => 'My sheet', 'integration_id' => $integration->id, 'dataset' => 'Leads',
        ]);

        $create->assertCreated()->assertJsonPath('name', 'My sheet');
        $id = $create->json('id');

        $this->assertDatabaseHas('sheets', ['id' => $id, 'name' => 'My sheet', 'dataset' => 'Leads']);

        $data = $this->actingAs($admin)->getJson("/sheets/{$id}/data");
        $data->assertOk()
            ->assertJsonPath('rows.0.Email', 'a@x.com')
            ->assertJsonCount(1, 'rows');
    }

    public function test_config_is_persisted_on_update(): void
    {
        $admin = $this->admin();
        $sheet = Sheet::create(['name' => 'S', 'integration_id' => null, 'dataset' => 'Leads', 'config' => []]);

        $config = ['group' => 'Status', 'totals' => true, 'conditions' => [['column' => 'Status', 'operator' => 'eq', 'value' => 'Booked']]];

        $this->actingAs($admin)->putJson("/sheets/{$sheet->id}", ['config' => $config])
            ->assertOk()
            ->assertJsonPath('config.group', 'Status');

        $this->assertSame('Status', $sheet->fresh()->config['group']);
    }

    public function test_a_sheet_can_be_deleted(): void
    {
        $admin = $this->admin();
        $sheet = Sheet::create(['name' => 'S', 'integration_id' => null, 'dataset' => 'Leads', 'config' => []]);

        $this->actingAs($admin)->deleteJson("/sheets/{$sheet->id}")->assertNoContent();
        $this->assertDatabaseMissing('sheets', ['id' => $sheet->id]);
    }

    public function test_non_admins_are_blocked(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->getJson('/sheets')->assertForbidden();
    }
}
