{{-- resources/views/admin/partials/filter-operator-script.blade.php --}}
{{--
    Helpers the condition rows call from Alpine expressions. They hang off
    window rather than a component because the chart builder and the metric
    builder are separate x-data trees that need the same behaviour.

    Every holder is addressed as (object, key) so one set of helpers serves both
    shapes in use: a condition row ({column, operator, value}) and a metric's
    single filter ({filter_column, filter_operator, filter_value}).
--}}
<script>
    window.FILTER_OPERATOR_INPUT = @json(\App\Support\FilterOperators::inputKinds());
    window.FILTER_RANGE_SEP = @json(\App\Support\FilterOperators::RANGE_SEPARATOR);

    /* What the value box should collect: none | text | number | date | date_range. */
    window.filterInput = op => window.FILTER_OPERATOR_INPUT[op] || 'text';

    /* "is between" keeps both ends in the single value string the API takes. */
    window.filterRangePart = (value, part) => {
        const [start = '', end = ''] = String(value ?? '').split(window.FILTER_RANGE_SEP);
        return (part === 'start' ? start : end).trim();
    };

    window.filterSetRangePart = (obj, key, part, value) => {
        const start = part === 'start' ? value : window.filterRangePart(obj[key], 'start');
        const end = part === 'end' ? value : window.filterRangePart(obj[key], 'end');
        obj[key] = start + window.FILTER_RANGE_SEP + end;
    };

    /* Switching operator seeds a usable value, so a row is never left in the
       half-configured state that the server reads as "match nothing". */
    window.filterOnOperatorChange = (obj, key, opKey) => {
        const kind = window.filterInput(obj[opKey]);
        const current = String(obj[key] ?? '').trim();

        if (kind === 'none') obj[key] = '';
        else if (kind === 'number' && !/^\d+$/.test(current)) obj[key] = '7';
        else if (kind === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(current)) obj[key] = new Date().toISOString().slice(0, 10);
        else if (kind === 'date_range' && !current.includes(window.FILTER_RANGE_SEP)) obj[key] = window.FILTER_RANGE_SEP;
        else if (kind === 'text' && (current === window.FILTER_RANGE_SEP || /^\d{4}-\d{2}-\d{2}$/.test(current))) obj[key] = '';
    };
</script>
