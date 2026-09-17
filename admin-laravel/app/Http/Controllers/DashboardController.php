<?php

namespace App\Http\Controllers;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\DbConnection;
use App\Models\KbCollection;
use App\Models\KbSource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The workspace at a glance: what it has and who is in it. How visitors use
 * the bots is the Analytics page's job, so this only gives last week's count
 * and points there.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $activeSystem = view()->shared('activeSystem');

        if (!$activeSystem) {
            return view('dashboard', ['hasSystems' => false]);
        }

        $bots = BotProfile::with('provider')->where('system_id', $activeSystem->id)->orderBy('name')->get();

        // The last seven days, over the workspace's own bots only: a deleted
        // bot is gone for the workspace, so its conversations are not counted.
        $since = Carbon::now()->subDays(7)->format('Y-m-d H:i:s');
        $weekMessages = ChatMessage::query()
            ->whereIn('conversation_id', ChatConversation::query()->select('id')->whereIn('bot_id', $bots->pluck('id')))
            ->where('created_at', '>=', $since);
        $weekConversations = (clone $weekMessages)->distinct()->count('conversation_id');
        $weekMessageCount = (clone $weekMessages)->where('sender', 'user')->count();

        $collectionIds = KbCollection::where('system_id', $activeSystem->id)->pluck('id');
        $sources = KbSource::whereIn('collection_id', $collectionIds)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $connections = DbConnection::where('system_id', $activeSystem->id)->get(['id', 'is_enabled']);

        $members = DB::table('system_user')->where('system_id', $activeSystem->id)
            ->selectRaw('role, COUNT(*) as total')->groupBy('role')->pluck('total', 'role');

        return view('dashboard', [
            'hasSystems' => true,
            'activeSystem' => $activeSystem,
            'bots' => $bots,
            'activeBotCount' => $bots->where('is_active', true)->count(),
            'weekConversations' => $weekConversations,
            'weekMessages' => $weekMessageCount,
            'collectionCount' => $collectionIds->count(),
            'sourceCount' => (int) $sources->sum(),
            'readySourceCount' => (int) ($sources['ready'] ?? 0),
            'failedSourceCount' => (int) ($sources['error'] ?? 0),
            'connectionCount' => $connections->count(),
            'enabledConnectionCount' => $connections->where('is_enabled', true)->count(),
            'members' => $members,
            'role' => $user->roleInSystem($activeSystem->id),
            'canEdit' => $user->canManageSystem($activeSystem->id, 'editor'),
            'apiHost' => env('API_HOST_URL', 'http://localhost:8000'),
        ]);
    }
}
