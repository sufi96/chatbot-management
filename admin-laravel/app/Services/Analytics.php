<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\KbSource;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the Analytics page shows for a set of bots, over one window of
 * time. The caller decides which bots the viewer may see.
 *
 * Messages are read once, row by row, and counted in PHP rather than grouped
 * in SQL: hours and weekdays in the viewer's time zone are spelt differently
 * in SQLite and PostgreSQL, and one pass keeps the numbers on the page in
 * agreement with each other.
 */
class Analytics
{
    /** The sources an answer can name, in the order the page lists them. */
    public const SOURCE_KINDS = [
        'documents' => 'Knowledge base',
        'database' => 'Live database',
        'web' => 'Web search',
        'combined' => 'Knowledge base and database',
        'none' => 'Searched, nothing found',
        'model' => 'Model only, nothing searched',
        'refused' => 'Refused by the guard',
    ];

    /** Response-time bands, as [upper bound in ms, label]. */
    public const LATENCY_BANDS = [
        [1000, 'Under 1s'],
        [3000, '1–3s'],
        [10000, '3–10s'],
        [30000, '10–30s'],
        [PHP_INT_MAX, 'Over 30s'],
    ];

    private DateTimeZone $utc;

    public function __construct(
        /** @var Collection<int, \App\Models\BotProfile> */
        private Collection $bots,
        private Carbon $from,
        private Carbon $to,
        private DateTimeZone $zone,
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    public function report(): array
    {
        $current = $this->pass($this->from, $this->to, true);

        // The window just before, as long as this one, for the change arrows.
        $length = $this->from->diffInSeconds($this->to);
        $previous = $this->pass($this->from->copy()->subSeconds($length), $this->from->copy(), false);

        return $current + [
            'previous' => $previous['kpis'],
            'flagged' => $this->flaggedExchanges(),
            'gaps' => $this->knowledgeGaps(),
            'unused_documents' => $this->unusedDocuments($current['cited_source_ids']),
            'by_bot' => $this->byBot(),
        ];
    }

    private function botIds(): array
    {
        return $this->bots->pluck('id')->all();
    }

    /** The conversations of these bots, as a subquery. */
    private function conversationIds()
    {
        return ChatConversation::query()->select('id')->whereIn('bot_id', $this->botIds());
    }

    private function utcString(Carbon $moment): string
    {
        return $moment->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
    }

    /** A stored timestamp, read as UTC and turned to the viewer's zone. */
    private function local(string $stored): DateTimeImmutable
    {
        return (new DateTimeImmutable($stored, $this->utc))->setTimezone($this->zone);
    }

    private function inWindow($query, Carbon $from, Carbon $to)
    {
        return $query->whereIn('conversation_id', $this->conversationIds())
            ->where('created_at', '>=', $this->utcString($from))
            ->where('created_at', '<', $this->utcString($to));
    }

    /** Whether the window is short enough to chart by the hour. */
    public function hourly(): bool
    {
        return $this->from->diffInHours($this->to) <= 48;
    }

    /** The empty buckets of the activity chart, keyed by their start. */
    public function buckets(): array
    {
        $hourly = $this->hourly();
        $cursor = $this->from->copy()->setTimezone($this->zone);
        $cursor = $hourly ? $cursor->startOfHour() : $cursor->startOfDay();
        $end = $this->to->copy()->setTimezone($this->zone);

        $buckets = [];
        while ($cursor < $end) {
            $buckets[$cursor->format($hourly ? 'Y-m-d H' : 'Y-m-d')] = [
                'label' => $cursor->format($hourly ? 'H:00' : 'M j'),
                'title' => $cursor->format($hourly ? 'D M j, H:00' : 'D M j, Y'),
                'messages' => 0, 'conversations' => 0, 'tokens' => 0, 'flagged' => 0,
            ];
            $hourly ? $cursor->addHour() : $cursor->addDay();
        }

        return $buckets;
    }

    private function pass(Carbon $from, Carbon $to, bool $full): array
    {
        $hourly = $this->hourly();
        $buckets = $full ? $this->buckets() : [];
        $heatmap = array_fill(0, 7, array_fill(0, 24, 0));

        $visitor = 0;
        $replies = 0;
        $tokens = 0;
        $flagged = 0;
        $active = [];
        $intents = ['facts' => 0, 'chat' => 0, 'unread' => 0];

        $sources = array_fill_keys(array_keys(self::SOURCE_KINDS), 0);
        $untracked = 0;
        $firstToken = [];
        $response = [];
        $bands = array_fill(0, count(self::LATENCY_BANDS), 0);
        $flagCategories = [];
        $cited = [];
        $domains = [];
        $dbAnswers = 0;
        $dbRows = 0;
        $models = [];

        $columns = ['conversation_id', 'sender', 'created_at', 'tokens_used', 'intent', 'guard_flag',
            'source_kind', 'first_token_ms', 'response_ms'];
        if ($full) {
            array_push($columns, 'citations', 'db_sql', 'db_row_count', 'model_trace');
        }

        $rows = $this->inWindow(DB::table('chat_messages'), $from, $to)
            ->select($columns)->orderBy('created_at')->cursor();

        foreach ($rows as $row) {
            $active[$row->conversation_id] = true;
            $at = $full ? $this->local($row->created_at) : null;
            $bucket = $at?->format($hourly ? 'Y-m-d H' : 'Y-m-d');

            if ($row->guard_flag) {
                $flagged++;
                if ($full) {
                    $flagCategories[$row->guard_flag] = ($flagCategories[$row->guard_flag] ?? 0) + 1;
                    if (isset($buckets[$bucket])) {
                        $buckets[$bucket]['flagged']++;
                    }
                }
            }

            if ($row->sender === 'user') {
                $visitor++;
                if (!$full) {
                    continue;
                }
                if (isset($buckets[$bucket])) {
                    $buckets[$bucket]['messages']++;
                }
                // Monday first, the way a working week reads.
                $heatmap[(int) $at->format('N') - 1][(int) $at->format('G')]++;
                $intents[$row->intent === 'facts' || $row->intent === 'chat' ? $row->intent : 'unread']++;
                continue;
            }

            if ($row->sender !== 'assistant') {
                continue;
            }

            $replies++;
            $tokens += (int) $row->tokens_used;

            if ($row->source_kind !== null && isset($sources[$row->source_kind])) {
                $sources[$row->source_kind]++;
            } else {
                $untracked++;
            }
            if ($row->first_token_ms !== null) {
                $firstToken[] = (int) $row->first_token_ms;
            }
            if ($row->response_ms !== null) {
                $response[] = (int) $row->response_ms;
            }

            if (!$full) {
                continue;
            }

            if (isset($buckets[$bucket])) {
                $buckets[$bucket]['tokens'] += (int) $row->tokens_used;
            }
            if ($row->response_ms !== null) {
                foreach (self::LATENCY_BANDS as $i => [$limit]) {
                    if ($row->response_ms < $limit) {
                        $bands[$i]++;
                        break;
                    }
                }
            }
            if ($row->db_sql) {
                $dbAnswers++;
                $dbRows += (int) $row->db_row_count;
            }
            foreach ((array) json_decode((string) $row->model_trace, true) as $job => $model) {
                if (is_string($model) && $model !== '') {
                    $models[$job][$model] = ($models[$job][$model] ?? 0) + 1;
                }
            }
            foreach ((array) json_decode((string) $row->citations, true) as $citation) {
                if (!is_array($citation)) {
                    continue;
                }
                if (!empty($citation['url'])) {
                    $host = parse_url((string) $citation['url'], PHP_URL_HOST) ?: (string) $citation['url'];
                    $domains[$host] = ($domains[$host] ?? 0) + 1;
                } elseif (in_array($row->source_kind, ['documents', 'combined'], true) && !empty($citation['source_id'])) {
                    $id = (string) $citation['source_id'];
                    $cited[$id] ??= ['title' => (string) ($citation['title'] ?? 'Untitled'), 'count' => 0];
                    $cited[$id]['count']++;
                }
            }
        }

        // The conversations started in the window, which is not the same as
        // the ones active in it: a visitor can come back to an old session.
        $started = ChatConversation::query()->whereIn('bot_id', $this->botIds())
            ->where('created_at', '>=', $this->utcString($from))
            ->where('created_at', '<', $this->utcString($to));
        $startedCount = (clone $started)->count();

        $activeIds = array_keys($active);
        $bounced = $this->bounced($activeIds);

        $searched = $sources['documents'] + $sources['database'] + $sources['web'] + $sources['combined'] + $sources['none'];

        $kpis = [
            'conversations' => count($activeIds),
            'new_conversations' => $startedCount,
            'visitor_messages' => $visitor,
            'replies' => $replies,
            'messages_per_conversation' => count($activeIds) ? ($visitor + $replies) / count($activeIds) : null,
            'tokens' => $tokens,
            'tokens_per_reply' => $replies ? $tokens / $replies : null,
            'first_token_median' => $this->percentile($firstToken, 50),
            'response_median' => $this->percentile($response, 50),
            'response_p95' => $this->percentile($response, 95),
            'answer_rate' => $searched ? ($searched - $sources['none']) / $searched : null,
            'flagged' => $flagged,
            'flag_rate' => $visitor ? $flagged / $visitor : null,
            'bounce_rate' => count($activeIds) ? $bounced / count($activeIds) : null,
        ];

        if (!$full) {
            return ['kpis' => $kpis];
        }

        foreach ($started->pluck('created_at') as $createdAt) {
            $key = $createdAt->copy()->setTimezone($this->zone)->format($hourly ? 'Y-m-d H' : 'Y-m-d');
            if (isset($buckets[$key])) {
                $buckets[$key]['conversations']++;
            }
        }

        arsort($flagCategories);
        arsort($domains);
        uasort($cited, fn ($a, $b) => $b['count'] <=> $a['count']);
        ksort($models);
        foreach ($models as &$byModel) {
            arsort($byModel);
        }
        unset($byModel);

        return [
            'kpis' => $kpis,
            'hourly' => $hourly,
            'series' => array_values($buckets),
            'heatmap' => $heatmap,
            'origins' => $this->origins($activeIds),
            'returning' => max(0, count($activeIds) - $this->startedAmong($activeIds, $from, $to)),
            'intents' => $intents,
            'intent_enabled' => $this->bots->contains(fn ($bot) => (bool) $bot->intent_enabled),
            'top_questions' => $this->topQuestions($from, $to),
            'sources' => $sources,
            'untracked' => $untracked,
            'latency_bands' => $bands,
            'first_token_p95' => $this->percentile($firstToken, 95),
            'timed_replies' => count($response),
            'flag_categories' => $flagCategories,
            'cited' => array_slice($cited, 0, 10, true),
            'cited_source_ids' => array_keys($cited),
            'domains' => array_slice($domains, 0, 8, true),
            'db_answers' => $dbAnswers,
            'db_rows_avg' => $dbAnswers ? $dbRows / $dbAnswers : null,
            'models' => $models,
        ];
    }

    /** The nearest-rank percentile of a list of numbers, or null for none. */
    private function percentile(array $values, int $percent): ?int
    {
        if (!$values) {
            return null;
        }
        sort($values);

        return $values[max(0, (int) ceil($percent / 100 * count($values)) - 1)];
    }

    /** How many of these conversations the visitor spoke in only once, ever. */
    private function bounced(array $conversationIds): int
    {
        $bounced = 0;
        foreach (array_chunk($conversationIds, 500) as $chunk) {
            $bounced += DB::table('chat_messages')
                ->select('conversation_id')
                ->whereIn('conversation_id', $chunk)
                ->where('sender', 'user')
                ->groupBy('conversation_id')
                ->havingRaw('COUNT(*) = 1')
                ->get()->count();
        }

        return $bounced;
    }

    /** How many of these conversations began inside the window. */
    private function startedAmong(array $conversationIds, Carbon $from, Carbon $to): int
    {
        $count = 0;
        foreach (array_chunk($conversationIds, 500) as $chunk) {
            $count += ChatConversation::query()->whereIn('id', $chunk)
                ->where('created_at', '>=', $this->utcString($from))
                ->where('created_at', '<', $this->utcString($to))
                ->count();
        }

        return $count;
    }

    /** The sites the active conversations came from, busiest first. */
    private function origins(array $conversationIds): array
    {
        $origins = [];
        foreach (array_chunk($conversationIds, 500) as $chunk) {
            $rows = ChatConversation::query()->whereIn('id', $chunk)
                ->selectRaw("COALESCE(origin, '') as origin, COUNT(*) as total")
                ->groupBy(DB::raw("COALESCE(origin, '')"))
                ->get();
            foreach ($rows as $row) {
                $origin = $row->origin === '' ? 'preview sandbox' : $row->origin;
                $origins[$origin] = ($origins[$origin] ?? 0) + (int) $row->total;
            }
        }
        arsort($origins);

        return array_slice($origins, 0, 8, true);
    }

    /**
     * The questions visitors asked most, matched after folding case, spacing
     * and trailing punctuation, so "Opening hours?" and "opening hours" count
     * as one. Small talk is left out: nobody needs a ranking of "hi".
     */
    private function topQuestions(Carbon $from, Carbon $to): array
    {
        $counts = [];
        $shown = [];

        $rows = $this->inWindow(DB::table('chat_messages'), $from, $to)
            ->where('sender', 'user')
            ->where(fn ($q) => $q->whereNull('intent')->orWhere('intent', '!=', 'chat'))
            // A refused message is listed under the guard, not ranked here.
            ->whereNull('guard_flag')
            ->select(['content'])->cursor();

        foreach ($rows as $row) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $row->content));
            $key = rtrim(mb_strtolower($text), " ?!.");
            if ($key === '' || mb_strlen($key) > 300) {
                continue;
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $shown[$key] ??= $text;
        }

