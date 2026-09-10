<?php

namespace App\Integration\Console;

use App\Integration\Models\Integration;
use App\Integration\Services\RecordReader;
use Illuminate\Console\Command;

/**
 * Dumps the column set actually in use per GHL pipeline.
 *
 * Opportunities are ONE flat dataset: every location-wide custom field that a
 * given opportunity has a value for becomes a payload key (see
 * GoHighLevelProvider::opportunityCustomFields), so RecordReader::columns()
 * returns the UNION across every pipeline. GHL has no "fields of pipeline X"
 * endpoint — custom fields are scoped to the location, not the pipeline — so a
 * per-pipeline column set can only be derived observationally: which keys are
 * actually present (or non-empty) on the rows sitting in that pipeline.
 *
 * Reads only through RecordReader, so it never touches the GHL API.
 */
class DumpPipelineColumnsCommand extends Command
{
    protected $signature = 'ghl:columns
        {integration? : Integration id (defaults to the first connected gohighlevel)}
        {--dataset=Opportunities : Dataset to inspect}
        {--group=Pipeline : Column to group by (e.g. Pipeline, Stage)}
        {--pipeline=* : Limit to these pipeline names}
        {--filled : Count a column as present only when it has a non-empty value}
        {--min-fill=0 : Hide columns filled on fewer than this % of the pipeline rows}
        {--json= : Also write the {pipeline: [columns]} map to this path}';

    protected $description = 'Dump which columns each GHL pipeline actually uses.';

    public function handle(RecordReader $reader): int
    {
        $integration = $this->resolveIntegration();

        if (! $integration) {
            $this->error('No connected GoHighLevel integration found.');

            return self::FAILURE;
        }

        $dataset = (string) $this->option('dataset');
        $groupBy = (string) $this->option('group');
        $rows = $reader->rows($integration->id, $dataset);

        if (! $rows) {
            $this->error("No synced rows for {$dataset} on integration {$integration->id} — run `php artisan integrations:sync --sync` first.");

            return self::FAILURE;
        }

        $only = array_map('mb_strtolower', (array) $this->option('pipeline'));
        $filledOnly = (bool) $this->option('filled');
        $minFill = (float) $this->option('min-fill');

        // group value => ['total' => int, 'cols' => [column => filled count]]
        $groups = [];

        foreach ($rows as $row) {
            $key = trim((string) ($row[$groupBy] ?? '')) ?: '(blank)';

            if ($only && ! in_array(mb_strtolower($key), $only, true)) {
                continue;
            }

            $groups[$key]['total'] = ($groups[$key]['total'] ?? 0) + 1;

            foreach ($row as $column => $value) {
                $isFilled = trim((string) (is_array($value) ? implode(',', $value) : $value)) !== '';

                if ($filledOnly && ! $isFilled) {
                    continue;
                }

                $groups[$key]['cols'][$column] = ($groups[$key]['cols'][$column] ?? 0) + ($isFilled ? 1 : 0);
            }
        }

        if (! $groups) {
            $this->error('No rows matched. Check --pipeline against the values in the data.');

            return self::FAILURE;
        }

        ksort($groups);

        // A column is "shared" when every group has it, "only here" otherwise —
        // that difference is the thing you actually want to see.
        $groupCount = count($groups);
        $appearsIn = [];
        foreach ($groups as $g) {
            foreach (array_keys($g['cols'] ?? []) as $column) {
                $appearsIn[$column] = ($appearsIn[$column] ?? 0) + 1;
            }
        }

        $export = [];

        foreach ($groups as $name => $g) {
            $total = $g['total'];
            $cols = $g['cols'] ?? [];
            ksort($cols);

            $table = [];

            foreach ($cols as $column => $filled) {
                $fill = $total > 0 ? round($filled / $total * 100, 1) : 0.0;

                if ($fill < $minFill) {
                    continue;
                }

                $table[] = [
                    $column,
                    $filled.'/'.$total,
                    $fill.'%',
                    ($appearsIn[$column] ?? 0) === $groupCount ? 'shared' : 'only here',
                ];
                $export[$name][] = $column;
            }

            $this->newLine();
            $this->line("<fg=cyan;options=bold>{$groupBy}: {$name}</> — {$total} rows, ".count($table).' columns');
            $this->table(['Column', 'Filled', 'Fill rate', 'Scope'], $table);
        }

        $this->newLine();
        $this->line('Union across all groups: '.count($appearsIn).' columns — this is what the builder/sheet dropdown shows today.');

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Wrote {$path}");
        }

        return self::SUCCESS;
    }

    private function resolveIntegration(): ?Integration
    {
        if ($id = $this->argument('integration')) {
            return Integration::find($id);
        }

        return Integration::where('provider', 'gohighlevel')
            ->where('status', 'connected')
            ->orderBy('id')
            ->first();
    }
}