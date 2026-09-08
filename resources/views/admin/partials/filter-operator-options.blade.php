{{-- resources/views/admin/partials/filter-operator-options.blade.php --}}
{{--
    The <option> list for any {column, operator, value} condition row, rendered
    from App\Support\FilterOperators so the builders and the controllers that
    validate them can never drift apart.
--}}
@foreach (\App\Support\FilterOperators::groups() as $group => $operators)
    <optgroup label="{{ $group }}">
        @foreach ($operators as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </optgroup>
@endforeach
