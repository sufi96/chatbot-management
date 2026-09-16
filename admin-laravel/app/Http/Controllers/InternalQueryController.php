<?php

namespace App\Http\Controllers;

use App\Models\DbConnection;
use App\Services\Schema\DbQueryRunner;
use Illuminate\Http\Request;

/**
 * The engine asking the portal to run one statement.
 *
 * Every refusal comes back as ok:false with a reason rather than an error
 * status, because the engine turns a reason into a fall-through and an
 * exception into a stack trace.
 */
class InternalQueryController extends Controller
{
    public function query(Request $request)
    {
        $validated = $request->validate([
            'connection_id' => ['required', 'string', 'max:36'],
            'sql' => ['required', 'string', 'max:8000'],
            'max_rows' => ['required', 'integer', 'min:1', 'max:1000'],
            'timeout' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $connection = DbConnection::find($validated['connection_id']);

        if (!$connection) {
            return response()->json([
                'ok' => false, 'columns' => [], 'rows' => [], 'row_count' => 0,
                'elapsed_ms' => 0, 'message' => 'No such connection.',
            ]);
        }

        return response()->json(DbQueryRunner::run(
            $connection, $validated['sql'],
            (int) $validated['max_rows'], (int) $validated['timeout']));
    }
}
