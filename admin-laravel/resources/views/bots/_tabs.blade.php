{{-- Profile and Behaviour are two halves of one bot's settings. A tab each makes
     Behaviour read as part of the bot rather than as an action in the page head.
     Each tab is its own page, so these are links and not Bootstrap tab
     triggers. --}}
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link d-inline-flex align-items-center gap-1.5 {{ request()->routeIs('bots.edit') ? 'active' : '' }}"
           href="{{ route('bots.edit', $bot->id) }}"
           @if(request()->routeIs('bots.edit')) aria-current="page" @endif>
            <i class="bi bi-person-badge"></i> Profile
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link d-inline-flex align-items-center gap-1.5 {{ request()->routeIs('bots.brain') ? 'active' : '' }}"
           href="{{ route('bots.brain', $bot->id) }}"
           @if(request()->routeIs('bots.brain')) aria-current="page" @endif>
            <i class="bi bi-cpu"></i> Behaviour
        </a>
    </li>
</ul>
