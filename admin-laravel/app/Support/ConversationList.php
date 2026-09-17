<?php

namespace App\Support;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * The searchable, sortable, paged table of conversations. The Conversations
 * page and the foot of the Analytics page both draw it, so a change to one is
 * a change to both.
 */
class ConversationList
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
        'tokens' => 'desc',
        'origin' => 'asc',
        'started' => 'desc',
    ];

    /**
     * The table's rows and the state its controls show.
     *
     * @param  array<int, string>  $botIds  the bots the viewer may see and picked
     * @param  array{0: Carbon, 1: Carbon}|null  $window  only sessions with a message inside it
     * @return array{conversations: LengthAwarePaginator, search: string, perPage: int, sort: string, dir: string}
     */
    public static function fromRequest(Request $request, array $botIds, ?array $window = null, ?string $fragment = null): array
    {
        $search = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, self::PER_PAGE, true) ? $perPage : 25;
        $sort = (string) $request->query('sort', 'started');
        $sort = array_key_exists($sort, self::SORTS) ? $sort : 'started';
        $dir = $request->query('dir') === 'asc' ? 'asc' : ($request->query('dir') === 'desc' ? 'desc' : self::SORTS[$sort]);

        // The opening line, the turn count, the tokens and the bot's name come
        // back as columns, so the table can sort on them without loading every
        // message of every session on the page.
        $sum = fn (string $column) => ChatMessage::query()
            ->selectRaw("SUM({$column})")
            ->whereColumn('conversation_id', 'chat_conversations.id');

        $query = ChatConversation::query()
            ->select('chat_conversations.*')
            ->with(['bot' => fn ($q) => $q->withTrashed()->with('system')])
            ->withCount('messages')
            ->selectSub(
                ChatMessage::query()->select('content')
                    ->whereColumn('conversation_id', 'chat_conversations.id')
                    ->where('sender', 'user')
                    ->orderBy('created_at')->orderBy('id')
                    ->limit(1),
                'opening_message')
            ->selectSub(
                BotProfile::withTrashed()->select('name')->whereColumn('id', 'chat_conversations.bot_id')->limit(1),
                'bot_name')
            ->selectSub($sum('tokens_used'), 'tokens_total')
            ->selectSub($sum('tokens_in'), 'tokens_in')
            ->selectSub($sum('tokens_out'), 'tokens_out')
            ->whereIn('bot_id', $botIds);

        if ($window) {
            [$from, $to] = $window;
            $query->whereHas('messages', fn ($q) => $q
                ->where('created_at', '>=', $from->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'))
                ->where('created_at', '<', $to->copy()->setTimezone('UTC')->format('Y-m-d H:i:s')));
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
            'tokens' => $query->orderByRaw("COALESCE(tokens_total, 0) {$dir}"),
            'origin' => $query->orderByRaw("COALESCE(chat_conversations.origin, '') {$dir}"),
            'started' => $query->orderBy('chat_conversations.created_at', $dir),
        };
        // Ties keep a stable order, so a row never hops between pages.
        $query->orderBy('chat_conversations.created_at', 'desc')->orderBy('chat_conversations.id');

        $conversations = $query->paginate($perPage)->withQueryString();
        if ($fragment) {
            $conversations->fragment($fragment);
        }

        return compact('conversations', 'search', 'perPage', 'sort', 'dir');
    }
}
