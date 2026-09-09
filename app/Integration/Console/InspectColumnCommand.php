<?php

namespace App\Integration\Console;

use App\Integration\Models\Integration;
use App\Support\FilterOperators;
use App\Support\FiltersRows;
use Illuminate\Console\Command;

/**
 * Shows what a synced column actually holds, and how a filter reads it.
 *
 * "My filter returns 0" is almost never the filter — it is the cell not holding
 * what the dashboard displays. A date column can be missing from the payload
 * entirely, be present but empty (GoHighLevel omits unset custom fields, so a
 * column can exist on some rows and not others), or hold a shape the date
 * parser does not recognise. Those three look identical from the builder, so
 * this prints the raw values and the parse result side by side.
 */
class InspectColumnCommand extends Command
{
    use FiltersRows;

    protected $signature = 'integration:column
        {integration : Integration id}
        {dataset : Dataset name, e.g. "Opportunities"}
        {column : Column name, e.g. "Email 1 TS"}
        {--operator= : Also run this filter operator and report what it matched}
        {--value= : Value for the operator (e.g. 7, or 2026-09-01..2026-09-30)}
        {--limit=15 : How many distinct values to list}';

    protected $description = 'Show the raw values behind one synced column, and how a filter reads them.';

    public function handle(): int
    {
        $integration = Integration::find($this->argument('integration'));

        if (! $integration) {
            $this->error('No integration with id '.$this->argument('integration').'.');
            $this->line('Known: '.Integration::pluck('name', 'id')->map(fn ($n, $id) => "{$id} = {$n}")->implode(', '));

            return self::FAILURE;
        }

        $dataset = $this->argument('dataset');
        $column = $this->argument('column');
        $rows = $integration->rows($dataset);

        if (! $rows) {
            $this->error("No synced rows for dataset “{$dataset}”.");
            $this->line('Datasets with rows: '.$integration->records()->distinct()->pluck('dataset')->implode(', '));

            return self::FAILURE;
        }

        $this->line('');
        $this->info("{$integration->name} · {$dataset} · “{$column}”");
        $this->line(str_repeat('─', 60));

        $present = array_filter($rows, fn ($r) => array_key_exists($column, $r));
        $filled = array_filter($present, fn ($r) => trim((string) $r[$column]) !== '');

        $this->line('rows synced        '.count($rows));
        $this->line('have this key      '.count($present).$this->hint(count($present), count($rows), 'the column is missing from some rows — pick it in the integration settings so every row carries it'));
        $this->line('non-empty value    '.count($filled));

        if (! $present) {
            $this->line('');
            $this->warn('That column is on no row at all. Column names are case- and space-sensitive; this dataset has:');
            $this->line('  '.implode(', ', $this->columnsOf($rows)));

            return self::FAILURE;
        }

        $this->distinctValues($filled, $column);
        $this->dateReading($filled, $column);

        if ($operator = $this->option('operator')) {
            $this->runOperator($rows, $column, $operator);
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function columnsOf(array $rows): array
    {
        $cols = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $k) {
                $cols[$k] = true;
            }
        }

        return array_keys($cols);
    }

    private function distinctValues(array $rows, string $column): void
    {
        if (! $rows) {
            return;
        }

        $counts = array_count_values(array_map(fn ($r) => (string) $r[$column], $rows));
        arsort($counts);

        $limit = (int) $this->option('limit');

        $this->line('');
        $this->line('distinct non-empty values (most common first)');

        foreach (array_slice($counts, 0, $limit, true) as $value => $n) {
            // json_encode so trailing spaces and odd characters are visible.
            $this->line(sprintf('  %-6s %s', '×'.$n, json_encode($value)));
        }

        if (count($counts) > $limit) {
            $this->line('  … '.(count($counts) - $limit).' more');
        }
    }

    /** How the date parser reads those values — the answer to "why is my date filter 0?". */
    private function dateReading(array $rows, string $column): void
    {
        if (! $rows) {
            return;
        }

        $values = array_unique(array_map(fn ($r) => (string) $r[$column], $rows));
        $readable = [];
        $unreadable = [];

        foreach ($values as $value) {
            $day = $this->cellDate($value);
            $day ? $readable[] = $day->toDateString() : $unreadable[] = $value;
        }

        $this->line('');
        $this->line('read as a date     '.count($readable).' of '.count($values).' distinct values');

        if ($readable) {
            sort($readable);
            $this->line('  range            '.reset($readable).' … '.end($readable));
        }

        if ($unreadable) {
            $this->warn('  not date-shaped  '.implode(', ', array_map('json_encode', array_slice($unreadable, 0, 5))));
            $this->line('  A date filter can never match these. If they look like real dates, the parser needs that shape added.');
        }
    }

    private function runOperator(array $rows, string $column, string $operator): void
    {
        if (! FilterOperators::has($operator)) {
            $this->error("Unknown operator “{$operator}”. Known: ".implode(', ', FilterOperators::keys()));

            return;
        }

        $value = (string) $this->option('value');
        $matched = $this->applyOneFilter($rows, $column, $operator, $value);

        $this->line('');
        $this->line('filter             '.$operator.($value === '' ? '' : ' '.json_encode($value)));

        if (str_starts_with($operator, 'date_')) {
            [$from, $to] = $this->dateWindow($operator, $value);
            $this->line('window             '.($from?->toDateString() ?? '—').' … '.($to?->toDateString() ?? '—').'   (today is '.now()->toDateString().' '.config('app.timezone').')');
        }

        $this->line('matched            '.count($matched).' of '.count($rows).' rows');
    }

    private function hint(int $part, int $whole, string $message): string
    {
        return $part === $whole ? '' : "   ← {$message}";
    }
}
