<?php

namespace App\Dashboard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved "loop" definition: which column to fan out over, an optional filter
 * on which values to include, and the template metrics/charts to reproduce for
 * each value. Expanding it materialises real Section + Chart + Metric rows
 * (tagged with this loop's id) so they render through the normal pipeline.
 *
 * Two columns, not one. `column` is where the VALUES come from (loop over
 * Users · Name to get every SDR on the roster, whether or not they have data
 * yet); `scope_column` is the column the generated widgets FILTER on (Owner,
 * on the Opportunities dataset the templates read). They're usually the same
 * field, so scope_column is optional and falls back to column.
 */
class LoopStatistic extends Model
{
    protected $table = 'loop_statistics';

    protected $fillable = [
        'dashboard_id', 'section_id', 'name', 'integration_id', 'dataset',
        'column', 'scope_column', 'value_operator', 'value_match', 'templates',
        'expanded_values', 'position',
    ];

    protected $casts = [
        'templates' => 'array',
        'expanded_values' => 'array',
    ];

    /**
     * The column the generated widgets filter on. Defaults to the value column,
     * which is the right answer whenever values and widgets share a dataset.
     */
    public function filterColumn(): string
    {
        $scope = trim((string) $this->scope_column);

        return $scope !== '' ? $scope : (string) $this->column;
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }
}
