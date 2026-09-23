<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ChatConversation;
use App\Models\KbCollection;
use App\Models\KbSource;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the Knowledge base tab of the Analytics page shows: what the
 * collections hold, how it was chunked and embedded, where it is stored, how
 * often answers drew on it in the window, and a map of its meaning.
 */
class KbAnalytics
{
    /** Chunk-length bands, as [upper bound in characters, label]. */
    public const SIZE_BANDS = [
        [200, 'Under 200'],
        [500, '200–500'],
        [1000, '500–1k'],
        [1500, '1k–1.5k'],
        [2000, '1.5k–2k'],
        [3000, '2k–3k'],
        [PHP_INT_MAX, 'Over 3k'],
    ];

    /** How many chunks the semantic map draws, at most. */
    public const MAP_SAMPLE = 600;

    /** Collections the map colours apart; the rest share "Other". */
    public const MAP_COLOURS = 6;

    public function __construct(
        /** @var Collection<int, KbCollection> */
        private Collection $collections,
        /** @var Collection<int, \App\Models\BotProfile> */
        private Collection $bots,
        private Carbon $from,
        private Carbon $to,
        private DateTimeZone $zone,
    ) {
    }

    public function report(): array
    {
        $ids = $this->collections->pluck('id')->all();

        $sources = KbSource::query()->whereIn('collection_id', $ids)
            ->get(['id', 'collection_id', 'type', 'title', 'status', 'error_message', 'chunk_count',
                'file_size', 'file_mime', 'indexed_at', 'updated_at'])
            ->keyBy('id');

        $chunks = $this->chunkStats($ids);
        $hits = $this->hits($sources);

        $collections = $this->collections->map(function (KbCollection $collection) use ($sources, $chunks, $hits) {
            $own = $sources->where('collection_id', $collection->id);
            $stats = $chunks['by_collection'][$collection->id] ?? ['chunks' => 0, 'chars' => 0, 'embedded' => 0];

            return [
                'collection' => $collection,
                'sources' => $own->count(),
                'ready' => $own->where('status', 'ready')->count(),
                'failed' => $own->where('status', 'failed')->count(),
                'pending' => $own->whereNotIn('status', ['ready', 'failed'])->count(),
                'chunks' => $stats['chunks'],
                'chars' => $stats['chars'],
                'embedded' => $stats['embedded'],
                'avg_chunk' => $stats['chunks'] ? $stats['chars'] / $stats['chunks'] : null,
                'hits' => $hits['by_collection'][$collection->id] ?? 0,
                'last_indexed' => $own->max('indexed_at'),
            ];
        })->sortByDesc('hits')->values()->all();

        $ready = $sources->where('status', 'ready');
        $citedIds = array_keys($hits['by_source']);

        $topSources = [];
        foreach (array_slice($hits['by_source'], 0, 10, true) as $id => $count) {
            $source = $sources->get($id);
            $topSources[] = [
                'id' => $id,
                'title' => $source?->title ?? $hits['titles'][$id] ?? 'Removed document',
                'collection' => $source ? $this->collections->firstWhere('id', $source->collection_id)?->name : null,
                'chunks' => $source?->chunk_count,
                'hits' => $count,
                'exists' => $source !== null,
            ];
        }

        $model = (string) AppSetting::get('embedding_model');

        return [
            'kpis' => [
                'collections' => count($ids),
                'sources' => $sources->count(),
                'ready' => $ready->count(),
                'chunks' => $chunks['total'],
                'chars' => $chunks['chars'],
                'embedded' => $chunks['embedded'],
                'avg_chunk' => $chunks['total'] ? $chunks['chars'] / $chunks['total'] : null,
                'hits' => $hits['total'],
                'kb_answers' => $hits['answers'],
                'misses' => $hits['misses'],
                'hit_rate' => ($hits['answers'] + $hits['misses']) ? $hits['answers'] / ($hits['answers'] + $hits['misses']) : null,
                'citations_per_answer' => $hits['answers'] ? $hits['total'] / $hits['answers'] : null,
                'coverage' => $ready->count() ? $ready->whereIn('id', $citedIds)->count() / $ready->count() : null,
            ],
            'collections' => $collections,
            'series' => $hits['series'],
            'hourly' => $hits['hourly'],
            'top_sources' => $topSources,
            'uncited' => $ready->whereNotIn('id', $citedIds)->sortBy('title')->take(15)->values(),
            'uncited_count' => $ready->whereNotIn('id', $citedIds)->count(),
            'attention' => $sources->filter(fn ($s) => $s->status !== 'ready' || $s->chunk_count === 0)
                ->sortBy('status')->take(12)->values(),
            'types' => $sources->countBy('type')->sortDesc()->all(),
            'statuses' => $sources->countBy('status')->sortDesc()->all(),
            'file_bytes' => (int) $sources->sum('file_size'),
            'size_bands' => $chunks['bands'],
            'size_min' => $chunks['min'],
            'size_median' => $chunks['median'],
            'size_p95' => $chunks['p95'],
            'size_max' => $chunks['max'],
            'with_heading' => $chunks['with_heading'],
            'chunks_per_source' => $ready->count() ? $ready->avg('chunk_count') : null,
            'models' => $chunks['models'],
            'stale' => $chunks['total'] - ($chunks['models'][$model] ?? 0),
            'settings' => [
                'chunk_size' => (int) AppSetting::get('chunk_size'),
                'chunk_overlap' => (int) AppSetting::get('chunk_overlap'),
                'embedding_model' => $model,
                'embedding_dimensions' => (int) AppSetting::get('embedding_dimensions'),
                'context_char_budget' => (int) AppSetting::get('context_char_budget'),
            ],
            'storage' => $this->storage($chunks),
            'map' => $this->semanticMap($ids, $sources, $citedIds),
        ];
    }

