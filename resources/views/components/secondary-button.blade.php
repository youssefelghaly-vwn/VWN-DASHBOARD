<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center px-4 py-2.5 rounded-lg font-semibold text-[13.5px] transition disabled:opacity-40']) }}
    style="background:var(--panel);border:1px solid var(--line);color:var(--ink);"
    onmouseover="this.style.borderColor='var(--mint)'" onmouseout="this.style.borderColor='var(--line)'">
    {{ $slot }}
</button>