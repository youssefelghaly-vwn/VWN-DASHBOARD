{{-- resources/views/admin/menu.blade.php --}}
<x-app-layout title="Menu">
    <div class="px-6 lg:px-8 py-8" x-data="{ kind: 'dashboard' }">
        <div class="mb-7">
            <h1 class="display text-2xl font-bold">Menu Management</h1>
            <p class="text-sm" style="color:var(--ink-soft);">
                Create dashboards and control the sidebar — no routes to touch.
            </p>
        </div>

        {{-- DASHBOARDS --}}
        <div class="rounded-xl mb-8" style="background:var(--panel);border:1px solid var(--line);">
            <div class="px-5 py-4 flex items-center justify-between" style="border-bottom:1px solid var(--line);">
                <h3 class="display text-[14.5px] font-semibold uppercase tracking-wide">Dashboards</h3>
                <form method="POST" action="{{ route('admin.dashboards.store') }}" class="flex gap-2">
                    @csrf
                    <input name="name" required placeholder="New dashboard name"
                           class="rounded-lg text-xs px-3 py-2 w-48" style="border:1px solid var(--line);background:var(--panel-alt);">
                    <button class="px-3 py-2 rounded-lg text-xs font-semibold text-white" style="background:var(--mint-deep);">Create</button>
                </form>
            </div>
            @forelse ($dashboards as $dashboard)
                <div class="px-5 py-3 flex items-center justify-between gap-3"
                     @if (! $loop->last) style="border-bottom:1px solid var(--line);" @endif>
                    <div>
                        <a href="{{ route('admin.dashboards.show', $dashboard->slug) }}" class="font-semibold text-sm underline">{{ $dashboard->name }}</a>
                        @if ($dashboard->is_default)<span class="mono text-[10px] ms-2" style="color:var(--mint-deep);">default</span>@endif
                    </div>
                    @unless ($dashboard->is_default)
                        <form method="POST" action="{{ route('admin.dashboards.destroy', $dashboard) }}"
                              onsubmit="return confirm('Delete {{ $dashboard->name }} and its charts/metrics?')">
                            @csrf @method('DELETE')
                            <button class="px-3 py-1.5 rounded-lg text-xs font-medium" style="color:var(--coral);border:1px solid var(--line);">Delete</button>
                        </form>
                    @endunless
                </div>
            @empty
                <div class="px-5 py-6 text-center text-sm" style="color:var(--ink-soft);">No dashboards yet — create one above.</div>
            @endforelse
        </div>

        {{-- CURRENT ITEMS --}}
        <div class="rounded-xl mb-8" style="background:var(--panel);border:1px solid var(--line);">
            <div class="px-5 py-4" style="border-bottom:1px solid var(--line);">
                <h3 class="display text-[14.5px] font-semibold uppercase tracking-wide">Menu items</h3>
            </div>
            @forelse ($items as $item)
                <div class="px-5 py-4 flex items-center justify-between gap-3"
                     @if (! $loop->last) style="border-bottom:1px solid var(--line);" @endif>
                    <div>
                        <div class="font-semibold text-sm">{{ $item->label }}</div>
                        <div class="mono text-[11px]" style="color:var(--ink-soft);">
                            {{ $item->dashboard ? 'Dashboard · '.$item->dashboard->name : ($item->url ?: 'Link') }}
                        </div>
                    </div>
                    <form method="POST" action="{{ route('admin.menu.destroy', $item) }}">
                        @csrf @method('DELETE')
                        <button class="px-3 py-1.5 rounded-lg text-xs font-medium" style="color:var(--coral);border:1px solid var(--line);">Remove</button>
                    </form>
                </div>
            @empty
                <div class="px-5 py-8 text-center text-sm" style="color:var(--ink-soft);">No custom menu items yet.</div>
            @endforelse
        </div>

        {{-- ADD --}}
        <div class="rounded-xl p-5" style="background:var(--panel);border:1px solid var(--line);">
            <h3 class="display text-[14.5px] font-semibold uppercase tracking-wide mb-4">Add menu item</h3>
            <form method="POST" action="{{ route('admin.menu.store') }}" class="grid grid-cols-2 gap-4">
                @csrf
                <div class="col-span-2">
                    <label class="block text-xs font-semibold mb-1.5">Label</label>
                    <input name="label" required class="w-full rounded-lg text-sm px-3 py-2"
                           style="border:1px solid var(--line);background:var(--panel-alt);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1.5">Type</label>
                    <select x-model="kind" class="w-full rounded-lg text-sm px-3 py-2"
                            style="border:1px solid var(--line);background:var(--panel-alt);">
                        <option value="dashboard">Dashboard</option>
                        <option value="link">Custom link</option>
                    </select>
                </div>
                <div x-show="kind === 'dashboard'">
                    <label class="block text-xs font-semibold mb-1.5">Dashboard</label>
                    <select name="dashboard_id" class="w-full rounded-lg text-sm px-3 py-2"
                            style="border:1px solid var(--line);background:var(--panel-alt);">
                        @foreach ($dashboards as $dashboard)
                            <option value="{{ $dashboard->id }}">{{ $dashboard->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div x-show="kind === 'link'" x-cloak>
                    <label class="block text-xs font-semibold mb-1.5">URL</label>
                    <input name="url" placeholder="https://…" class="w-full rounded-lg text-sm px-3 py-2"
                           style="border:1px solid var(--line);background:var(--panel-alt);">
                </div>
                <div class="col-span-2">
                    <button class="px-5 py-2 rounded-lg text-sm font-semibold text-white" style="background:var(--mint-deep);">Add to menu</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
