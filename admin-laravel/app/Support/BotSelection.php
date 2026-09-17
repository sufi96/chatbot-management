<?php

namespace App\Support;

use App\Models\BotProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Which bots the bot picker chose, on the Conversations and Analytics pages.
 *
 * Workspace bots travel as bots[]; none of them, or every one, means all.
 * The console assistant is apart: "All bot profiles" never includes it, and
 * only a super admin can add it, as console=1 beside the workspace bots or
 * console=only on its own.
 */
class BotSelection
{
    public const CONSOLE_WITH = '1';
    public const CONSOLE_ONLY = 'only';

    /**
     * @param  Collection<int, BotProfile>  $bots  the workspace bots the user may see
     * @param  Collection<int, BotProfile>  $scope  the bots to report on
     * @param  array<int, string>  $selectedBots  the workspace bots picked; empty is all
     * @param  string  $console  '', CONSOLE_WITH or CONSOLE_ONLY
     */
    public function __construct(
        public readonly Collection $bots,
        public readonly ?BotProfile $consoleBot,
        public readonly Collection $scope,
        public readonly array $selectedBots,
        public readonly string $console,
    ) {
    }

    public static function fromRequest(Request $request, Collection $bots, User $user): self
    {
        $consoleBot = $user->isSuperAdmin() ? BotProfile::with('provider')->find(BotProfile::CONSOLE_ID) : null;

        // Ticked bots, kept only if this user may see them. Every one ticked
        // means the same as none.
        $selected = collect((array) $request->query('bots', []))
            ->filter(fn ($id) => is_string($id))
            ->intersect($bots->pluck('id'))
            ->unique()->values();
        if ($selected->count() === $bots->count()) {
            $selected = collect();
        }

        $console = $consoleBot ? (string) $request->query('console', '') : '';
        $console = in_array($console, [self::CONSOLE_WITH, self::CONSOLE_ONLY], true) ? $console : '';

        $workspaceBots = match (true) {
            $console === self::CONSOLE_ONLY => collect(),
            $selected->isEmpty() => $bots,
            default => $bots->whereIn('id', $selected->all())->values(),
        };
        if ($console === self::CONSOLE_ONLY) {
            $selected = collect();
        }

        $scope = $console !== '' ? $workspaceBots->concat([$consoleBot])->values() : $workspaceBots;

        return new self($bots, $consoleBot, $scope, $selected->all(), $console);
    }

    /** Query parameters that pick these bots again, for links and forms. */
    public function query(): array
    {
        return array_filter([
            'bots' => $this->selectedBots ?: null,
            'console' => $this->console ?: null,
        ]);
    }

    /** Whether the choice differs from the default of every workspace bot. */
    public function narrowed(): bool
    {
        return $this->selectedBots !== [] || $this->console !== '';
    }
}
