@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'text-[12.5px] space-y-1 mt-1.5']) }} style="color:var(--coral);">
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif