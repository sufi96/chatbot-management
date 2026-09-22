<?php

namespace App\Http\Controllers;

use App\Models\BotProfile;
use App\Models\KbCollection;
use App\Services\Analytics;
use App\Services\KbAnalytics;
use App\Support\BotSelection;
use App\Support\ConversationList;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    /** The preset windows, each as a label and its length in hours. */
    public const RANGES = [
        '24h' => ['label' => '24 hours', 'hours' => 24],
        '7d' => ['label' => '7 days', 'hours' => 24 * 7],
        '30d' => ['label' => '30 days', 'hours' => 24 * 30],
        '90d' => ['label' => '90 days', 'hours' => 24 * 90],
    ];

    /** The longest custom window, so one request cannot read years of rows. */
    public const MAX_CUSTOM_DAYS = 366;

    public function index(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');

        if (!$activeSystem) {
            return redirect()->route('systems.index');
        }

        // Every workspace this user can open, the same list the sidebar's
        // switcher offers: all of them for a super admin.
        $systems = view()->shared('userSystems') ?? collect([$activeSystem]);

        // A super admin also sees deleted bots, so nothing a bot said is
        // out of reach while it can still be restored.
        $bots = BotProfile::visibleTo($request->user())
            ->whereIn('system_id', $systems->pluck('id'))->with('system')->orderBy('name')->get();

        // The picked bots. The console assistant only when a super admin
        // ticks it: "All bot profiles" means the workspaces' bots.
        $selection = BotSelection::fromRequest($request, $bots, $request->user());
        $inScope = $selection->scope;

        $zone = $this->zone((string) $request->query('tz', ''));
        [$range, $from, $to] = $this->window($request, $zone);

        // The same table as the Conversations page, held to these bots and
        // this window, and linked back to its own place on the page.
        $list = ConversationList::fromRequest($request, $inScope->pluck('id')->all(), [$from, $to], 'conversations');

        // Bot analytics, or the knowledge base tab. Only the open tab is built.
        $tab = $request->query('tab') === 'kb' ? 'kb' : 'bots';
        $canReport = $bots->isNotEmpty() || $selection->consoleBot;

        $kbReport = null;
        if ($canReport && $tab === 'kb') {
            // Every collection when no bot is picked, else the picked bots' own.
            $collections = KbCollection::query()->whereIn('system_id', $systems->pluck('id'))
                ->when($selection->selectedBots || $selection->console,
                    fn ($q) => $q->whereHas('bots', fn ($b) => $b->whereIn('bot_profiles.id', $inScope->pluck('id'))))
                ->with('system')->withCount('bots')->orderBy('name')->get();
            $kbReport = (new KbAnalytics($collections, $inScope, $from, $to, $zone))->report();
        }

        return view('analytics.index', $list + [
            'tab' => $tab,
            'canReport' => $canReport,
            'kbReport' => $kbReport,
            'report' => $canReport && $tab === 'bots' ? (new Analytics($inScope, $from, $to, $zone))->report() : null,
            'bots' => $inScope,
            'botGroups' => $systems->sortBy('name')
                ->map(fn ($system) => ['system' => $system, 'bots' => $bots->where('system_id', $system->id)->values()])
                ->filter(fn ($group) => $group['bots']->isNotEmpty())
                ->values(),
            'selectedBots' => $selection->selectedBots,
            'consoleBot' => $selection->consoleBot,
            'console' => $selection->console,
            'botQuery' => $selection->query(),
            'multiWorkspace' => $systems->count() > 1,
            'range' => $range,
            'from' => $from->copy()->setTimezone($zone),
            'to' => $to->copy()->setTimezone($zone),
            'zone' => $zone->getName(),
            'activeSystem' => $activeSystem,
        ]);
    }

    /** The viewer's time zone, when it is a real one, or the app's. */
    private function zone(string $name): DateTimeZone
    {
        return in_array($name, DateTimeZone::listIdentifiers(), true)
            ? new DateTimeZone($name)
            : new DateTimeZone(config('app.timezone'));
    }

    /**
     * The window to report on: a preset ending now, or whole days picked by
     * the viewer, read in their own time zone.
     *
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    private function window(Request $request, DateTimeZone $zone): array
    {
        $range = (string) $request->query('range', '7d');

        if ($range === 'custom') {
            try {
                $from = Carbon::createFromFormat('!Y-m-d', (string) $request->query('from'), $zone);
                $to = Carbon::createFromFormat('!Y-m-d', (string) $request->query('to'), $zone);
            } catch (\Throwable) {
                $from = $to = false;
            }

            if ($from && $to) {
                if ($from > $to) {
                    [$from, $to] = [$to, $from];
                }
                // The last day picked is included whole.
                $to = $to->addDay();
                if ($from->diffInDays($to) > self::MAX_CUSTOM_DAYS) {
                    $from = $to->copy()->subDays(self::MAX_CUSTOM_DAYS);
                }

                return ['custom', $from, $to];
            }

            $range = '7d';
        }

        $range = array_key_exists($range, self::RANGES) ? $range : '7d';
        $to = Carbon::now($zone);

        return [$range, $to->copy()->subHours(self::RANGES[$range]['hours']), $to];
    }
}
