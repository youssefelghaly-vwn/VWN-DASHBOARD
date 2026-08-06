<?php

namespace App\Dashboard\Controllers;

use App\Dashboard\Models\Chart;
use App\Dashboard\Models\Dashboard;
use App\Dashboard\Models\Metric;
use App\Dashboard\Models\Section;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for the titled dividers that group a dashboard's widgets. Sections nest
 * one level deep (a section may have a parent that is itself a top-level
 * section). Deleting a section frees its widgets (section_id → null) rather
 * than deleting them.
 */
class SectionController extends Controller
{
    public function index(Dashboard $dashboard)
    {
        return response()->json($this->payload($dashboard));
    }

    public function store(Request $request, Dashboard $dashboard)
    {
        $data = $this->validated($request, $dashboard);

        Section::create([
            'dashboard_id' => $dashboard->id,
            'parent_id' => $data['parent_id'] ?? null,
            'title' => $data['title'],
            'position' => (int) $dashboard->sections()->max('position') + 1,
        ]);

        return response()->json($this->payload($dashboard), 201);
    }

    public function update(Request $request, Section $section)
    {
        $data = $this->validated($request, $section->dashboard, $section);

        $update = [];

        if (array_key_exists('title', $data) && $data['title'] !== null) {
            $update['title'] = $data['title'];
        }

        // Only touch parent_id when the caller actually sent it — so a plain
        // rename never accidentally re-parents the section.
        if ($request->has('parent_id')) {
            $parentId = $data['parent_id'] ?? null;
            // Keep nesting at one level: a section that already has children
            // can't itself become someone's child.
            if ($parentId && $section->children()->exists()) {
                $parentId = null;
            }
            $update['parent_id'] = $parentId;
        }

        $section->update($update);

        return response()->json($this->payload($section->dashboard));
    }

    public function destroy(Section $section)
    {
        $dashboard = $section->dashboard;

        DB::transaction(function () use ($section) {
            // Collect this section + its sub-sections, free their widgets, then
            // delete (children cascade via the parent_id FK).
            $ids = collect([$section->id])
                ->merge($section->children()->pluck('id'))
                ->all();

            Chart::whereIn('section_id', $ids)->update(['section_id' => null]);
            Metric::whereIn('section_id', $ids)->update(['section_id' => null]);

            $section->delete();
        });

        return response()->json($this->payload($dashboard));
    }

    /** @return array<int, array<string, mixed>> */
    private function payload(Dashboard $dashboard): array
    {
        return $dashboard->sections()->get()
            ->map(fn (Section $s) => [
                'id' => $s->id,
                'parent_id' => $s->parent_id,
                'title' => $s->title,
                'position' => $s->position,
            ])->all();
    }

    private function validated(Request $request, Dashboard $dashboard, ?Section $self = null): array
    {
        $data = $request->validate([
            'title' => [$self ? 'sometimes' : 'required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        // A parent must be a top-level section of the SAME dashboard, and never
        // the section itself.
        if (! empty($data['parent_id'])) {
            $parent = Section::find($data['parent_id']);
            $valid = $parent
                && $parent->dashboard_id === $dashboard->id
                && $parent->parent_id === null
                && (! $self || $parent->id !== $self->id);

            if (! $valid) {
                $data['parent_id'] = null;
            }
        }

        return $data;
    }
}
