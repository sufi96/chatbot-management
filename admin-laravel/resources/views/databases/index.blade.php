@extends('layouts.app')

@section('page-title', 'Knowledge base')

@section('content')

@php($canEdit = auth()->user()->canManageSystem($activeSystem->id, 'editor'))

{{-- The tab strip carries the rule under the head, so the head drops its own
     and the two do not stack into a double line. --}}
<div class="page-head mb-3" style="border-bottom: 0; padding-bottom: 0;">
    <div>
        <h1>Knowledge base</h1>
        <p>Live data the bots in {{ $activeSystem->name }} can query. Connect a database, discover its tables, and explain what each one holds so a bot can write sensible queries against it.</p>
    </div>

    @if($canEdit)
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('kb.playground') }}" class="btn btn-outline-secondary">
                <i class="bi bi-search"></i> Playground
            </a>
            <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newConnectionModal">
                <i class="bi bi-plus-lg"></i> New connection
            </button>
        </div>
    @endif
</div>

@include('layouts._knowledge-tabs')

<div class="card">
    @if($connections->isEmpty())
        <div class="empty">
            <i class="bi bi-database"></i>
            <h6>No connections yet</h6>
            <p>Point one at your orders or tickets database, using a read-only account.</p>
            @if($canEdit)
                <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newConnectionModal">New connection</button>
            @endif
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 240px;">Connection</th>
                        <th style="width: 120px;">Driver</th>
                        {{-- What the last test found, as against whether a bot
                             is allowed to use it. Two different questions. --}}
                        <th style="width: 120px;">Reachable</th>
                        <th style="width: 120px;">Bot access</th>
                        <th style="width: 140px;">Readable tables</th>
                        <th class="text-end" style="width: 300px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($connections as $connection)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $connection->name }}</div>
                                <div class="text-muted" style="font-size: 0.75rem;">
                                    {{ $connection->host ? $connection->host . ' / ' : '' }}{{ $connection->database }}
                                </div>
                            </td>
                            <td><span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $connection->driver }}</span></td>
                            <td>
                                @if($connection->status === 'ok')
                                    <span class="badge bg-success-subtle text-success-emphasis">Connected</span>
                                @elseif($connection->status === 'failed')
                                    <span class="badge bg-danger-subtle text-danger-emphasis"
                                          title="{{ $connection->error_message }}">Failed</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">Untested</span>
                                @endif
                            </td>
                            <td>
                                @if($connection->is_enabled)
                                    <span class="badge bg-success-subtle text-success-emphasis">Enabled</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis"
                                          title="No bot reads this database, whatever its tables say.">Disabled</span>
                                @endif
                            </td>
                            <td class="{{ $connection->is_enabled ? '' : 'opacity-50' }}">
                                <span class="figure-mono">{{ $connection->enabled_tables_count }}</span>
                                <span class="text-muted">/ {{ $connection->tables_count }}</span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex align-items-center justify-content-end gap-1">
                                    <a href="{{ route('databases.schema', $connection->id) }}"
                                       class="btn btn-sm btn-outline-primary">Schema</a>
                                    @if($canEdit)
                                        <form action="{{ route('databases.toggle', $connection->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm {{ $connection->is_enabled ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                                {{ $connection->is_enabled ? 'Disable' : 'Enable' }}
                                            </button>
                                        </form>
                                        <form action="{{ route('databases.test', $connection->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary">Test</button>
                                        </form>
                                        <button class="btn btn-sm btn-outline-secondary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editConnection{{ $connection->id }}">Edit</button>
                                        <form action="{{ route('databases.destroy', $connection->id) }}" method="POST"
                                              data-confirm="Delete this database connection?"
                                              data-confirm-subject="{{ $connection->name }}"
                                              data-confirm-detail="{{ $connection->driver }} · {{ $connection->host ? $connection->host . ' / ' : '' }}{{ $connection->database }}"
                                              data-confirm-message="Every table description and annotation on it is deleted too, and bots stop reading it. The database itself is not touched."
                                              data-confirm-label="Delete connection"
                                              class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if($canEdit)
<div class="modal fade" id="newConnectionModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="{{ route('databases.store') }}" method="POST">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">New connection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                @include('databases._connection-fields', ['connection' => null, 'useOld' => true])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-brand">Save connection</button>
            </div>
        </form>
    </div>
</div>

@foreach($connections as $connection)
    <div class="modal fade" id="editConnection{{ $connection->id }}" tabindex="-1">
        <div class="modal-dialog">
            <form class="modal-content" action="{{ route('databases.update', $connection->id) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Edit {{ $connection->name }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @include('databases._connection-fields', ['connection' => $connection])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-brand">Save changes</button>
                </div>
            </form>
        </div>
    </div>
@endforeach
@endif

@endsection

@push('scripts')
<script>
(function () {
    // One delegated handler for every modal on the page. Each button reads the
    // form it sits in, so the create form and each edit form test themselves
    // without needing ids that would collide.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-test-connection]');
        if (!button) return;

        var form = button.closest('form');
        var output = form.querySelector('[data-test-result]');
        var field = function (name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
        };
        var trust = form.querySelector('input[type=checkbox][name="options[trust_server_certificate]"]');

        output.className = 'form-text m-0 text-muted';
        output.textContent = 'Testing...';
        button.disabled = true;

        fetch('{{ route('databases.test-draft') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                connection_id: button.dataset.connectionId || null,
                driver: field('driver'),
                host: field('host'),
                port: field('port') || null,
                database: field('database'),
                username: field('username'),
                password: field('password'),
                options: {
                    sslmode: field('options[sslmode]'),
                    search_path: field('options[search_path]'),
                    trust_server_certificate: trust && trust.checked ? '1' : '0'
                }
            })
        })
        .then(function (response) {
            return response.json().then(function (data) {
                return { status: response.status, data: data };
            });
        })
        .then(function (result) {
            button.disabled = false;

            if (result.status === 422) {
                var errors = result.data.errors || {};
                var first = Object.keys(errors)[0];
                output.className = 'form-text m-0 text-danger';
                output.textContent = first ? errors[first][0] : 'Check the fields above.';
                return;
            }

            output.className = 'form-text m-0 ' + (result.data.ok ? 'text-success' : 'text-danger');
            output.textContent = result.data.message || (result.data.ok ? 'Connected.' : 'Failed.');
        })
        .catch(function () {
            button.disabled = false;
            output.className = 'form-text m-0 text-danger';
            output.textContent = 'Could not reach the admin portal.';
        });
    });

    @if($errors->any() && $canEdit)
    // A rejected create closes the modal and drops the banner behind it. Reopen
    // it, with the fields repopulated, so the complaint and the form arrive
    // together.
    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('newConnectionModal');
        if (modal) new bootstrap.Modal(modal).show();
    });
    @endif
}());
</script>
@endpush
