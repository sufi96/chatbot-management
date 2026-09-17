<?php

namespace App\Http\Controllers;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Support\BotSelection;
use App\Support\ConversationList;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');

        if (!$activeSystem) {
            return redirect()->route('systems.index');
        }

        // Every workspace this user can open, the same list the sidebar's
        // switcher offers: all of them for a super admin.
        $systems = view()->shared('userSystems') ?? collect([$activeSystem]);
        $systemIds = $systems->pluck('id');

        // A deleted bot is gone for everyone but a super admin, who can still
        // read what it said. See BotProfile::visibleTo.
        $bots = BotProfile::visibleTo($request->user())->whereIn('system_id', $systemIds)->orderBy('name')->get();

        // The picked bots. The console assistant only when a super admin
        // ticks it: "All bot profiles" means the workspaces' bots.
        $selection = BotSelection::fromRequest($request, $bots, $request->user());

        $list = ConversationList::fromRequest($request, $selection->scope->pluck('id')->all());

        return view('logs.index', $list + [
            // Workspaces by name, each with its bots, for the picker.
            'botGroups' => $systems->sortBy('name')
                ->map(fn ($system) => ['system' => $system, 'bots' => $bots->where('system_id', $system->id)->values()])
                ->filter(fn ($group) => $group['bots']->isNotEmpty())
                ->values(),
            'selectedBots' => $selection->selectedBots,
            'consoleBot' => $selection->consoleBot,
            'console' => $selection->console,
            'botQuery' => $selection->query(),
            'multiWorkspace' => $systems->count() > 1,
            'activeSystem' => $activeSystem,
        ]);
    }

    public function transcript(Request $request, string $id)
    {
        $conversation = ChatConversation::with(['bot' => fn ($q) => $q->withTrashed(), 'messages'])->findOrFail($id);
        $user = $request->user();

        // The list only offers sessions from the user's own workspaces; the
        // transcript holds to the same line, so an id alone opens nothing. A
        // deleted bot's sessions are a super admin's alone.
        abort_unless($conversation->bot && $user->canManageSystem($conversation->bot->system_id)
            && (!$conversation->bot->trashed() || $user->isSuperAdmin()), 403);

        return response()->json([
            'bot_name' => $conversation->bot->name ?? 'Bot',
            'bot_deleted' => $conversation->bot->trashed(),
            'session_id' => $conversation->session_id,
            'origin' => $conversation->origin ?: null,
            'started_at' => $conversation->created_at?->format('M j, Y · H:i'),
            'message_count' => $conversation->messages->count(),
            'models' => $this->modelsUsed($conversation),
            'messages' => $conversation->messages,
        ]);
    }

    /**
     * Each job and the models that did it across the session, for the
     * transcript header. A trace that does not parse is skipped, not shown raw.
     */
    private function modelsUsed(ChatConversation $conversation): array
    {
        $models = [];

        foreach ($conversation->messages as $message) {
            $trace = json_decode((string) $message->model_trace, true);
            if (!is_array($trace)) {
                continue;
            }
            foreach ($trace as $job => $model) {
                if (is_string($model) && $model !== '' && !in_array($model, $models[$job] ?? [], true)) {
                    $models[$job][] = $model;
                }
            }
        }

        ksort($models);

        return $models;
    }
}
