<?php

namespace App\Http\Controllers;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /** The sizes the per-page picker offers; anything else falls back to the default. */
    public const PER_PAGE = [10, 25, 50, 100];

    /**
     * The sortable columns, each with the direction a first click sorts in:
     * names read A to Z, counts and dates read biggest and newest first.
     */
    public const SORTS = [
        'bot' => 'asc',
        'opening' => 'asc',
        'turns' => 'desc',
        'origin' => 'asc',
        'started' => 'desc',
    ];

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

        $bots = BotProfile::whereIn('system_id', $systemIds)->orderBy('name')->get();

        // Ticked bots, kept only if this user may see them. None ticked, or
        // every one ticked, both mean all.
        $selectedBots = collect((array) $request->query('bots', []))
            ->filter(fn ($id) => is_string($id))
            ->intersect($bots->pluck('id'))
            ->unique()->values();
        if ($selectedBots->count() === $bots->count()) {
            $selectedBots = collect();
        }

        $search = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, self::PER_PAGE, true) ? $perPage : 25;
        $sort = (string) $request->query('sort', 'started');
        $sort = array_key_exists($sort, self::SORTS) ? $sort : 'started';
        $dir = $request->query('dir') === 'asc' ? 'asc' : ($request->query('dir') === 'desc' ? 'desc' : self::SORTS[$sort]);

        // The opening line, the turn count and the bot's name come back as
        // columns, so the table can sort on them without loading every
        // message of every session on the page.
        $query = ChatConversation::query()
            ->select('chat_conversations.*')
            ->with('bot.system')
            ->withCount('messages')
            ->selectSub(
                ChatMessage::query()->select('content')
                    ->whereColumn('conversation_id', 'chat_conversations.id')
                    ->where('sender', 'user')
                    ->orderBy('created_at')->orderBy('id')
                    ->limit(1),
                'opening_message')
            ->selectSub(
                BotProfile::query()->select('name')->whereColumn('id', 'chat_conversations.bot_id')->limit(1),
                'bot_name')
            ->whereIn('bot_id', BotProfile::query()->select('id')->whereIn('system_id', $systemIds));

        if ($selectedBots->isNotEmpty()) {
            $query->whereIn('bot_id', $selectedBots->all());
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                // What was typed is matched as text: % and _ are escaped with
                // "!", which, unlike a backslash, means the same in every SQL.
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
                $match = fn (string $column) => "LOWER({$column}) LIKE ? ESCAPE '!'";

                $q->whereRaw($match('chat_conversations.session_id'), [$like])
                    ->orWhereRaw($match("COALESCE(chat_conversations.origin, '')"), [$like])
                    ->orWhereExists(fn ($sub) => $sub->from('chat_messages')
                        ->whereColumn('chat_messages.conversation_id', 'chat_conversations.id')
                        ->whereRaw($match('chat_messages.content'), [$like]))
                    ->orWhereExists(fn ($sub) => $sub->from('bot_profiles')
                        ->whereColumn('bot_profiles.id', 'chat_conversations.bot_id')
                        ->whereRaw($match('bot_profiles.name'), [$like]));
            });
        }

        match ($sort) {
            'bot' => $query->orderBy('bot_name', $dir),
            'opening' => $query->orderBy('opening_message', $dir),
            'turns' => $query->orderBy('messages_count', $dir),
            'origin' => $query->orderByRaw("COALESCE(chat_conversations.origin, '') {$dir}"),
            'started' => $query->orderBy('chat_conversations.created_at', $dir),
        };
        // Ties keep a stable order, so a row never hops between pages.
        $query->orderBy('chat_conversations.created_at', 'desc')->orderBy('chat_conversations.id');

        $conversations = $query->paginate($perPage)->withQueryString();

        return view('logs.index', [
            'conversations' => $conversations,
            // Workspaces by name, each with its bots, for the picker.
            'botGroups' => $systems->sortBy('name')
                ->map(fn ($system) => ['system' => $system, 'bots' => $bots->where('system_id', $system->id)->values()])
                ->filter(fn ($group) => $group['bots']->isNotEmpty())
                ->values(),
            'selectedBots' => $selectedBots->all(),
            'multiWorkspace' => $systems->count() > 1,
            'search' => $search,
            'perPage' => $perPage,
            'sort' => $sort,
            'dir' => $dir,
            'activeSystem' => $activeSystem,
        ]);
    }

    public function transcript(Request $request, string $id)
    {
        $conversation = ChatConversation::with(['bot', 'messages'])->findOrFail($id);

        // The list only offers sessions from the user's own workspaces; the
        // transcript holds to the same line, so an id alone opens nothing.
        abort_unless($conversation->bot && $request->user()->canManageSystem($conversation->bot->system_id), 403);

        return response()->json([
            'bot_name' => $conversation->bot->name ?? 'Bot',
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
