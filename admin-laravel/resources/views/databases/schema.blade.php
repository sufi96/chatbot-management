@extends('layouts.app')

@section('page-title', 'Schema')

@section('content')

<div class="page-head mb-4">
    <div>
        <h1>{{ $connection->name }}</h1>
        <p>
            Tick the tables a bot may read, and say in plain language what each table
            and column holds. A bot writes its queries from these sentences, so a
            column called <code>amt_ttl</code> is only as useful as the line beside it.
        </p>
    </div>

    <div class="d-flex align-items-center gap-2">
        <a href="{{ route('databases.index') }}" class="btn btn-outline-secondary">Back</a>
        @if($canEdit)
            <form action="{{ route('databases.introspect', $connection->id) }}" method="POST">
                @csrf
                <button class="btn btn-brand"><i class="bi bi-arrow-repeat"></i> Discover schema</button>
            </form>
        @endif
    </div>
</div>

@unless($connection->is_enabled)
    <div class="alert alert-warning">
        <strong>This database is switched off.</strong>
        No bot will read it, whatever the table switches below say. Your ticks and
        descriptions are all kept. Turn it back on from the
        <a href="{{ route('databases.index') }}">Databases</a> list.
    </div>
@endunless

@if($connection->last_introspected_at)
    <p class="text-muted mb-3" style="font-size: 0.8rem;">
        Last discovered {{ $connection->last_introspected_at->diffForHumans() }}.
        Discovering again never overwrites a description you wrote.
    </p>
