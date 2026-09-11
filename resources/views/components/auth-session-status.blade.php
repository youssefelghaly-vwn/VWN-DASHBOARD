@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-[13px] rounded-lg px-3.5 py-2.5']) }}
        style="background:rgba(43,227,143,0.10);border:1px solid var(--mint);color:var(--mint);">
        {{ $status }}
    </div>
@endif