    /** Counts, sizes and models of the chunks in these collections. */
    private function chunkStats(array $ids): array
    {
        $byCollection = [];
        $models = [];
        $bands = array_fill(0, count(self::SIZE_BANDS), 0);
        $sizes = [];
        $withHeading = 0;
        $embedded = 0;

        // Read row by row, without the text or the vector, so a big knowledge
        // base costs a scan, not the memory to hold it.
        $rows = DB::table('kb_chunks')->whereIn('collection_id', $ids)
            ->selectRaw('collection_id, char_count, embedding_model, heading_path, '
                . 'CASE WHEN embedding IS NULL THEN 0 ELSE 1 END as has_vector')
            ->cursor();

        foreach ($rows as $row) {
            $size = (int) $row->char_count;
            $c = &$byCollection[$row->collection_id];
            $c ??= ['chunks' => 0, 'chars' => 0, 'embedded' => 0];
            $c['chunks']++;
            $c['chars'] += $size;
            $c['embedded'] += (int) $row->has_vector;
            unset($c);

            $embedded += (int) $row->has_vector;
            $sizes[] = $size;
            $withHeading += $row->heading_path !== null && $row->heading_path !== '' ? 1 : 0;
            $model = $row->embedding_model ?: 'none';
            $models[$model] = ($models[$model] ?? 0) + 1;
            foreach (self::SIZE_BANDS as $i => [$limit]) {
                if ($size < $limit) {
                    $bands[$i]++;
                    break;
                }
            }
        }

        sort($sizes);
        arsort($models);
        $n = count($sizes);

        return [
            'by_collection' => $byCollection,
            'total' => $n,
            'chars' => array_sum($sizes),
            'embedded' => $embedded,
            'bands' => $bands,
            'min' => $n ? $sizes[0] : null,
            'median' => $n ? $sizes[intdiv($n - 1, 2)] : null,
            'p95' => $n ? $sizes[max(0, (int) ceil(0.95 * $n) - 1)] : null,
            'max' => $n ? $sizes[$n - 1] : null,
            'with_heading' => $withHeading,
            'models' => $models,
        ];
    }

