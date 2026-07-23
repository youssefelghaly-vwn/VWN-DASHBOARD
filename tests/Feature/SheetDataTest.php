<?php

namespace Tests\Feature;

use App\Integration\Models\Integration;
use App\Integration\Models\IntegrationRecord;
use App\Sheet\Models\Sheet;
use App\Sheet\Services\SheetData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Sheets workspace is read-only, but its one server-side data operation —
 * resolving VLOOKUP columns by joining a second dataset — must be correct.
 */
class SheetDataTest extends TestCase
{
    use RefreshDatabase;

    private function integration(string $name): Integration
    {
        return Integration::create([
            'provider' => 'google_sheets',
            'name' => $name,
            'status' => 'connected',
            'credentials' => [],
        ]);
    }

    private function seedRows(Integration $i, string $dataset, array $rows): void
    {
        foreach ($rows as $payload) {
            IntegrationRecord::create([
                'integration_id' => $i->id,
                'dataset' => $dataset,
                'payload' => $payload,
            ]);
        }
    }

    public function test_it_returns_base_rows_and_columns_with_no_lookups(): void
    {
        $i = $this->integration('Sheets');
        $this->seedRows($i, 'Leads', [
            ['Email' => 'a@x.com', 'Status' => 'Booked'],
            ['Email' => 'b@x.com', 'Status' => 'Spam'],
        ]);

        $sheet = Sheet::create(['name' => 'S', 'integration_id' => $i->id, 'dataset' => 'Leads', 'config' => []]);

        $payload = app(SheetData::class)->payload($sheet);

        $this->assertCount(2, $payload['rows']);
        $this->assertEqualsCanonicalizing(['Email', 'Status'], $payload['columns']);
        $this->assertSame([], $payload['lookups']);
    }

    public function test_it_resolves_a_vlookup_column_from_another_dataset(): void
    {
        $leads = $this->integration('Leads source');
        $users = $this->integration('Users source');

        $this->seedRows($leads, 'Leads', [
            ['Email' => 'a@x.com', 'Owner' => 'u1'],
            ['Email' => 'b@x.com', 'Owner' => 'u2'],
            ['Email' => 'c@x.com', 'Owner' => 'ghost'], // no match → blank
        ]);
        $this->seedRows($users, 'Users', [
            ['UserId' => 'u1', 'Name' => 'Alice'],
            ['UserId' => 'u2', 'Name' => 'Bob'],
        ]);

        $sheet = Sheet::create([
            'name' => 'S',
            'integration_id' => $leads->id,
            'dataset' => 'Leads',
            'config' => [
                'lookups' => [[
                    'name' => 'Owner Name',
                    'integration_id' => $users->id,
                    'dataset' => 'Users',
                    'local_key' => 'Owner',
                    'foreign_key' => 'UserId',
                    'return_column' => 'Name',
                ]],
            ],
        ]);

        $payload = app(SheetData::class)->payload($sheet);

        $this->assertContains('Owner Name', $payload['columns']);
        $this->assertSame(['Owner Name'], $payload['lookups']);
        $this->assertSame('Alice', $payload['rows'][0]['Owner Name']);
        $this->assertSame('Bob', $payload['rows'][1]['Owner Name']);
        $this->assertSame('', $payload['rows'][2]['Owner Name']); // unmatched key → empty
    }

    public function test_it_skips_half_configured_lookups(): void
    {
        $i = $this->integration('Leads');
        $this->seedRows($i, 'Leads', [['Email' => 'a@x.com', 'Owner' => 'u1']]);

        $sheet = Sheet::create([
            'name' => 'S',
            'integration_id' => $i->id,
            'dataset' => 'Leads',
            'config' => ['lookups' => [['name' => 'Broken', 'local_key' => 'Owner']]], // missing foreign_key/return_column
        ]);

        $payload = app(SheetData::class)->payload($sheet);

        $this->assertSame([], $payload['lookups']);
        $this->assertArrayNotHasKey('Broken', $payload['rows'][0]);
    }
}
