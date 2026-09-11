<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center px-4 py-2.5 rounded-lg font-semibold text-[13.5px] text-white transition']) }}
    style="background:var(--mint-deep);"
    onmouseover="this.style.background='var(--mint)'" onmouseout="this.style.background='var(--mint-deep)'">
    {{ $slot }}
</button>