    /**
     * How often answers in the window drew on these documents. A hit is one
     * chunk cited in one answer, which is what the model was shown.
     */
    private function hits(Collection $sources): array
    {
        $analytics = new Analytics($this->bots, $this->from, $this->to, $this->zone);
        $hourly = $analytics->hourly();
        $buckets = array_map(fn ($b) => ['label' => $b['label'], 'title' => $b['title'], 'hits' => 0, 'misses' => 0],
            $analytics->buckets());

        $bySource = [];
        $byCollection = [];
        $titles = [];
        $total = 0;
        $answers = 0;
        $misses = 0;

        $rows = DB::table('chat_messages')
            ->whereIn('conversation_id', ChatConversation::query()->select('id')->whereIn('bot_id', $this->bots->pluck('id')))
            ->where('sender', 'assistant')
            ->whereIn('source_kind', ['documents', 'combined', 'none'])
            ->where('created_at', '>=', $this->from->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $this->to->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'))
            ->select(['source_kind', 'citations', 'created_at'])
            ->cursor();

        foreach ($rows as $row) {
            $key = (new DateTimeImmutable($row->created_at, new DateTimeZone('UTC')))
                ->setTimezone($this->zone)->format($hourly ? 'Y-m-d H' : 'Y-m-d');

            if ($row->source_kind === 'none') {
                $misses++;
                if (isset($buckets[$key])) {
                    $buckets[$key]['misses']++;
                }
                continue;
            }

            $answers++;
            foreach ((array) json_decode((string) $row->citations, true) as $citation) {
                if (!is_array($citation) || empty($citation['source_id'])) {
                    continue;
                }
                $id = (string) $citation['source_id'];
                $total++;
                $bySource[$id] = ($bySource[$id] ?? 0) + 1;
                $titles[$id] ??= (string) ($citation['title'] ?? 'Untitled');
                if ($source = $sources->get($id)) {
                    $byCollection[$source->collection_id] = ($byCollection[$source->collection_id] ?? 0) + 1;
                }
                if (isset($buckets[$key])) {
                    $buckets[$key]['hits']++;
                }
            }
        }

        arsort($bySource);

        return [
            'total' => $total, 'answers' => $answers, 'misses' => $misses,
            'by_source' => $bySource, 'by_collection' => $byCollection, 'titles' => $titles,
            'series' => array_values($buckets), 'hourly' => $hourly,
        ];
    }

    /** Where the chunks live, and how big that is. */
    private function storage(array $chunks): array
    {
        $driver = DB::getDriverName();
        $storage = [
            'driver' => $driver,
            'vector_type' => $driver === 'pgsql' ? 'pgvector' : 'float32 blob',
            'search' => $driver === 'pgsql' ? 'HNSW cosine + GIN full text' : 'In-memory cosine + keyword scan',
            'rows' => DB::table('kb_chunks')->count(),
            'text_bytes' => $chunks['chars'],
            'vector_bytes' => $chunks['embedded'] * (int) AppSetting::get('embedding_dimensions') * 4,
            'table_bytes' => null,
            'indexes' => [],
        ];

        if ($driver === 'pgsql') {
            try {
                $storage['table_bytes'] = (int) DB::selectOne("SELECT pg_total_relation_size('kb_chunks') AS size")->size;
                $storage['indexes'] = DB::table('pg_indexes')->where('tablename', 'kb_chunks')->pluck('indexname')->all();
            } catch (\Throwable) {
                // A role without catalogue access still gets the estimates.
            }
        }

        return $storage;
    }

    /**
     * A sample of chunks laid out by meaning: their embeddings projected onto
     * the two directions they vary most along (PCA by power iteration), so
     * chunks about the same thing land near each other. Also the cosine
     * similarity between collection centroids, for how much they overlap.
     *
     * ponytail: random sample of MAP_SAMPLE chunks, PCA in PHP; move to the
     * engine with numpy (or UMAP) if the map needs every chunk.
     */
    private function semanticMap(array $ids, Collection $sources, array $citedIds): array
    {
        $empty = ['points' => [], 'legend' => [], 'variance' => null, 'sampled' => 0, 'overlap' => []];
        if (!$ids) {
            return $empty;
        }

        $rows = DB::table('kb_chunks')->whereIn('collection_id', $ids)->whereNotNull('embedding')
            ->inRandomOrder()->limit(self::MAP_SAMPLE)
            ->get(['source_id', 'collection_id', 'ordinal', 'heading_path', 'embedding']);

        $vectors = [];
        $meta = [];
        foreach ($rows as $row) {
            $vector = self::decode($row->embedding);
            if ($vector) {
                $vectors[] = $vector;
                $meta[] = $row;
            }
        }

        // Chunks from an older model have another length; keep the commonest.
        $lengths = array_count_values(array_map('count', $vectors));
        if (!$lengths) {
            return $empty;
        }
        arsort($lengths);
        $dims = array_key_first($lengths);
        foreach ($vectors as $i => $v) {
            if (count($v) !== $dims) {
                unset($vectors[$i], $meta[$i]);
            }
        }
        $vectors = array_values($vectors);
        $meta = array_values($meta);
        $n = count($vectors);
        if ($n < 3) {
            return $empty + ['sampled' => $n];
        }

        // Centre the sample, then take the two leading components.
        $mean = array_fill(0, $dims, 0.0);
        foreach ($vectors as $v) {
            foreach ($v as $d => $x) {
                $mean[$d] += $x / $n;
            }
        }
        $total = 0.0;
        foreach ($vectors as &$v) {
            foreach ($v as $d => &$x) {
                $x -= $mean[$d];
                $total += $x * $x;
            }
            unset($x);
        }
        unset($v);

        $components = [];
        $explained = 0.0;
        foreach ([0, 1] as $k) {
            [$axis, $variance] = self::leadingComponent($vectors, $dims, $components);
            $components[] = $axis;
            $explained += $variance;
        }

        $xs = [];
        $ys = [];
        foreach ($vectors as $v) {
            $xs[] = self::dot($v, $components[0]);
            $ys[] = self::dot($v, $components[1]);
        }
        [$minX, $maxX, $minY, $maxY] = [min($xs), max($xs), min($ys), max($ys)];

        // Colour by collection, biggest in the sample first, in a fixed order.
        $counts = array_count_values(array_map(fn ($m) => $m->collection_id, $meta));
        arsort($counts);
        $slot = [];
        $legend = [];
        foreach (array_keys($counts) as $i => $collectionId) {
            $slot[$collectionId] = min($i, self::MAP_COLOURS);
            if ($i < self::MAP_COLOURS) {
                $legend[] = ['slot' => $i, 'label' => $this->collections->firstWhere('id', $collectionId)?->name ?? 'Unknown', 'count' => $counts[$collectionId]];
            }
        }
        if (count($counts) > self::MAP_COLOURS) {
            $legend[] = ['slot' => self::MAP_COLOURS, 'label' => 'Other collections',
                'count' => array_sum(array_slice($counts, self::MAP_COLOURS))];
        }

        $cited = array_flip($citedIds);
        $points = [];
        foreach ($meta as $i => $m) {
            $points[] = [
                'x' => $maxX > $minX ? ($xs[$i] - $minX) / ($maxX - $minX) : 0.5,
                'y' => $maxY > $minY ? ($ys[$i] - $minY) / ($maxY - $minY) : 0.5,
                'slot' => $slot[$m->collection_id],
                'cited' => isset($cited[$m->source_id]),
                'title' => $sources->get($m->source_id)?->title ?? 'Untitled',
                'heading' => (string) $m->heading_path,
                'ordinal' => (int) $m->ordinal,
            ];
        }

        return [
            'points' => $points,
            'legend' => $legend,
            'variance' => $total > 0 ? $explained / $total : null,
            'sampled' => $n,
            'overlap' => $this->overlap($vectors, $meta, $mean, array_keys($counts)),
        ];
    }

    /** Cosine similarity between the centroids of the biggest collections. */
    private function overlap(array $centred, array $meta, array $mean, array $order): array
    {
        $order = array_slice($order, 0, self::MAP_COLOURS);
        if (count($order) < 2) {
            return [];
        }

        // Centroids of the raw vectors: the shared mean added back.
        $sums = [];
        $counts = [];
        foreach ($centred as $i => $v) {
            $c = $meta[$i]->collection_id;
            if (!in_array($c, $order, true)) {
                continue;
            }
            $sums[$c] ??= array_fill(0, count($v), 0.0);
            foreach ($v as $d => $x) {
                $sums[$c][$d] += $x;
            }
            $counts[$c] = ($counts[$c] ?? 0) + 1;
        }
        $centroids = [];
        foreach ($sums as $c => $sum) {
            $centroids[$c] = array_map(fn ($x, $m) => $x / $counts[$c] + $m, $sum, $mean);
        }

        $names = [];
        foreach ($order as $c) {
            $names[$c] = $this->collections->firstWhere('id', $c)?->name ?? 'Unknown';
        }

        $matrix = [];
        foreach ($order as $a) {
            foreach ($order as $b) {
                $na = sqrt(self::dot($centroids[$a], $centroids[$a]));
                $nb = sqrt(self::dot($centroids[$b], $centroids[$b]));
                $matrix[$a][$b] = $na && $nb ? self::dot($centroids[$a], $centroids[$b]) / ($na * $nb) : null;
            }
        }

        return ['names' => $names, 'matrix' => $matrix];
    }

    /**
     * The direction of greatest variance in rows already centred, orthogonal
     * to $found, and the variance along it.
     *
     * @return array{0: array<int, float>, 1: float}
     */
    private static function leadingComponent(array $rows, int $dims, array $found): array
    {
        // A fixed start, so the same data draws the same map every time.
        $axis = [];
        for ($d = 0; $d < $dims; $d++) {
            $axis[] = 1.0 + ($d % 7) / 7;
        }

        for ($iteration = 0; $iteration < 25; $iteration++) {
            $axis = self::orthonormal($axis, $found);
            $next = array_fill(0, $dims, 0.0);
            foreach ($rows as $row) {
                $score = self::dot($row, $axis);
                foreach ($row as $d => $x) {
                    $next[$d] += $score * $x;
                }
            }
            if (self::dot($next, $next) == 0.0) {
                break;
            }
            $axis = $next;
        }

        $axis = self::orthonormal($axis, $found);
        $variance = 0.0;
        foreach ($rows as $row) {
            $variance += self::dot($row, $axis) ** 2;
        }

        return [$axis, $variance];
    }

    /** $axis with the directions in $found taken out, scaled to length one. */
    private static function orthonormal(array $axis, array $found): array
    {
        foreach ($found as $f) {
            $p = self::dot($axis, $f);
            foreach ($axis as $d => $x) {
                $axis[$d] = $x - $p * $f[$d];
            }
        }
        $norm = sqrt(self::dot($axis, $axis)) ?: 1.0;

        return array_map(fn ($x) => $x / $norm, $axis);
    }

    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $x) {
            $sum += $x * $b[$i];
        }

        return $sum;
    }

    /**
     * A stored embedding as numbers: pgvector reads back as "[0.1,0.2,...]",
     * SQLite holds the engine's little-endian float32 blob.
     *
     * @return array<int, float>|null
     */
    public static function decode(mixed $stored): ?array
    {
        if (is_resource($stored)) {
            $stored = stream_get_contents($stored);
        }
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        if ($stored[0] === '[') {
            $vector = json_decode($stored, true);

            return is_array($vector) ? array_map('floatval', $vector) : null;
        }
        if (strlen($stored) % 4 !== 0) {
            return null;
        }

        return array_values(unpack('g*', $stored));
    }
}
