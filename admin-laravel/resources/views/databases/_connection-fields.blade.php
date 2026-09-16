@php
    $c = $connection ?? null;

    // Only the create form repopulates from old input. An edit form falls back
    // to its stored values instead, because a failed create would otherwise
    // prefill every edit modal on the page with the wrong connection.
    $useOld = $useOld ?? false;
    $val = fn (string $field, $stored) => $useOld ? old($field, $stored) : $stored;

    $trustChecked = $useOld && old('options') !== null
        ? old('options.trust_server_certificate') === '1'
        : ($c === null || filter_var(
            $c->options['trust_server_certificate'] ?? true, FILTER_VALIDATE_BOOL));
@endphp

<div class="alert alert-warning" style="font-size: 0.8rem;">
    Use a read-only database account. A bot only ever reads, and the stored
    password is encrypted against a database dump but not against anyone
    holding this application's key.
</div>

<div class="mb-3">
    <label class="form-label">Name</label>
    <input name="name" class="form-control @error('name') is-invalid @enderror" required maxlength="255"
           value="{{ $val('name', $c->name ?? '') }}" placeholder="Orders database">
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    <div class="form-text">This is what a visitor sees when a bot cites it.</div>
</div>

<div class="mb-3">
    <label class="form-label">Driver</label>
    <select name="driver" class="form-select @error('driver') is-invalid @enderror" required>
        @foreach(['mysql' => 'MySQL or MariaDB', 'pgsql' => 'PostgreSQL',
                  'sqlsrv' => 'SQL Server', 'sqlite' => 'SQLite'] as $value => $label)
            <option value="{{ $value }}" @selected($val('driver', $c->driver ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    @error('driver')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="row g-2 mb-3">
    <div class="col-8">
        <label class="form-label">Host</label>
        <input name="host" class="form-control @error('host') is-invalid @enderror" maxlength="255"
               value="{{ $val('host', $c->host ?? '') }}" placeholder="db.internal">
        @error('host')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-4">
        <label class="form-label">Port</label>
        <input name="port" type="number" class="form-control @error('port') is-invalid @enderror"
               min="1" max="65535"
               value="{{ $val('port', $c->port ?? '') }}" placeholder="default">
        @error('port')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="mb-3">
    <label class="form-label">Database</label>
    <input name="database" class="form-control @error('database') is-invalid @enderror" required maxlength="255"
           value="{{ $val('database', $c->database ?? '') }}">
    @error('database')<div class="invalid-feedback">{{ $message }}</div>@enderror
    <div class="form-text">A database name, or a file path when the driver is SQLite.</div>
</div>

<div class="row g-2 mb-3">
    <div class="col-6">
        <label class="form-label">Username</label>
        <input name="username" class="form-control" maxlength="255"
               value="{{ $val('username', $c->username ?? '') }}" autocomplete="off">
    </div>
    <div class="col-6">
        <label class="form-label">Password</label>
        <input name="password" type="password" class="form-control" maxlength="255"
               autocomplete="new-password"
               placeholder="{{ $c ? 'leave empty to keep the current one' : '' }}">
    </div>
</div>

<details @if($errors->has('options')) open @endif>
    <summary class="text-muted" style="font-size: 0.8rem;">Advanced</summary>
    <div class="row g-2 mt-1">
        <div class="col-6">
            <label class="form-label">PostgreSQL SSL mode</label>
            <input name="options[sslmode]" class="form-control" maxlength="20"
                   value="{{ $val('options.sslmode', $c->options['sslmode'] ?? '') }}" placeholder="prefer">
        </div>
        <div class="col-6">
            <label class="form-label">PostgreSQL search path</label>
            <input name="options[search_path]" class="form-control" maxlength="128"
                   value="{{ $val('options.search_path', $c->options['search_path'] ?? '') }}" placeholder="public">
        </div>
    </div>
    <div class="form-check mt-2">
        {{-- An unticked checkbox sends nothing, and nothing means the default,
             which is on. The hidden field is what makes turning it off possible. --}}
        <input type="hidden" name="options[trust_server_certificate]" value="0">
        <input class="form-check-input" type="checkbox" name="options[trust_server_certificate]"
               value="1" id="trustCert{{ $c->id ?? 'new' }}" @checked($trustChecked)>
        <label class="form-check-label" for="trustCert{{ $c->id ?? 'new' }}" style="font-size: 0.8rem;">
            Trust the SQL Server certificate
        </label>
    </div>
</details>

<hr class="my-3">

<div class="d-flex align-items-center gap-2">
    <button type="button" class="btn btn-outline-secondary btn-sm"
            data-test-connection data-connection-id="{{ $c->id ?? '' }}">
        <i class="bi bi-plug"></i> Test connection
    </button>
    <span class="form-text m-0" data-test-result></span>
</div>
<div class="form-text mt-1">
    Tries the details above without saving them.
    @if($c) A blank password means the one already stored. @endif
</div>
