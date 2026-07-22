<?php

namespace App\Sheet\Services;

use App\Integration\Services\RecordReader;
use App\Sheet\Models\Sheet;

/**
 * Builds a sheet's row payload from local synced data. Reads ONLY through the
 * RecordReader (never an external API), then resolves any configured VLOOKUP
 * columns by joining a second dataset on a key — the read-only, Excel-style
 * lookup the workspace is built around.
 *
 * Filtering, sorting, grouping, totals and charts are applied client-side by
 * the grid (Tabulator) over the rows this returns; the server's job is just to
 * assemble the base rows plus resolved lookup values.
 */
class SheetData
{
    public function __construct(private RecordReader $reader) {}

    /**
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, lookups: array<int, string>}
     */
    public function payload(Sheet $sheet): array
    {
        $rows = $this->reader->rows($sheet->integration_id, $sheet->dataset);
        $columns = $this->reader->columns($sheet->integration_id, $sheet->dataset);

        $lookupColumns = [];

        foreach ($this->lookups($sheet) as $lookup) {
            $name = trim((string) ($lookup['name'] ?? ''));

            // Skip half-configured lookups rather than blowing up the sheet.
            if ($name === '' || empty($lookup['local_key']) || empty($lookup['foreign_key']) || empty($lookup['return_column'])) {
                continue;
            }

            $index = $this->indexForeign(
                $lookup['integration_id'] ?? null,
                (string) ($lookup['dataset'] ?? ''),
                (string) $lookup['foreign_key'],
                (string) $lookup['return_column'],
            );

            foreach ($rows as &$row) {
                $key = $this->normalize($row[$lookup['local_key']] ?? null);
                $row[$name] = ($key !== '' && array_key_exists($key, $index)) ? $index[$key] : '';
            }
            unset($row);

            $lookupColumns[] = $name;
        }

        return [
            'columns' => array_values(array_unique([...$columns, ...$lookupColumns])),
            'rows' => array_values($rows),
            'lookups' => $lookupColumns,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function lookups(Sheet $sheet): array
    {
        $lookups = $sheet->config['lookups'] ?? [];

        return is_array($lookups) ? $lookups : [];
    }

    /**
     * Map of normalized foreign-key value → return-column value for a dataset.
     * First match wins, mirroring spreadsheet VLOOKUP's "first exact match".
     *
     * @return array<string, mixed>
     */
    private function indexForeign(?int $integrationId, string $dataset, string $foreignKey, string $returnColumn): array
    {
        $index = [];

        foreach ($this->reader->rows($integrationId, $dataset) as $row) {
            $key = $this->normalize($row[$foreignKey] ?? null);

            if ($key === '' || array_key_exists($key, $index)) {
                continue;
            }

            $index[$key] = $row[$returnColumn] ?? '';
        }

        return $index;
    }

    private function normalize(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
