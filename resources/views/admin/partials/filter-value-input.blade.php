{{-- resources/views/admin/partials/filter-value-input.blade.php --}}
{{--
    The value box for one filter condition, swapped to match the operator:
    hidden for "is empty", a number for "in the last N days", a date picker for
    "is on", two date pickers for "is between", a text box otherwise.

    $filterObj      Alpine expression for the object holding the condition
    $filterValueKey property on it holding the value    (default "value")
    $filterOpKey    property on it holding the operator (default "operator")
--}}
@php
    $valueKey = $filterValueKey ?? 'value';
    $value = $filterObj.'.'.$valueKey;
    $operator = $filterObj.'.'.($filterOpKey ?? 'operator');
@endphp

<template x-if="window.filterInput({{ $operator }}) === 'date_range'">
    <div class="flex items-center gap-1">
        <input type="date" :value="window.filterRangePart({{ $value }}, 'start')"
               @input="window.filterSetRangePart({{ $filterObj }}, '{{ $valueKey }}', 'start', $event.target.value)"
               class="w-full rounded text-xs px-2 py-1.5"
               style="border:1px solid var(--line);background:var(--panel);">
        <span class="text-[10px]" style="color:var(--ink-soft);">to</span>
        <input type="date" :value="window.filterRangePart({{ $value }}, 'end')"
               @input="window.filterSetRangePart({{ $filterObj }}, '{{ $valueKey }}', 'end', $event.target.value)"
               class="w-full rounded text-xs px-2 py-1.5"
               style="border:1px solid var(--line);background:var(--panel);">
    </div>
</template>

<template x-if="window.filterInput({{ $operator }}) === 'date'">
    <input type="date" x-model="{{ $value }}"
           class="w-full rounded text-xs px-2 py-1.5"
           style="border:1px solid var(--line);background:var(--panel);">
</template>

<template x-if="window.filterInput({{ $operator }}) === 'number'">
    <input type="number" min="1" step="1" x-model="{{ $value }}"
           :placeholder="String({{ $operator }}).startsWith('date_') ? 'days' : 'number'"
           class="w-full rounded text-xs px-2 py-1.5"
           style="border:1px solid var(--line);background:var(--panel);">
</template>

<template x-if="window.filterInput({{ $operator }}) === 'text'">
    <input x-model="{{ $value }}"
           :placeholder="['has_all','has_any','not_has_any'].includes({{ $operator }}) ? 'e.g. 1st Email, 1st Linked-IN' : 'value'"
           class="w-full rounded text-xs px-2 py-1.5"
           style="border:1px solid var(--line);background:var(--panel);">
</template>
