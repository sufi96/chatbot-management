{{-- A bot's settings, a tab each. Appearance is the Profile page; the
     others are BotBrainController::TAB_NAMES, one page each, so these are
     links and not Bootstrap tab triggers. On a narrow screen the row
     scrolls rather than wrapping. --}}
@php
    $botTabs = [
        ['route' => 'bots.edit', 'params' => [$bot->id], 'icon' => 'bi-palette', 'label' => 'Appearance', 'active' => request()->routeIs('bots.edit')],
    ];
    foreach (['behaviour' => 'bi-chat-left-text', 'brain' => 'bi-diagram-2', 'connectors' => 'bi-plug',
              'files' => 'bi-eye', 'safety' => 'bi-shield-check'] as $tab => $icon) {
        $botTabs[] = ['route' => 'bots.settings', 'params' => [$bot->id, $tab], 'icon' => $icon,
            'label' => \App\Http\Controllers\BotBrainController::TAB_NAMES[$tab],
            'active' => request()->routeIs('bots.settings') && request()->route('section') === $tab];
    }
@endphp
<ul class="nav nav-tabs mb-3 flex-nowrap" style="overflow-x: auto; overflow-y: hidden;">
    @foreach($botTabs as $tab)
        <li class="nav-item">
            <a class="nav-link d-inline-flex align-items-center gap-1.5 text-nowrap {{ $tab['active'] ? 'active' : '' }}"
               href="{{ route($tab['route'], $tab['params']) }}"
               @if($tab['active']) aria-current="page" @endif>
                <i class="bi {{ $tab['icon'] }}"></i> {{ $tab['label'] }}
            </a>
        </li>
    @endforeach
</ul>
