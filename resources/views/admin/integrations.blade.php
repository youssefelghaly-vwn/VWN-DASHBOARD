{{-- resources/views/admin/integrations.blade.php --}}
<x-app-layout title="Integrations">
    <div class="px-6 lg:px-8 py-8" x-data="{ provider: '' }">
        <div class="mb-7">
            <h1 class="display text-2xl font-bold">Integrations</h1>
            <p class="text-sm" style="color:var(--ink-soft);">
                Connect data sources. Each syncs into local storage; dashboards read only that local data.
            </p>
        </div>

        @error('integration')
            <div class="rounded-lg px-4 py-3 text-sm mb-6"
                 style="background:rgba(226,105,79,0.10);border:1px solid var(--coral);color:#9E3B24;">{{ $message }}</div>
        @enderror

        {{-- CONNECTED --}}
        <div class="rounded-xl mb-8" style="background:var(--panel);border:1px solid var(--line);">
            <div class="px-5 py-4" style="border-bottom:1px solid var(--line);">
                <h3 class="display text-[14.5px] font-semibold uppercase tracking-wide">Connected</h3>
            </div>

            @forelse ($integrations as $integration)
                @php $run = $integration->latestSyncRun; @endphp
                <div x-data="{ editing: false }"
                     @if (! $loop->last) style="border-bottom:1px solid var(--line);" @endif>
                <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-semibold text-sm">{{ $integration->name }}</div>
                        <div class="mono text-[11px]" style="color:var(--ink-soft);">
                            {{ $catalogue[$integration->provider] ?? $integration->provider }} ·
                            <span style="color:{{ $integration->status === 'connected' ? 'var(--mint-deep)' : 'var(--coral)' }};">{{ ucfirst($integration->status) }}</span> ·
                            synced {{ $integration->last_synced_at?->diffForHumans() ?? 'never' }}
                            @if ($run && $run->records_synced) · {{ number_format($run->records_synced) }} records @endif
                        </div>
                        @if ($run?->last_error)
                            <div class="mt-2 rounded-md px-3 py-2 text-[11px]"
                                 style="background:rgba(226,105,79,0.10);border:1px solid var(--coral);color:#9E3B24;">
                                <span class="font-semibold">Last sync failed:</span> {{ $run->last_error }}
                                <a href="{{ route('admin.data-health') }}" class="underline ms-1">Details</a>
                            </div>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <button @click="editing = !editing" type="button"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium" style="border:1px solid var(--line);">
                            <span x-text="editing ? 'Cancel' : 'Edit'"></span>
                        </button>
                        <form method="POST" action="{{ route('admin.integrations.sync', $integration) }}">
                            @csrf
                            <button class="px-3 py-1.5 rounded-lg text-xs font-medium" style="border:1px solid var(--line);">↻ Sync</button>
                        </form>
                        <form method="POST" action="{{ route('admin.integrations.destroy', $integration) }}"
                              onsubmit="return confirm('Disconnect {{ $integration->name }}? Synced data will be removed.')">
                            @csrf @method('DELETE')
                            <button class="px-3 py-1.5 rounded-lg text-xs font-medium" style="color:var(--coral);border:1px solid var(--line);">Disconnect</button>
                        </form>
                    </div>
                </div>

                <div x-show="editing" x-cloak class="px-5 pb-5">
                    <form method="POST" action="{{ route('admin.integrations.update', $integration) }}" class="grid grid-cols-2 gap-4 p-4 rounded-lg" style="background:var(--panel-alt);">
                        @csrf @method('PUT')

                        @if ($integration->provider === 'gohighlevel')
                            <div>
                                <label class="block text-xs font-semibold mb-1.5">Private Integration Token</label>
                                <input name="access_token" placeholder="Leave blank to keep the current token" class="w-full rounded-lg text-sm px-3 py-2"
                                       style="border:1px solid var(--line);background:var(--panel);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1.5">Location ID</label>
                                <input name="location_id" value="{{ $integration->credential('location_id') }}" class="w-full rounded-lg text-sm px-3 py-2"
                                       style="border:1px solid var(--line);background:var(--panel);">
                            </div>
                        @elseif ($integration->provider === 'google_sheets')
                            <div>
                                <label class="block text-xs font-semibold mb-1.5">Apps Script URL</label>
                                <input name="endpoint_url" value="{{ $integration->setting('endpoint_url') }}" class="w-full rounded-lg text-sm px-3 py-2"
                                       style="border:1px solid var(--line);background:var(--panel);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1.5">Sheet/tab names (comma-separated)</label>
                                <input name="sheet_names" value="{{ implode(', ', $integration->setting('sheet_names', [])) }}" class="w-full rounded-lg text-sm px-3 py-2"
                                       style="border:1px solid var(--line);background:var(--panel);">
                            </div>
                        @elseif ($integration->provider === 'meta_ads')
                            <div>
                                <label class="block text-xs font-semibold mb-1.5">Access Token</label>
                                <input name="access_token" placeholder="Leave blank to keep the current token" class="w-full rounded-lg text-sm px-3 py-2"
                                       style="border:1px solid var(--line);background:var(--panel);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1.5">Ad Account ID</label>
                                <input name="ad_account_id" value="{{ $integration->credential('ad_account_id') }}" class="w-full rounded-lg text-sm px-3 py-2"
                                       style="border:1px solid var(--line);background:var(--panel);">
                            </div>
                        @endif

                        <div class="col-span-2">
                            <button class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background:var(--mint-deep);">Save changes</button>
                        </div>
                    </form>
                </div>
                </div>
            @empty
                <div class="px-5 py-8 text-center text-sm" style="color:var(--ink-soft);">Nothing connected yet.</div>
            @endforelse
        </div>

        {{-- CONNECT NEW --}}
        <div class="rounded-xl p-5" style="background:var(--panel);border:1px solid var(--line);">
            <h3 class="display text-[14.5px] font-semibold uppercase tracking-wide mb-4">Connect a new integration</h3>

            <form method="POST" action="{{ route('admin.integrations.store') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-semibold mb-1.5">Provider</label>
                    <select name="provider" x-model="provider" required
                            class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                        <option value="">Select a provider…</option>
                        @foreach ($catalogue as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- GoHighLevel --}}
                <template x-if="provider === 'gohighlevel'">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold mb-1.5">Private Integration Token</label>
                            <input name="access_token" placeholder="pit-..." class="w-full rounded-lg text-sm px-3 py-2"
                                   style="border:1px solid var(--line);background:var(--panel-alt);">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold mb-1.5">Location ID</label>
                            <input name="location_id" class="w-full rounded-lg text-sm px-3 py-2"
                                   style="border:1px solid var(--line);background:var(--panel-alt);">
                        </div>
                    </div>
                </template>

                {{-- Google Sheets --}}
                <template x-if="provider === 'google_sheets'">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold mb-1.5">Apps Script URL</label>
                            <input name="endpoint_url" placeholder="https://script.google.com/..." class="w-full rounded-lg text-sm px-3 py-2"
                                   style="border:1px solid var(--line);background:var(--panel-alt);">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold mb-1.5">Sheet/tab names (comma-separated)</label>
                            <input name="sheet_names" placeholder="Leads, Output, SDR Performance" class="w-full rounded-lg text-sm px-3 py-2"
                                   style="border:1px solid var(--line);background:var(--panel-alt);">
                        </div>
                    </div>
                </template>

                {{-- Meta Ads --}}
                <template x-if="provider === 'meta_ads'">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold mb-1.5">Access Token</label>
                            <input name="access_token" class="w-full rounded-lg text-sm px-3 py-2"
                                   style="border:1px solid var(--line);background:var(--panel-alt);">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold mb-1.5">Ad Account ID</label>
                            <input name="ad_account_id" placeholder="act_1234567890" class="w-full rounded-lg text-sm px-3 py-2"
                                   style="border:1px solid var(--line);background:var(--panel-alt);">
                        </div>
                    </div>
                </template>

                <button x-show="provider" class="px-5 py-2 rounded-lg text-sm font-semibold text-white" style="background:var(--mint-deep);">
                    Connect
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
