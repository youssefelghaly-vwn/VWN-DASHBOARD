<x-guest-layout>
    <div class="text-center">
        <div class="w-11 h-11 rounded-full flex items-center justify-center mx-auto mb-4 text-lg"
             style="background:rgba(255,176,32,0.12);border:1px solid var(--amber);color:var(--amber);">⏳</div>
        <h1 class="display font-bold text-[18px]" style="color:var(--ink);">This invitation link isn't valid</h1>
        <p class="mt-2 text-[13.5px] leading-relaxed" style="color:var(--ink-soft);">
            It may have already been used, or it has expired. Ask an admin to send you a fresh invitation.
        </p>
        <a href="{{ route('login') }}"
           class="inline-block mt-6 text-[13px] font-semibold transition" style="color:var(--mint);"
           onmouseover="this.style.color='#fff'" onmouseout="this.style.color='var(--mint)'">
            Back to sign in
        </a>
    </div>
</x-guest-layout>