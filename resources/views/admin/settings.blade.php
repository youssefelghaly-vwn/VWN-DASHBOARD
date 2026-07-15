{{-- resources/views/admin/settings.blade.php --}}
<x-app-layout title="Settings">
    <x-slot name="header">
        <h1 class="display text-2xl font-bold">Data Source</h1>
        <p class="text-sm" style="color:var(--ink-soft);">
            Choose where the dashboard reads from. You can switch anytime — your charts and metrics stay put.
        </p>
    </x-slot>

    @php
        $setting = \App\Models\SheetSetting::current();
        $ghl     = \App\Models\GhlConnection::current();
        $source  = $setting?->source ?? 'sheets';
    @endphp

    <div class="px-6 lg:px-8 py-8 max-w-3xl"
         x-data="{ source: '{{ $source }}' }">

        @error('endpoint_url')
            <div class="mb-5 rounded-lg px-4 py-3 text-sm"
                 style="background:rgba(226,105,79,0.10);border:1px solid var(--coral);color:#9E3B24;">
                {{ $message }}
            </div>
        @enderror

        {{-- SOURCE SWITCHER --}}
        <div class="flex gap-2 mb-8 p-1 rounded-xl w-max" style="background:var(--panel-alt);">
            <button @click="source = 'sheets'"
                    class="px-5 py-2.5 rounded-lg text-sm font-semibold transition"
                    :style="source === 'sheets'
                        ? 'background:var(--panel);color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.08);'
                        : 'color:var(--ink-soft);background:transparent;'">
                Google Sheets
            </button>
            <button @click="source = 'ghl'"
                    class="px-5 py-2.5 rounded-lg text-sm font-semibold transition"
                    :style="source === 'ghl'
                        ? 'background:var(--panel);color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.08);'
                        : 'color:var(--ink-soft);background:transparent;'">
                GoHighLevel
            </button>
        </div>

        {{-- ==================== GOHIGHLEVEL (Private Integration token) ==================== --}}
        <div x-show="source === 'ghl'" x-cloak class="space-y-6">

            @if ($ghl)
                {{-- CONNECTED STATE --}}
                <div class="rounded-xl p-6" style="background:var(--panel);border:1px solid var(--line);">
                    <span class="text-[10.5px] font-bold uppercase tracking-[1.5px] block mb-4" style="color:var(--mint-deep);">
                        GoHighLevel Connection
                    </span>

                    <div class="flex items-center gap-2.5 mb-4">
                        <span class="w-2.5 h-2.5 rounded-full" style="background:var(--mint);box-shadow:0 0 0 3px rgba(79,227,166,0.2);"></span>
                        <span class="text-sm font-semibold">Connected</span>
                        @if ($ghl->location_name)
                            <span class="mono text-xs px-2.5 py-1 rounded-full"
                                  style="background:rgba(79,227,166,0.16);color:var(--mint-deep);">{{ $ghl->location_name }}</span>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-4 text-xs mb-5" style="color:var(--ink-soft);">
                        <div>
                            <div class="uppercase tracking-wide text-[10px] mb-1">Location ID</div>
                            <div class="mono" style="color:var(--ink);">{{ $ghl->location_id }}</div>
                        </div>
                        <div>
                            <div class="uppercase tracking-wide text-[10px] mb-1">Last synced</div>
                            <div class="mono" style="color:var(--ink);">{{ $ghl->last_synced_at?->diffForHumans() ?? 'never' }}</div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.ghl.disconnect') }}"
                          onsubmit="return confirm('Disconnect GoHighLevel and revert to Google Sheets?')">
                        @csrf @method('DELETE')
                        <button class="px-4 py-2.5 rounded-lg text-sm font-medium"
                                style="border:1px solid var(--coral);color:var(--coral);">Disconnect</button>
                    </form>
                </div>

                {{-- Re-enter token (to rotate / fix scopes) --}}
                <details class="rounded-xl p-5" style="background:var(--panel);border:1px solid var(--line);">
                    <summary class="text-sm font-semibold cursor-pointer">Update token or location</summary>
                    @include('admin.partials.ghl-token-form', ['ghl' => $ghl])
                </details>

                {{-- Objects to pull + Cache TTL --}}
                @php
                    $selectedObjects = $setting?->ghlObjects() ?? \App\Services\GhlService::SHEETS;
                    $ghlMeta = app(\App\Services\GhlService::class)->lastMeta();
                    $ghlResources = $ghlMeta['resources'] ?? [];
                    $maxMs = collect($ghlResources)->max('ms') ?: 1;
                @endphp

                <form method="POST" action="{{ route('admin.settings.update') }}"
                      class="rounded-xl p-5 space-y-6" style="background:var(--panel);border:1px solid var(--line);">
                    @csrf @method('PUT')
                    <input type="hidden" name="source" value="ghl">

                    {{-- OBJECT SELECTION --}}
                    <div>
                        <span class="text-[10.5px] font-bold uppercase tracking-[1.5px] block mb-1" style="color:var(--mint-deep);">
                            Objects to pull
                        </span>
                        <p class="text-xs mb-3" style="color:var(--ink-soft);">
                            Only the objects you check are fetched from GoHighLevel and shown in the
                            chart, metric, and table builders. Fewer objects = a faster dashboard —
                            <b>Appointments</b> is the heaviest (it sweeps every calendar).
                        </p>

                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Services\GhlService::SHEETS as $name)
                                @php $isOn = in_array($name, old('ghl_objects', $selectedObjects), true); @endphp
                                <label x-data="{ on: {{ $isOn ? 'true' : 'false' }} }"
                                       class="mono text-[11px] px-3 py-1.5 rounded-full cursor-pointer select-none transition flex items-center gap-1.5"
                                       :style="on
                                           ? 'background:var(--mint-deep);color:#fff;'
                                           : 'background:var(--panel-alt);color:var(--ink-soft);border:1px solid var(--line);'">
                                    <input type="checkbox" name="ghl_objects[]" value="{{ $name }}"
                                           x-model="on" class="hidden">
                                    <span x-text="on ? '✓' : '＋'" class="text-[10px] leading-none"></span>
                                    {{ $name }}
                                </label>
                            @endforeach
                        </div>

                        @error('ghl_objects')
                            <p class="text-xs mt-2" style="color:var(--coral);">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- CACHE TTL --}}
                    <div class="pt-5" style="border-top:1px solid var(--line);">
                        <label class="block text-sm font-semibold mb-1.5">Cache refresh (seconds)</label>
                        <input name="cache_ttl" type="number" min="0" max="3600"
                               value="{{ old('cache_ttl', $setting->cache_ttl ?? 60) }}"
                               class="w-40 rounded-lg text-sm px-3 py-2.5"
                               style="border:1px solid var(--line);background:var(--panel-alt);">
                        <p class="text-xs mt-1.5" style="color:var(--ink-soft);">
                            How long GHL data is held before re-fetching. Higher = fewer API calls.
                        </p>
                    </div>

                    <button class="px-4 py-2.5 rounded-lg text-sm font-semibold text-white"
                            style="background:var(--mint-deep);">Save changes</button>
                </form>

                {{-- SYNC HEALTH SUMMARY --}}
                <div class="rounded-xl p-5" style="background:var(--panel);border:1px solid var(--line);">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-[10.5px] font-bold uppercase tracking-[1.5px]" style="color:var(--ink-soft);">
                            Last sync — time per object
                        </span>
                        <a href="{{ route('admin.ghl.health') }}"
                           class="text-xs font-semibold" style="color:var(--mint-deep);">Data Health →</a>
                    </div>

                    @if ($ghlResources)
                        <div class="space-y-2">
                            @foreach ($ghlResources as $resource => $info)
                                <div class="flex items-center gap-3">
                                    <span class="w-2 h-2 rounded-full shrink-0"
                                          style="background:{{ ($info['ok'] ?? false) ? 'var(--mint)' : 'var(--coral)' }};"></span>
                                    <span class="text-xs w-32 shrink-0 truncate">{{ $resource }}</span>
                                    <div class="flex-1 h-2 rounded-full overflow-hidden" style="background:var(--panel-alt);">
                                        <div class="h-full rounded-full"
                                             style="width:{{ max(3, round(($info['ms'] ?? 0) / $maxMs * 100)) }}%;background:var(--mint-deep);"></div>
                                    </div>
                                    <span class="mono text-[11px] w-20 text-right shrink-0" style="color:var(--ink-soft);">
                                        {{ number_format($info['ms'] ?? 0) }} ms
                                    </span>
                                    <span class="mono text-[11px] w-16 text-right shrink-0" style="color:var(--ink-soft);">
                                        {{ number_format($info['count'] ?? 0) }} rows
                                    </span>
                                </div>
                            @endforeach
                        </div>
                        <p class="mono text-[11px] mt-3" style="color:var(--ink-soft);">
                            Total {{ number_format($ghlMeta['total_ms'] ?? 0) }} ms
                            @if (!empty($ghlMeta['ran_at']))· {{ \Illuminate\Support\Carbon::parse($ghlMeta['ran_at'])->diffForHumans() }}@endif
                        </p>
                    @else
                        <p class="text-xs" style="color:var(--ink-soft);">
                            No timing data yet. Open <a href="{{ route('admin.ghl.health') }}" class="underline" style="color:var(--mint-deep);">Data Health</a>
                            and run a sync to see how long each object takes.
                        </p>
                    @endif
                </div>
            @else
                {{-- NOT CONNECTED — paste token --}}
                <div class="rounded-xl p-6" style="background:var(--panel);border:1px solid var(--line);">
                    <span class="text-[10.5px] font-bold uppercase tracking-[1.5px] block mb-4" style="color:var(--mint-deep);">
                        Connect GoHighLevel
                    </span>

                    <div class="rounded-lg p-4 mb-5 text-xs leading-relaxed" style="background:var(--panel-alt);color:var(--ink-soft);">
                        <b style="color:var(--ink);">Where to get these:</b>
                        <ol class="list-decimal ml-4 mt-2 space-y-1">
                            <li>In GoHighLevel: <b>Settings → Private Integrations</b>.</li>
                            <li>Open your integration (or create one) with all <b>read-only</b> scopes for
                                contacts, opportunities, calendars, and users.</li>
                            <li>Copy the token (starts with <span class="mono">pit-</span>) and paste it below.</li>
                            <li>Your <b>Location ID</b> is in the page URL:
                                <span class="mono">…/location/<b style="color:var(--mint-deep);">THIS_PART</b>/settings…</span>
                            </li>
                        </ol>
                    </div>

                    @include('admin.partials.ghl-token-form', ['ghl' => null])
                </div>
            @endif
        </div>

        {{-- ==================== GOOGLE SHEETS (unchanged) ==================== --}}
        <div x-show="source === 'sheets'" x-cloak>
            <div class="mb-8" x-data="{ copied: false }">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[10.5px] font-bold uppercase tracking-[1.5px]" style="color:var(--mint-deep);">
                        Step 1 — Paste this into Apps Script
                    </span>
                    <button type="button"
                            @click="navigator.clipboard.writeText($refs.script.textContent); copied = true; setTimeout(() => copied = false, 1800)"
                            class="text-xs px-3 py-1.5 rounded-md font-semibold"
                            style="background:var(--ink);color:#fff;">
                        <span x-text="copied ? '✓ Copied' : 'Copy script'"></span>
                    </button>
                </div>

                <p class="text-xs mb-3" style="color:var(--ink-soft);">
                    In your Google Sheet: <b>Extensions → Apps Script</b>, replace everything with this,
                    then <b>Deploy → New deployment → Web app</b> (Execute as: <i>Me</i>, Access: <i>Anyone</i>).
                </p>

                <pre x-ref="script"
                     class="mono text-[11px] leading-relaxed p-4 rounded-lg overflow-x-auto"
                     style="background:var(--sidebar);color:#B9EFDA;">{{ $script }}</pre>
            </div>

            <div class="rounded-xl p-6" style="background:var(--panel);border:1px solid var(--line);">
                <span class="text-[10.5px] font-bold uppercase tracking-[1.5px] block mb-4" style="color:var(--mint-deep);">
                    Step 2 — Connect
                </span>

                <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-5">
                    @csrf @method('PUT')
                    <input type="hidden" name="source" value="sheets">

                    <div>
                        <label class="block text-sm font-semibold mb-1.5">Web App URL</label>
                        <input name="endpoint_url" type="url"
                               value="{{ old('endpoint_url', $setting?->endpoint_url) }}"
                               placeholder="https://script.google.com/macros/s/.../exec"
                               class="mono w-full rounded-lg text-xs px-3 py-2.5"
                               style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5">Sheet tabs</label>
                        <input name="sheet_names"
                               value="{{ old('sheet_names', $setting && $setting->sheet_names ? implode(', ', $setting->sheet_names) : '') }}"
                               placeholder="Output, Data, Leads"
                               class="w-full rounded-lg text-sm px-3 py-2.5"
                               style="border:1px solid var(--line);background:var(--panel-alt);">
                        <p class="text-xs mt-1.5" style="color:var(--ink-soft);">
                            Comma-separated. Column names are read from row 1 of each tab.
                        </p>
                        @error('sheet_names')
                            <p class="text-xs mt-1" style="color:var(--coral);">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5">Cache TTL (seconds)</label>
                        <input name="cache_ttl" type="number" min="0" max="3600"
                               value="{{ old('cache_ttl', $setting->cache_ttl ?? 60) }}"
                               class="w-40 rounded-lg text-sm px-3 py-2.5"
                               style="border:1px solid var(--line);background:var(--panel-alt);">
                    </div>

                    <div class="flex items-center gap-3 pt-1">
                        <button class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white"
                                style="background:var(--mint-deep);">
                            Save &amp; Test Connection
                        </button>

                        @if ($setting && $setting->endpoint_url)
                            <span class="mono text-[11px]" style="color:var(--ink-soft);">
                                Last synced: {{ $setting->last_synced_at?->diffForHumans() ?? 'never' }}
                            </span>
                        @endif
                    </div>
                </form>
            </div>

            @if ($setting && $setting->sheet_names)
                <div class="mt-6 rounded-xl p-5" style="background:var(--panel);border:1px solid var(--line);">
                    <span class="text-[10.5px] font-bold uppercase tracking-[1.5px] block mb-3" style="color:var(--ink-soft);">
                        Detected Tabs
                    </span>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($setting->sheet_names as $name)
                            <span class="mono text-[11px] px-3 py-1.5 rounded-full"
                                  style="background:rgba(79,227,166,0.16);color:var(--mint-deep);">{{ $name }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>