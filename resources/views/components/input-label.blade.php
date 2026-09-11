@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-[13px] mb-1.5']) }} style="color:var(--ink-soft);">
    {{ $value ?? $slot }}
</label>