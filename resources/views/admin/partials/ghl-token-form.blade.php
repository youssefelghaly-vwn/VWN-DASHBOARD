{{-- resources/views/admin/partials/ghl-token-form.blade.php --}}
{{-- $ghl = existing connection or null --}}
<form method="POST" action="{{ route('admin.ghl.store') }}" class="space-y-4 {{ $ghl ? 'mt-4' : '' }}">
    @csrf

    <div>
        <label class="block text-sm font-semibold mb-1.5">Private Integration Token</label>
        <input name="access_token" type="password" autocomplete="off"
               placeholder="{{ $ghl ? '•••••••• (enter to replace)' : 'pit-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' }}"
               class="mono w-full rounded-lg text-xs px-3 py-2.5"
               style="border:1px solid var(--line);background:var(--panel-alt);">
        @error('access_token')
            <p class="text-xs mt-1" style="color:var(--coral);">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="block text-sm font-semibold mb-1.5">Location ID</label>
        <input name="location_id" type="text"
               value="{{ old('location_id', $ghl?->location_id) }}"
               placeholder="e.g. PNCMlG4voJswQZCqs2Uh"
               class="mono w-full rounded-lg text-xs px-3 py-2.5"
               style="border:1px solid var(--line);background:var(--panel-alt);">
        @error('location_id')
            <p class="text-xs mt-1" style="color:var(--coral);">{{ $message }}</p>
        @enderror
    </div>

    <button class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white"
            style="background:var(--mint-deep);">
        {{ $ghl ? 'Update & Test' : 'Connect & Test' }}
    </button>
</form>