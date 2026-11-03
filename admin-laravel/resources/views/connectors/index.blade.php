@extends('layouts.app')

@section('page-title', 'Connectors')

@section('content')

<div class="page-head mb-3">
    <div>
        <h1>Connectors</h1>
        <p>Outside APIs the bots in {{ $activeSystem->name }} can call. Import each API's operations from its OpenAPI spec, then switch on the ones bots may use. An operation that changes something always asks the visitor to confirm first.</p>
    </div>

    @if($canManage)
        <button class="btn btn-brand" data-connector-edit>
            <i class="bi bi-plus-lg"></i> New connector
        </button>
    @endif
</div>

<div id="connectorStatus" class="small fw-medium mb-2"></div>

@if($connectors->isEmpty())
    <div class="card">
        <div class="empty">
            <i class="bi bi-plug"></i>
            <h6>No connectors yet</h6>
            <p>Add an API: its address, how it signs in, then import its operations.</p>
        </div>
    </div>
@endif

@foreach($connectors as $connector)
    @php($page = $connector->forPage())
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <div>
                <div class="fw-semibold">{{ $connector->name }}</div>
                <div class="text-muted" style="font-size: 0.75rem;">
                    {{ \App\Models\Connector::TYPES[$connector->type] ?? $connector->type }} ·
                    <span class="figure-mono">{{ $connector->base_url }}</span> ·
                    sign-in: {{ $connector->auth_type }}{{ $page['hint'] ? ' ' . $page['hint'] : '' }}
                </div>
            </div>
            @if($canManage)
                <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-outline-secondary" data-connector-test="{{ $connector->id }}">Test</button>
                    <button class="btn btn-sm btn-outline-primary" data-connector-edit='@json($page)'>Edit</button>
                    <button class="btn btn-sm btn-outline-danger" title="Delete connector"
                            data-connector-delete="{{ $connector->id }}" data-name="{{ $connector->name }}">
                        <i class="bi bi-trash3"></i>
                    </button>
                </div>
            @endif
        </div>
        <div class="card-body">
            @if($canManage)
                <form class="d-flex gap-2 mb-3 flex-wrap" data-connector-import="{{ $connector->id }}">
                    <input type="url" name="spec_url" class="form-control form-control-sm" style="max-width: 360px;"
                           placeholder="OpenAPI spec URL (JSON)">
                    <span class="text-muted small align-self-center">or</span>
                    <textarea name="spec" class="form-control form-control-sm figure-mono" rows="1" style="max-width: 360px;"
                              placeholder="Paste the spec JSON"></textarea>
                    <button class="btn btn-sm btn-outline-primary">Import operations</button>
                </form>
            @endif

            @if($connector->tools->isEmpty())
                <p class="text-muted small mb-0">No operations imported yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Operation</th>
                                <th>Route</th>
                                <th>Description</th>
                                <th class="text-end">Bots may use it</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($connector->tools as $tool)
                                <tr>
                                    <td class="figure-mono">
                                        {{ $tool->name }}
                                        @if($tool->writes)
                                            <span class="badge text-bg-warning ms-1" title="Changes something; the visitor confirms first">Writes</span>
                                        @endif
                                    </td>
                                    <td class="figure-mono small">{{ strtoupper($tool->method) }} {{ $tool->path }}</td>
                                    <td class="small text-muted">{{ \Illuminate\Support\Str::limit($tool->description, 120) }}</td>
                                    <td class="text-end">
                                        <input type="checkbox" class="form-check-input" data-tool-toggle="{{ $tool->id }}"
                                               @checked($tool->enabled) @disabled(!$canManage)>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endforeach

