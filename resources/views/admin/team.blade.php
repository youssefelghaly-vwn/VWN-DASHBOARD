{{-- resources/views/admin/team.blade.php --}}
<x-app-layout title="Team">
    <div class="px-6 lg:px-8 py-8">
        <div class="mb-7">
            <h1 class="display text-2xl font-bold">Team</h1>
            <p class="text-sm" style="color:var(--ink-soft);">
                Invite people by email. They’ll get a link to set their own password and activate their account.
            </p>
        </div>

        @if (session('status'))
            <div class="rounded-lg px-4 py-3 text-sm mb-6"
                 style="background:rgba(79,227,166,0.12);border:1px solid var(--mint-deep);color:#12241F;">{{ session('status') }}</div>
        @endif
        @error('email')
            <div class="rounded-lg px-4 py-3 text-sm mb-6"
                 style="background:rgba(226,105,79,0.10);border:1px solid var(--coral);color:#9E3B24;">{{ $message }}</div>
        @enderror

        {{-- Invite form --}}
        <div class="rounded-xl mb-8 p-5" style="background:var(--panel);border:1px solid var(--line);">
            <h3 class="display text-[14.5px] font-semibold uppercase tracking-wide mb-4">Invite a member</h3>
            <form method="POST" action="{{ route('admin.team.invite') }}"
                  class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex-1 min-w-[220px]">
                    <label class="block text-xs font-semibold mb-1.5">Email address</label>
                    <input name="email" type="email" required placeholder="person@company.com" value="{{ old('email') }}"
                           class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                </div>
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs font-semibold mb-1.5">Name <span style="color:var(--ink-soft);">(optional)</span></label>
                    <input name="name" type="text" placeholder="Jane Doe" value="{{ old('name') }}"
                           class="w-full rounded-lg text-sm px-3 py-2" style="border:1px solid var(--line);background:var(--panel-alt);">
                </div>
                <label class="flex items-center gap-2 text-sm px-3 py-2 rounded-lg" style="border:1px solid var(--line);background:var(--panel-alt);">
                    <input type="checkbox" name="is_admin" value="1" {{ old('is_admin') ? 'checked' : '' }}> Admin
                </label>
                <button class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background:var(--mint-deep);">
                    Send invite
                </button>
            </form>
        </div>

        {{-- Member list --}}
        <div class="rounded-xl" style="background:var(--panel);border:1px solid var(--line);">
            <div class="px-5 py-4" style="border-bottom:1px solid var(--line);">
                <h3 class="display text-[14.5px] font-semibold uppercase tracking-wide">Members</h3>
            </div>

            @foreach ($users as $user)
                <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-3"
                     @if (! $loop->last) style="border-bottom:1px solid var(--line);" @endif>
                    <div class="min-w-0">
                        <div class="font-semibold text-sm flex items-center gap-2">
                            {{ $user->name }}
                            @if ($user->is_admin)
                                <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full"
                                      style="background:rgba(79,227,166,0.16);color:var(--mint-deep);">Admin</span>
                            @endif
                        </div>
                        <div class="mono text-[11px]" style="color:var(--ink-soft);">{{ $user->email }}</div>
                    </div>

                    <div class="flex items-center gap-3">
                        @if ($user->isPending())
                            <span class="text-[11px] font-semibold px-2.5 py-1 rounded-full"
                                  style="background:rgba(238,159,78,0.16);color:#7A4A12;">Invitation pending</span>
                            <form method="POST" action="{{ route('admin.team.resend', $user) }}">
                                @csrf
                                <button class="text-xs px-3 py-1.5 rounded-md font-medium" style="border:1px solid var(--line);background:var(--panel);">Resend</button>
                            </form>
                        @else
                            <span class="text-[11px] font-semibold px-2.5 py-1 rounded-full"
                                  style="background:rgba(79,227,166,0.14);color:var(--mint-deep);">Active</span>
                        @endif

                        @if ($user->id !== auth()->id())
                            <form method="POST" action="{{ route('admin.team.destroy', $user) }}"
                                  onsubmit="return confirm('Remove {{ $user->email }}?');">
                                @csrf @method('DELETE')
                                <button class="text-xs px-3 py-1.5 rounded-md font-medium" style="border:1px solid var(--line);color:var(--coral);background:var(--panel);">Remove</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
