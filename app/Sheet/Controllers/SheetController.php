<?php

namespace App\Sheet\Controllers;

use App\Http\Controllers\Controller;
use App\Integration\Services\RecordReader;
use App\Sheet\Models\Sheet;
use App\Sheet\Services\SheetData;
use Illuminate\Http\Request;

/**
 * The Sheets workspace: a read-only, Excel-style view over synced data. Each
 * sheet is a tab bound to one dataset; the browser (Tabulator + Chart.js) does
 * the filtering / lookups / charting over the rows this controller serves.
 */
class SheetController extends Controller
{
    public function index(RecordReader $reader)
    {
        return view('admin.sheets', [
            'sheets' => Sheet::orderBy('position')->orderBy('id')->get(),
            'schema' => $reader->schema(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'integration_id' => ['nullable', 'integer', 'exists:integrations,id'],
            'dataset' => ['required', 'string', 'max:190'],
        ]);

        $sheet = Sheet::create($data + [
            'user_id' => $request->user()->id,
            'config' => [],
            'position' => (int) Sheet::max('position') + 1,
        ]);

        return response()->json($this->present($sheet), 201);
    }

    public function data(Sheet $sheet, SheetData $sheetData)
    {
        return response()->json($this->present($sheet) + $sheetData->payload($sheet));
    }

    public function update(Request $request, Sheet $sheet)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'config' => ['sometimes', 'array'],
        ]);

        $sheet->update($data);

        return response()->json($this->present($sheet));
    }

    public function destroy(Sheet $sheet)
    {
        $sheet->delete();

        return response()->noContent();
    }

    /** The non-row metadata the front end needs to render a tab. */
    private function present(Sheet $sheet): array
    {
        return [
            'id' => $sheet->id,
            'name' => $sheet->name,
            'integration_id' => $sheet->integration_id,
            'dataset' => $sheet->dataset,
            'config' => $sheet->config ?? [],
        ];
    }
}
