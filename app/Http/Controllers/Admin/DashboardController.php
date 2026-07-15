<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chart;
use App\Services\SourceManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index(SourceManager $sources)
    {
        try {
            $schema = $sources->active()->schema();
        } catch (\Throwable $e) {
            return view('admin.dashboard', [
                'error'  => $e->getMessage(),
                'schema' => [],
                'charts' => [],
                'source' => $sources->activeKey(),
            ]);
        }

        return view('admin.dashboard', [
            'schema' => $schema,
            'charts' => Chart::query()
                ->where('user_id', Auth::id())
                ->orderBy('position')
                ->get(),
            'error'  => null,
            'source' => $sources->activeKey(),
        ]);
    }

    public function chartData(SourceManager $sources)
    {
        $source = $sources->active();

        $payload = Chart::query()
            ->where('user_id', Auth::id())
            ->orderBy('position')
            ->get()
            ->map(fn (Chart $c) => $source->buildChart($c));

        return response()->json($payload);
    }

    public function tableData(Request $request, SourceManager $sources)
    {
        $request->validate(['sheet' => ['required', 'string']]);

        $source = $sources->active();

        return response()->json([
            'columns' => $source->columns($request->string('sheet')),
            'rows'    => $source->sheet($request->string('sheet')),
        ]);
    }

    public function sync(SourceManager $sources)
    {
        $sources->active()->all(fresh: true);

        return back()->with('status', 'Synced.');
    }
}