@endif

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    Tables
                    <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1"
                          title="Readable tables out of the total discovered.">
                        {{ $readableCount }} / {{ $tables->count() }}
                    </span>
                </span>
                @if($canEdit)
                    <button class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#addTableModal">Add by hand</button>
                @endif
            </div>

            @if($tables->isEmpty())
                <div class="empty">
                    <i class="bi bi-table"></i>
                    <h6>No tables yet</h6>
                    <p>Press discover. If the account cannot read the information schema, add them by hand.</p>
                </div>
            @else
                <div class="list-group list-group-flush">
                    {{-- Ticking two hundred tables one at a time was the slowest
                         honest way to say "read all of it". This says it once. --}}
                    @if($canEdit)
                        <form action="{{ route('databases.tables.readable', $connection->id) }}"
                              method="POST" id="allReadableForm"
                              class="list-group-item table-master d-flex align-items-center gap-2">
                            @csrf
                            @if($selected)
                                <input type="hidden" name="table" value="{{ $selected->id }}">
                            @endif

                            <div class="form-check form-switch m-0 d-flex align-items-center gap-2">
                                <input class="form-check-input mt-0" type="checkbox" id="allReadable"
                                       data-total="{{ $tables->count() }}"
                                       data-readable="{{ $readableCount }}"
                                       {{ $readableCount === $tables->count() ? 'checked' : '' }}>
                                <label class="form-check-label" for="allReadable">
                                    All tables readable
                                </label>
                            </div>

                            {{-- Without scripting the tick cannot submit itself, so
                                 the fallback says both directions out loud rather
                                 than guessing which one the tick meant. --}}
                            <noscript>
                                <span class="ms-auto d-flex gap-1">
                                    <button name="enabled" value="1"
                                            class="btn btn-sm btn-outline-secondary">All</button>
                                    <button name="enabled" value="0"
                                            class="btn btn-sm btn-outline-secondary">None</button>
                                </span>
                            </noscript>
                        </form>
                    @endif

                    @foreach($tables as $table)
                        <a href="{{ route('databases.schema', [$connection->id, 'table' => $table->id]) }}"
                           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2
                                  {{ $selected && $selected->id === $table->id ? 'active' : '' }}">
                            <span class="d-flex align-items-baseline gap-2 text-truncate">
                                <span class="figure-mono text-muted" style="font-size: 0.7rem; min-width: 2.2em;">
                                    {{ $loop->iteration }}
                                </span>
                                <span class="text-truncate {{ $table->is_present ? '' : 'text-decoration-line-through opacity-50' }}">
                                    {{ $table->qualifiedName() }}
                                </span>
                            </span>
                            {{-- Both states are labelled. An unlabelled row made
                                 "switched off" and "not looked at yet" identical. --}}
                            @if($table->is_enabled)
                                <span class="badge bg-success-subtle text-success-emphasis"
                                      title="A bot may read this table.">Readable</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary-emphasis"
                                      title="A bot is never told this table exists.">Ignored</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="col-lg-8">
        @if(!$selected)
            <div class="card"><div class="empty"><p>Pick a table.</p></div></div>
        @else
            {{-- One form over both cards. A table with sixty columns used to be
                 sixty saves, each one a fresh page that threw the reader back to
                 the top. This is one save, in one place, that keeps your place. --}}
            <form action="{{ route('databases.tables.update', $selected->id) }}" method="POST"
                  id="tablePanelForm" data-table="{{ $selected->id }}">
                @csrf
                @method('PUT')

                <div class="card mb-3">
                    <div class="card-header">{{ $selected->qualifiedName() }}</div>
                    <div class="card-body">
                        @unless($selected->is_present)
                            <div class="alert alert-warning" style="font-size: 0.8rem;">
                                The database no longer has this table. Its description is kept in
                                case it comes back, and a query may not name it while it is missing.
                            </div>
                        @endunless

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="is_enabled" value="1"
                                   id="tableEnabled" {{ $selected->is_enabled ? 'checked' : '' }}
                                   {{ $canEdit ? '' : 'disabled' }}>
                            <label class="form-check-label" for="tableEnabled">
                                A bot may read this table
                            </label>
                        </div>

                        <div>
                            <label class="form-label">What this table holds</label>
                            <textarea name="description" class="form-control" rows="2" maxlength="2000"
                                      {{ $canEdit ? '' : 'disabled' }}
                                      placeholder="Orders placed through the web shop. One row per order, not per item.">{{ $selected->description }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            Columns
                            <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">
                                {{ $selected->columns->count() }} columns
                            </span>
                        </span>
                        @if($canEdit)
                            {{-- Inside a form now, so it must say it is not the submit. --}}
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="modal" data-bs-target="#addColumnModal">Add by hand</button>
                        @endif
                    </div>

                    @if($selected->columns->isEmpty())
                        <div class="empty"><p>No columns recorded. Discover the schema, or add them by hand.</p></div>
                    @else
                        <div class="card-body pb-0">
                            <p class="text-muted mb-2" style="font-size: 0.75rem;">
                                Discovery fills in every foreign key the database declares.
                                Where it declares none, open <em>+ relationship</em> on a row
                                and write the join yourself as <code>table.column</code>. It is
                                what lets a bot follow a question from an order to the customer
                                who placed it, and re-discovering never erases it.
                            </p>
                        </div>
                        <div class="table-responsive">
                            <table class="table mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 48px;" class="text-end text-muted">#</th>
                                        <th style="width: 220px;">Column</th>
                                        <th>What it means</th>
                                        @if($canEdit)<th style="width: 120px;"></th>@endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($selected->columns as $column)
                                        <tr class="{{ $column->is_present ? '' : 'opacity-50' }}">
                                            <td class="text-end figure-mono text-muted" style="font-size: 0.75rem;">
                                                {{ $loop->iteration }}
                                            </td>
                                            <td>
                                                <div class="fw-semibold">{{ $column->column_name }}</div>
                                                <div class="text-muted" style="font-size: 0.7rem;">
                                                    {{ $column->data_type ?: 'unknown type' }}@if($column->is_primary_key), primary key @endif
                                                    @if($column->foreign_key_target)<br>points at {{ $column->foreign_key_target }}@endif
                                                    {{-- A directive glued to a letter is not a directive, so the
                                                         space before @endunless is load bearing. --}}
                                                    @unless($column->is_present)<br>no longer in the database @endunless
                                                </div>
                                            </td>
                                            <td>
                                                <textarea name="columns[{{ $column->id }}][description]"
                                                          class="form-control form-control-sm" rows="1"
                                                          maxlength="2000" {{ $canEdit ? '' : 'disabled' }}
                                                          placeholder="Gross amount in ringgit, including tax.">{{ $column->description }}</textarea>
                                                {{-- Most columns are not foreign keys, and a box on every
                                                     row turned a forty-column table into a wall of them.
                                                     Collapsed, the field still submits, so saving a
                                                     description never silently drops a relationship. --}}
                                                <details class="fk-edit mt-1">
                                                    <summary>
                                                        @if($column->foreign_key_target)
                                                            <span class="fk-linked">joins {{ $column->foreign_key_target }}</span>
                                                        @else
                                                            <span class="fk-absent">+ relationship</span>
                                                        @endif
                                                    </summary>
                                                    <input type="text" name="columns[{{ $column->id }}][foreign_key_target]"
                                                           class="form-control form-control-sm mt-1 figure-mono"
                                                           style="font-size: 0.75rem;" maxlength="255"
                                                           {{ $canEdit ? '' : 'disabled' }}
                                                           value="{{ $column->foreign_key_target }}"
                                                           placeholder="table.column, e.g. customers.id">
                                                    <div class="form-text" style="font-size: 0.7rem;">
                                                        Empty clears it.
                                                    </div>
                                                </details>
                                            </td>
                                            @if($canEdit)
                                                <td class="text-end">
                                                    {{-- A form inside a form is not a form, so the button
                                                         reaches out to the lone delete form further down. --}}
                                                    <button class="btn btn-sm btn-outline-danger"
                                                            form="removeColumnForm"
                                                            formaction="{{ route('databases.columns.destroy', $column->id) }}"
                                                            onclick="return confirm('Remove {{ $column->column_name }}? Anything unsaved on this table is lost.');">
                                                        Remove
                                                    </button>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                @if($canEdit)
                    {{-- Pinned, because the save for a sixty column table should
                         never be sixty rows away from the row being written. --}}
                    <div class="schema-savebar" id="saveBar">
                        <span class="schema-savebar-state" id="saveState">No unsaved changes</span>
                        <button class="btn btn-brand btn-sm">Save changes</button>
                    </div>
                @endif
            </form>
        @endif
    </div>
</div>

@if($canEdit)
{{-- Outside the panel form on purpose: every Remove button borrows it. --}}
<form id="removeColumnForm" method="POST" class="d-none">
    @csrf
    @method('DELETE')
</form>

<div class="modal fade" id="addTableModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="{{ route('databases.tables.store', $connection->id) }}" method="POST">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add a table by hand</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size: 0.8rem;">
                    For a database whose account cannot read the information schema.
                    Name it exactly as SQL would.
                </p>
                <div class="mb-3">
                    <label class="form-label">Schema</label>
                    <input name="schema_name" class="form-control" maxlength="128" placeholder="leave empty if none">
                </div>
                <div class="mb-3">
                    <label class="form-label">Table</label>
                    <input name="table_name" class="form-control" required maxlength="128">
                </div>
                <div>
                    <label class="form-label">What it holds</label>
                    <textarea name="description" class="form-control" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-brand">Add table</button>
            </div>
        </form>
    </div>
</div>

@if($selected)
<div class="modal fade" id="addColumnModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="{{ route('databases.columns.store', $selected->id) }}" method="POST">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add a column to {{ $selected->table_name }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size: 0.8rem;">
                    Adding a column saves it straight away. Save your other changes first.
                </p>
                <div class="mb-3">
                    <label class="form-label">Column</label>
                    <input name="column_name" class="form-control" required maxlength="128">
                </div>
                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <input name="data_type" class="form-control" maxlength="64" placeholder="varchar, integer, date">
                </div>
                <div>
                    <label class="form-label">What it means</label>
                    <textarea name="description" class="form-control" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-brand">Add column</button>
            </div>
        </form>
    </div>
</div>
@endif
@endif

@endsection

@push('scripts')
<script>
(function () {
    // The console scrolls this element, not the window.
    var wrapper = document.querySelector('.content-wrapper') || document.documentElement;

    // ---- All tables readable -------------------------------------------
    var all = document.getElementById('allReadable');
    if (all) {
        var readable = parseInt(all.dataset.readable, 10);
        var total = parseInt(all.dataset.total, 10);

        // "Some of them" is a third state, and a half-empty box says so.
        all.indeterminate = readable > 0 && readable < total;

        all.addEventListener('change', function () {
            if (!all.checked && !confirm(
                'Stop bots reading all ' + total + ' tables? Your descriptions are kept.')) {
                all.checked = true;
                all.indeterminate = readable > 0 && readable < total;
                return;
            }

            var form = document.getElementById('allReadableForm');
            var answer = document.createElement('input');
            answer.type = 'hidden';
            answer.name = 'enabled';
            answer.value = all.checked ? '1' : '0';
            form.appendChild(answer);

            all.disabled = true;
            form.submit();
        });
    }

    // ---- One save for the whole panel ----------------------------------
    var form = document.getElementById('tablePanelForm');
    if (!form) { return; }

    var bar = document.getElementById('saveBar');
    var state = document.getElementById('saveState');
    var key = 'schema-scroll:' + form.dataset.table;
    var fields = Array.prototype.slice.call(
        form.querySelectorAll('textarea[name], input[name="is_enabled"], input[name*="[foreign_key_target]"]'));

    fields.forEach(function (field) {
        field.dataset.original = field.type === 'checkbox' ? (field.checked ? '1' : '') : field.value;
    });

    function dirtyFields() {
        return fields.filter(function (field) {
            var now = field.type === 'checkbox' ? (field.checked ? '1' : '') : field.value;
            return now !== field.dataset.original;
        });
    }

    function report() {
        var n = dirtyFields().length;
        if (bar) { bar.classList.toggle('is-dirty', n > 0); }
        if (state) {
            state.textContent = n === 0
                ? 'No unsaved changes'
                : n + (n === 1 ? ' unsaved change' : ' unsaved changes');
        }
    }

    form.addEventListener('input', report);
    form.addEventListener('change', report);
    report();

    // A row with an unsaved relationship should not hide it behind a summary.
    form.querySelectorAll('input[name*="[foreign_key_target]"]').forEach(function (input) {
        input.addEventListener('input', function () {
            if (input.value !== input.dataset.original) {
                input.closest('details').open = true;
            }
        });
    });

    // Sixty descriptions are too many to lose to a stray click on the list.
    window.addEventListener('beforeunload', function (event) {
        if (dirtyFields().length > 0 && !form.dataset.submitting) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    form.addEventListener('submit', function () {
        form.dataset.submitting = '1';
        try {
            sessionStorage.setItem(key, String(wrapper.scrollTop));
        } catch (e) { /* private mode: the page just opens at the top */ }
    });

    // Saving used to hand the reader back to the top of a sixty row table.
    // The key carries the table id, so a different table never inherits it.
    try {
        var at = sessionStorage.getItem(key);
        if (at !== null) {
            sessionStorage.removeItem(key);
            // After the first paint, or the page is not yet tall enough to hold
            // the offset and the browser clamps it back to the top.
            requestAnimationFrame(function () {
                wrapper.scrollTop = parseInt(at, 10) || 0;
            });
        }
    } catch (e) { /* nothing to restore */ }
})();
</script>
@endpush
