<?php

namespace Tests\Unit;

use App\Models\SheetSetting;
use App\Services\GhlService;
use PHPUnit\Framework\TestCase;

class SheetSettingTest extends TestCase
{
    public function test_null_selection_defaults_to_all_objects(): void
    {
        $setting = new SheetSetting(['ghl_objects' => null]);

        $this->assertSame(GhlService::SHEETS, $setting->ghlObjects());
    }

    public function test_empty_selection_defaults_to_all_objects(): void
    {
        $setting = new SheetSetting(['ghl_objects' => []]);

        $this->assertSame(GhlService::SHEETS, $setting->ghlObjects());
    }

    public function test_subset_selection_is_returned_in_canonical_order(): void
    {
        // Stored out of order; should come back in SHEETS order.
        $setting = new SheetSetting(['ghl_objects' => ['Users', 'Contacts']]);

        $this->assertSame(['Contacts', 'Users'], $setting->ghlObjects());
    }

    public function test_unknown_values_are_dropped(): void
    {
        $setting = new SheetSetting(['ghl_objects' => ['Contacts', 'Nonsense', 'Invoices']]);

        $this->assertSame(['Contacts'], $setting->ghlObjects());
    }
}
