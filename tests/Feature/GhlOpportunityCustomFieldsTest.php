<?php

namespace Tests\Feature;

use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use App\Integration\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Opportunities must carry their Owner (assignee) AND their custom fields as
 * real, filterable columns — the multi-select ones (e.g. "Outreach Stages")
 * flattened to a comma-separated string so has_all/has_any can match them.
 */
class GhlOpportunityCustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function integration(): Integration
    {
        return Integration::create([
            'provider' => 'gohighlevel',
            'name' => 'GHL',
            'status' => 'connected',
            'credentials' => ['access_token' => 'token', 'location_id' => 'loc1'],
            'config' => ['datasets' => ['Opportunities']],
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
                ['id' => 'vZVi0DXoQn2QRdlzQlNM', 'name' => 'Outreach Stages', 'model' => 'opportunity'],
                ['id' => 'AmPJj1JK8uQpdxKXMeP3', 'name' => 'LinkedIn URL', 'model' => 'opportunity'],
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
                    'contact' => ['name' => 'Mariano Rodriguez', 'companyName' => 'LawRank', 'email' => 'm@lawrank.com'],
                    'customFields' => [
                        ['fieldValueString' => 'http://linkedin.com/in/mariano', 'id' => 'AmPJj1JK8uQpdxKXMeP3', 'type' => 'string'],
                        ['fieldValueArray' => ['1st Email', '1st Linked-IN'], 'id' => 'vZVi0DXoQn2QRdlzQlNM', 'type' => 'array'],
                    ],
                ]],
                'meta' => ['total' => 1],
            ]),
        ]);
    }

    public function test_owner_and_custom_fields_become_columns(): void
    {
        $this->fakeGhl();

        $integration = $this->integration();
        app(SyncService::class)->run($integration);

        $row = IntegrationRecord::where('integration_id', $integration->id)
            ->where('dataset', 'Opportunities')
            ->first()->payload;

        $this->assertSame('Zainab Makarfi', $row['Owner']);
        $this->assertSame('Zainab Makarfi', $row['Assigned User']);
        $this->assertSame('LawRank', $row['Company']);
        $this->assertSame('Replied/Connected', $row['Stage']);
        $this->assertArrayHasKey('Outreach Stages', $row);
        $this->assertSame('1st Email, 1st Linked-IN', $row['Outreach Stages']);
        $this->assertSame('http://linkedin.com/in/mariano', $row['LinkedIn URL']);
    }
}
