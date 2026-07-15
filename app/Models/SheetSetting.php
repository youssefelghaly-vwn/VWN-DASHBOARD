<?php

namespace App\Models;

use App\Services\GhlService;
use Illuminate\Database\Eloquent\Model;

class SheetSetting extends Model
{
    protected $fillable = [
        'source', 'endpoint_url', 'sheet_names', 'ghl_objects', 'cache_ttl', 'last_synced_at',
    ];

    protected $casts = [
        'sheet_names'    => 'array',
        'ghl_objects'    => 'array',
        'last_synced_at' => 'datetime',
    ];

    protected $attributes = [
        'source' => 'sheets',
    ];

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }

    /**
     * Which GoHighLevel objects the dashboard should pull, in the canonical
     * order defined by GhlService::SHEETS. A null/empty selection means "all"
     * so existing installs keep pulling everything until they narrow it. Any
     * stored value that isn't a real GHL object is dropped.
     */
    public function ghlObjects(): array
    {
        $stored = collect($this->ghl_objects ?? [])
            ->intersect(GhlService::SHEETS)
            ->values();

        $selected = $stored->isEmpty() ? collect(GhlService::SHEETS) : $stored;

        // Return in canonical SHEETS order regardless of how it was stored.
        return collect(GhlService::SHEETS)
            ->filter(fn ($sheet) => $selected->contains($sheet))
            ->values()
            ->all();
    }
}