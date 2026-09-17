<?php

namespace App\Http\Controllers;

use App\Models\BotProfile;
use App\Models\System;
use Illuminate\Http\Request;

/**
 * Every bot in every workspace, for a super admin. A workspace admin can
 * only delete a bot, which marks it; this is where a marked bot is brought
 * back or erased for good.
 */
class AdminBotController extends Controller
{
    public const STATUSES = [
        'active' => 'Active',
        'deactivated' => 'Deactivated',
        'deleted' => 'Deleted',
    ];

    public function index(Request $request)
    {
        $workspace = (string) $request->query('workspace', '');
        $search = trim((string) $request->query('q', ''));
        $status = array_key_exists($request->query('status'), self::STATUSES) ? $request->query('status') : '';

        $bots = BotProfile::withTrashed()
            ->with(['system', 'provider'])
            // The console assistant has a card of its own above the list.
            ->where('is_platform', false)
            ->when($workspace !== '', fn ($q) => $q->where('system_id', $workspace))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)) . '%';
                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(model_name) LIKE ?', [$like]));
            })
            ->when($status === 'active', fn ($q) => $q->whereNull('deleted_at')->where('is_active', true))
            ->when($status === 'deactivated', fn ($q) => $q->whereNull('deleted_at')->where('is_active', false))
            ->when($status === 'deleted', fn ($q) => $q->onlyTrashed())
            ->latest()
            ->get();

        return view('admin.bots', [
            'bots' => $bots,
            'consoleBot' => BotProfile::with('provider')->find(BotProfile::CONSOLE_ID),
            'workspaces' => System::orderBy('name')->get(),
            'workspace' => $workspace,
            'search' => $search,
            'status' => $status,
        ]);
    }

    /** Marks the bot, as deleting from its workspace does. */
    public function destroy(Request $request, string $id)
    {
        $bot = BotProfile::findOrFail($id);
        abort_if($bot->is_platform, 403, 'The console assistant cannot be deleted.');

        if ($request->input('confirm_name') !== $bot->name) {
            return back()->with('error', 'The name typed did not match, so the bot was not deleted.');
        }

        $bot->delete();

        return back()->with('success', "{$bot->name} was deleted. It can be restored from this page.");
    }

    public function restore(string $id)
    {
        $bot = BotProfile::onlyTrashed()->findOrFail($id);
        $bot->restore();

        return back()->with('success', "{$bot->name} was restored to {$bot->system?->name}.");
    }

    /** Erases a deleted bot and, through the foreign keys, its conversations. */
    public function purge(Request $request, string $id)
    {
        $bot = BotProfile::onlyTrashed()->findOrFail($id);

        if ($request->input('confirm_name') !== $bot->name) {
            return back()->with('error', 'The name typed did not match, so the bot was not erased.');
        }

        $bot->forceDelete();

        return back()->with('success', "{$bot->name} and its conversations were erased for good.");
    }
}