@if($canManage)
    <div class="modal fade" id="connectorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">Connector</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="connectorForm">
                    <div class="modal-body">
                        <input type="hidden" name="cid">
                        <div class="mb-3">
                            <label class="form-label" for="con_name">Name <span style="color: var(--danger);">*</span></label>
                            <input id="con_name" name="name" class="form-control" required maxlength="120" placeholder="Applications API">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="con_type">Type</label>
                            <select id="con_type" name="type" class="form-select">
                                @foreach(\App\Models\Connector::TYPES as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="con_url">Base URL <span style="color: var(--danger);">*</span></label>
                            <input id="con_url" name="base_url" type="url" class="form-control" required placeholder="https://api.example.gov.my/v1">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="con_auth">Sign-in</label>
                            <select id="con_auth" name="auth_type" class="form-select">
                                <option value="none">None</option>
                                <option value="bearer">Bearer token</option>
                                <option value="header">Key in a header</option>
                                <option value="basic">User and password</option>
                            </select>
                        </div>
                        <div class="mb-3" data-auth-for="header">
                            <label class="form-label" for="con_header">Header name</label>
                            <input id="con_header" name="auth_header" class="form-control" placeholder="X-Api-Key">
                        </div>
                        <div class="mb-0" data-auth-for="bearer header basic">
                            <label class="form-label" for="con_secret">Secret</label>
                            <input id="con_secret" name="auth_secret" type="password" class="form-control" autocomplete="new-password">
                            <div class="form-text text-faint">Token, key, or user:password. Never shown again once saved; leave blank to keep the saved one.</div>
                        </div>
                        <div id="connectorFormStatus" class="small fw-medium mt-2"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" id="connectorFormTest">Test</button>
                        <button type="submit" class="btn btn-brand">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

@endsection

@push('scripts')
<script>
(function () {
    var systemId = @json($activeSystem->id);
    var pageStatus = document.getElementById('connectorStatus');

    function say(el, text, ok) {
        if (!el) return;
        el.textContent = text || '';
        el.className = el.className.replace(/\btext-(success|danger|muted)\b/g, '') + ' ' +
            (ok === true ? 'text-success' : ok === false ? 'text-danger' : 'text-muted');
    }

    function send(method, url, body) {
        return fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok && data.errors) {
                    data.message = Object.values(data.errors)[0][0];
                }
                if (response.status === 403) {
                    data.message = 'Only a system admin of this workspace can change its connectors.';
                }
                data.success = response.ok && data.success !== false;
                return data;
            });
        });
    }

    var routes = {
        store: @json(route('connectors.store')),
        test: @json(route('connectors.test')),
        update: @json(route('connectors.update', '__ID__')),
        destroy: @json(route('connectors.destroy', '__ID__')),
        import: @json(route('connectors.import', '__ID__')),
        tool: @json(route('connector-tools.update', '__ID__'))
    };
    function url(name, id) { return routes[name].replace('__ID__', encodeURIComponent(id)); }

    var modalEl = document.getElementById('connectorModal');
    var form = document.getElementById('connectorForm');
    var formStatus = document.getElementById('connectorFormStatus');

    function showAuthFields() {
        var auth = form.auth_type.value;
        form.querySelectorAll('[data-auth-for]').forEach(function (el) {
            el.hidden = el.dataset.authFor.split(' ').indexOf(auth) === -1;
        });
    }

    function fields() {
        var data = {system_id: systemId};
        ['name', 'type', 'base_url', 'auth_type', 'auth_header', 'auth_secret'].forEach(function (k) {
            data[k] = form[k].value;
        });
        return data;
    }

    if (form) {
        form.auth_type.addEventListener('change', showAuthFields);

        document.querySelectorAll('[data-connector-edit]').forEach(function (button) {
            button.addEventListener('click', function () {
                var saved = button.dataset.connectorEdit ? JSON.parse(button.dataset.connectorEdit) : {};
                form.reset();
                form.cid.value = saved.id || '';
                ['name', 'type', 'base_url', 'auth_type', 'auth_header'].forEach(function (k) {
                    if (saved[k]) form[k].value = saved[k];
                });
                say(formStatus, '');
                showAuthFields();
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var id = form.cid.value;
            say(formStatus, 'Saving…');
            send(id ? 'PUT' : 'POST', id ? url('update', id) : routes.store, fields()).then(function (data) {
                say(formStatus, data.message, data.success);
                if (data.success) location.reload();
            });
        });

        document.getElementById('connectorFormTest').addEventListener('click', function () {
            var body = fields();
            if (form.cid.value) body.connector_id = form.cid.value;
            say(formStatus, 'Testing…');
            send('POST', routes.test, body).then(function (data) { say(formStatus, data.message, data.success); });
        });
    }

    document.querySelectorAll('[data-connector-test]').forEach(function (button) {
        button.addEventListener('click', function () {
            say(pageStatus, 'Testing…');
            send('POST', routes.test, {connector_id: button.dataset.connectorTest}).then(function (data) {
                say(pageStatus, data.message, data.success);
            });
        });
    });

    document.querySelectorAll('[data-connector-delete]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!confirm('Delete ' + button.dataset.name + '? Its operations go with it.')) return;
            send('DELETE', url('destroy', button.dataset.connectorDelete)).then(function (data) {
                say(pageStatus, data.message, data.success);
                if (data.success) location.reload();
            });
        });
    });

    document.querySelectorAll('[data-connector-import]').forEach(function (importForm) {
        importForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var body = {};
            if (importForm.spec.value.trim()) body.spec = importForm.spec.value;
            else body.spec_url = importForm.spec_url.value;
            say(pageStatus, 'Importing…');
            send('POST', url('import', importForm.dataset.connectorImport), body).then(function (data) {
                say(pageStatus, data.message, data.success);
                if (data.success) location.reload();
            });
        });
    });

    document.querySelectorAll('[data-tool-toggle]').forEach(function (box) {
        box.addEventListener('change', function () {
            send('PUT', url('tool', box.dataset.toolToggle), {enabled: box.checked}).then(function (data) {
                say(pageStatus, data.message, data.success);
                if (!data.success) box.checked = !box.checked;
            });
        });
    });
})();
</script>
@endpush
