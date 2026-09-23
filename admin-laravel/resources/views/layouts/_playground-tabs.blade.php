{{-- One playground with a tab per store, mirroring _knowledge-tabs. Each tab
     is its own page (its own form and POST), so these are links. --}}
<div class="page-head mb-3" style="border-bottom: 0; padding-bottom: 0;">
    <div>
        <a href="{{ request()->routeIs('databases.*') ? route('databases.index') : route('kb.index') }}"
           class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
            <i class="bi bi-arrow-left"></i> Knowledge base
        </a>
        <h1>Playground</h1>
        <p>{{ $intro }}</p>
    </div>
</div>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link d-inline-flex align-items-center gap-1.5 {{ request()->routeIs('kb.*') ? 'active' : '' }}"
           href="{{ route('kb.playground') }}"
           @if(request()->routeIs('kb.*')) aria-current="page" @endif>
            <i class="bi bi-journal-text"></i> Collection
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link d-inline-flex align-items-center gap-1.5 {{ request()->routeIs('databases.*') ? 'active' : '' }}"
           href="{{ route('databases.playground') }}"
           @if(request()->routeIs('databases.*')) aria-current="page" @endif>
            <i class="bi bi-database"></i> Database
        </a>
    </li>
</ul>