        arsort($counts);

        $top = [];
        foreach (array_slice($counts, 0, 10, true) as $key => $count) {
            $top[] = ['text' => $shown[$key], 'count' => $count];
        }

        return $top;
    }

    /** The latest refused messages and flagged answers. */
    private function flaggedExchanges()
    {
        return $this->inWindow(ChatMessage::query(), $this->from, $this->to)
            ->whereNotNull('guard_flag')
            ->with(['conversation.bot' => fn ($q) => $q->withTrashed()])
            ->select(['id', 'conversation_id', 'sender', 'content', 'guard_flag', 'created_at'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    /**
     * The latest questions the sources had nothing for, each with the answer
     * the visitor got. These are what the knowledge base is missing.
     */
    private function knowledgeGaps(): array
    {
        $answers = $this->inWindow(ChatMessage::query(), $this->from, $this->to)
            ->where('sender', 'assistant')
            ->where('source_kind', 'none')
            ->with(['conversation.bot' => fn ($q) => $q->withTrashed()])
            ->select(['id', 'conversation_id', 'content', 'created_at'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return $answers->map(function (ChatMessage $answer) {
            $question = ChatMessage::query()
                ->where('conversation_id', $answer->conversation_id)
                ->where('sender', 'user')
                ->where('created_at', '<=', $answer->getRawOriginal('created_at'))
                ->orderByDesc('created_at')
                ->value('content');

            return [
                'conversation_id' => $answer->conversation_id,
                'bot' => $answer->conversation?->bot?->name,
                'question' => $question,
                'answer' => $answer->content,
                'at' => $answer->created_at,
            ];
        })->all();
    }

    /** Ready documents in these bots' collections that no answer cited. */
    private function unusedDocuments(array $citedIds)
    {
        $collectionIds = DB::table('bot_kb_collection')->whereIn('bot_id', $this->botIds())
            ->distinct()->pluck('collection_id');
        if ($collectionIds->isEmpty()) {
            return collect();
        }

        return KbSource::query()
            ->whereIn('collection_id', $collectionIds)
            ->where('status', 'ready')
            ->when($citedIds, fn ($q) => $q->whereNotIn('id', $citedIds))
            ->orderBy('title')
            ->limit(12)
            ->get(['id', 'title', 'type']);
    }

    /**
     * The headline figures of each bot, so one busy or struggling bot can be
     * picked out of a workspace's totals. Grouped in SQL, which is plain
     * counting here and the same in every database.
     */
    private function byBot(): array
    {
        $from = $this->utcString($this->from);
        $to = $this->utcString($this->to);

        $rows = DB::table('chat_messages as m')
            ->join('chat_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereIn('c.bot_id', $this->botIds())
            ->where('m.created_at', '>=', $from)
            ->where('m.created_at', '<', $to)
            ->groupBy('c.bot_id', 'm.sender', 'm.source_kind')
            ->selectRaw('c.bot_id, m.sender, m.source_kind, COUNT(*) as total, '
                . 'SUM(CASE WHEN m.guard_flag IS NULL THEN 0 ELSE 1 END) as flagged, '
                . 'SUM(COALESCE(m.tokens_used, 0)) as tokens')
            ->get();

        $conversations = DB::table('chat_messages as m')
            ->join('chat_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereIn('c.bot_id', $this->botIds())
            ->where('m.created_at', '>=', $from)
            ->where('m.created_at', '<', $to)
            ->groupBy('c.bot_id')
            ->selectRaw('c.bot_id, COUNT(DISTINCT m.conversation_id) as total')
            ->pluck('total', 'bot_id');

        $figures = [];
        foreach ($this->bots as $bot) {
            $figures[$bot->id] = [
                'bot' => $bot,
                'conversations' => (int) ($conversations[$bot->id] ?? 0),
                'visitor_messages' => 0, 'replies' => 0, 'tokens' => 0, 'flagged' => 0,
                'searched' => 0, 'missed' => 0,
            ];
        }

        foreach ($rows as $row) {
            $f = &$figures[$row->bot_id];
            $f['flagged'] += (int) $row->flagged;
            if ($row->sender === 'user') {
                $f['visitor_messages'] += (int) $row->total;
            } elseif ($row->sender === 'assistant') {
                $f['replies'] += (int) $row->total;
                $f['tokens'] += (int) $row->tokens;
                if (in_array($row->source_kind, ['documents', 'database', 'web', 'combined', 'none'], true)) {
                    $f['searched'] += (int) $row->total;
                }
                if ($row->source_kind === 'none') {
                    $f['missed'] += (int) $row->total;
                }
            }
            unset($f);
        }

        foreach ($figures as &$f) {
            $f['answer_rate'] = $f['searched'] ? ($f['searched'] - $f['missed']) / $f['searched'] : null;
        }
        unset($f);

        // Busiest first; bots with nothing in the window keep their name order.
        uasort($figures, fn ($a, $b) => [$b['visitor_messages'], $a['bot']->name] <=> [$a['visitor_messages'], $b['bot']->name]);

        return array_values($figures);
    }
}
