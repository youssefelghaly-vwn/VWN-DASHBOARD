<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SheetSetting;
use App\Services\SourceManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    public function edit()
    {
        return view('admin.settings', [
            'setting' => SheetSetting::current(),
            'script'  => file_get_contents(resource_path('stubs/apps-script.js')),
        ]);
    }

    public function update(Request $request, SourceManager $sources)
    {
        $source = $request->input('source', 'sheets');

        // Sheets requires an endpoint + tabs; GHL requires neither (it's OAuth).
        $rules = [
            'source'    => ['required', 'in:sheets,ghl'],
            'cache_ttl' => ['required', 'integer', 'min:0', 'max:3600'],
        ];

        if ($source === 'sheets') {
            $rules['endpoint_url'] = ['required', 'url', 'starts_with:https://script.google.com/'];
            $rules['sheet_names']  = ['required', 'string'];
        }

        $data = $request->validate($rules);

        $names = $source === 'sheets'
            ? collect(explode(',', $data['sheet_names']))->map(fn ($n) => trim($n))->filter()->unique()->values()->all()
            : (SheetSetting::current()?->sheet_names ?? []);

        if ($source === 'sheets' && empty($names)) {
            return back()->withErrors(['sheet_names' => 'At least one sheet name is required.']);
        }

        Cache::flush();

        // Keep a single settings row; update in place so Sheets config survives
        // even while GHL is the active source (and vice versa).
        $setting = SheetSetting::current() ?? new SheetSetting();
        $setting->fill([
            'source'    => $source,
            'cache_ttl' => $data['cache_ttl'],
        ]);

        if ($source === 'sheets') {
            $setting->endpoint_url = $data['endpoint_url'];
            $setting->sheet_names  = $names;
        }

        $setting->save();

        // Validate the connection by pulling the schema from the chosen source.
        try {
            $schema = $sources->active()->schema();
        } catch (\Throwable $e) {
            return back()->withErrors([
                $source === 'sheets' ? 'endpoint_url' : 'source' => $e->getMessage(),
            ]);
        }

        return back()->with('status', 'Connected. Sheets found: '.implode(', ', array_keys($schema)));
    }
}