<?php

namespace App\Services;

use App\Contracts\DataSource;
use App\Models\SheetSetting;

/**
 * Resolves which DataSource is active. This is the seam that lets us keep
 * Google Sheets fully intact while adding GHL: controllers ask the manager
 * for "the source", and get whichever one the admin has selected.
 */
class SourceManager
{
    public function __construct(
        private GoogleSheetService $sheets,
        private GhlService $ghl,
    ) {}

    public function active(): DataSource
    {
        return match (SheetSetting::current()?->source) {
            'ghl'   => $this->ghl,
            default => $this->sheets,
        };
    }

    public function sheets(): GoogleSheetService
    {
        return $this->sheets;
    }

    public function ghl(): GhlService
    {
        return $this->ghl;
    }

    public function activeKey(): string
    {
        return SheetSetting::current()?->source ?? 'sheets';
    }
}