{{-- Collections and connections are two stores of the same thing: material a
     bot can answer from. One section with a tab each, rather than two sidebar
     entries that are only ever used together. Each tab is its own page, so
     these are links and not Bootstrap tab triggers. --}}
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link d-inline-flex align-items-center gap-1.5 {{ request()->routeIs('kb.*') ? 'active' : '' }}"
           href="{{ route('kb.index') }}"
           @if(request()->routeIs('kb.*')) aria-current="page" @endif>
            <i class="bi bi-journal-text"></i> Collections
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link d-inline-flex align-items-center gap-1.5 {{ request()->routeIs('databases.*') ? 'active' : '' }}"
           href="{{ route('databases.index') }}"
           @if(request()->routeIs('databases.*')) aria-current="page" @endif>
            <i class="bi bi-database"></i> Databases
        </a>
    </li>
</ul>
