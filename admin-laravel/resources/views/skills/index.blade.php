@extends('layouts.app')

@section('page-title', 'Skills')

@section('content')

<div class="page-head mb-3">
    <div>
        <h1>Skills</h1>
        <p>Packaged know-how for one task: when to use it, which tools, in what order, what to ask first. An agentic bot in {{ $activeSystem->name }} loads a skill only when a request matches it, and only when it has every tool the skill needs.</p>
    </div>

    @if($canManage)
        <div class="d-flex align-items-center gap-2 flex-wrap">
            @if($providers->isNotEmpty())
                <button class="btn btn-outline-secondary" id="skillGenerate" data-bs-toggle="modal" data-bs-target="#skillDraftModal">
                    <i class="bi bi-stars"></i> Generate
                </button>
            @endif
            <label class="btn btn-outline-secondary mb-0">
                <i class="bi bi-upload"></i> Upload
                <input type="file" id="skillUpload" accept=".md,.zip" hidden>
            </label>
            <button class="btn btn-brand" data-skill-edit>
                <i class="bi bi-plus-lg"></i> New skill
            </button>
        </div>
    @endif
</div>

<div id="skillStatus" class="small fw-medium mb-2"></div>

<div class="card mb-4">
    @if($skills->isEmpty())
        <div class="empty">
            <i class="bi bi-lightbulb"></i>
            <h6>No skills yet</h6>
            <p>Write one, upload a SKILL.md, or copy a built-in below.</p>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Skill</th>
                        <th>Needs</th>
                        <th>Bots</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($skills as $skill)
                        <tr>
                            <td>
                                <div class="fw-semibold figure-mono">{{ $skill->name }}
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">{{ $skill->origin }}</span>
                                </div>
                                <div class="text-muted" style="font-size: 0.75rem;">{{ $skill->description }}</div>
                            </td>
                            <td class="small figure-mono">{{ implode(', ', $skill->tools ?? []) ?: '—' }}</td>
                            <td><span class="figure-mono">{{ $skill->bots_count }}</span></td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-1">
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('skills.download', $skill->id) }}" title="Download SKILL.md">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    @if($canManage)
                                        <button class="btn btn-sm btn-outline-primary" data-skill-edit="{{ $skill->id }}"
                                                data-content="{{ $skill->toFile() }}">Edit</button>
                                        <button class="btn btn-sm btn-outline-danger" data-skill-delete="{{ $skill->id }}"
                                                data-name="{{ $skill->name }}" title="Delete skill">
                                            <i class="bi bi-trash3"></i>
                                        </button>
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

<h6 class="mb-2">Built-in skills</h6>
<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <tbody>
                @foreach($builtIns as $skill)
                    <tr>
                        <td>
                            <div class="fw-semibold figure-mono">{{ $skill->name }}</div>
                            <div class="text-muted" style="font-size: 0.75rem;">{{ $skill->description }}</div>
                        </td>
                        <td class="small figure-mono">{{ implode(', ', $skill->tools ?? []) ?: '—' }}</td>
                        <td class="text-end">
                            <div class="d-flex justify-content-end gap-1">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('skills.download', $skill->id) }}" title="Download SKILL.md">
                                    <i class="bi bi-download"></i>
                                </a>
                                @if($canManage)
                                    <button class="btn btn-sm btn-outline-primary" data-skill-copy="{{ $skill->id }}">Copy to workspace</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@if($canManage)
    <div class="modal fade" id="skillModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">Skill</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="skillForm">
                    <div class="modal-body">
                        <input type="hidden" name="sid">
                        <input type="hidden" name="origin" value="typed">
                        <textarea name="content" class="form-control figure-mono" rows="18" spellcheck="false" required></textarea>
                        <div class="form-text text-faint">
                            SKILL.md: front matter with <code>name</code> (lowercase-and-hyphens), <code>description</code> (when to use it)
                            and optional <code>tools: [a, b]</code>, then the instructions.
                        </div>
                        <div id="skillFormStatus" class="small fw-medium mt-2"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @if($providers->isNotEmpty())
        <div class="modal fade" id="skillDraftModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title mb-0">Generate a skill</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="skillDraftForm">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="draft_provider">Provider</label>
                                <select id="draft_provider" name="provider_id" class="form-select">
                                    @foreach($providers as $provider)
                                        <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="draft_model">Model</label>
                                <input id="draft_model" name="model" class="form-control" required placeholder="The model name on that provider">
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="draft_description">What should the skill do?</label>
                                <textarea id="draft_description" name="description" class="form-control" rows="4" required
                                          placeholder="Check an application's status with get_application_status, after asking for the reference number."></textarea>
                                <div class="form-text text-faint">Name the tools it should use. You edit the draft before it is saved.</div>
                            </div>
                            <div id="skillDraftStatus" class="small fw-medium mt-2"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-brand">Write a draft</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endif

@endsection

@push('scripts')
<script>
(function () {
    var systemId = @json($activeSystem->id);
    var pageStatus = document.getElementById('skillStatus');
    var TEMPLATE = "---\nname: my-skill\ndescription: Use when the visitor asks about ...\ntools: []\n---\n\n# Steps\n\n1. ";
    var routes = {
        store: @json(route('skills.store')),
        upload: @json(route('skills.upload')),
        update: @json(route('skills.update', '__ID__')),
        destroy: @json(route('skills.destroy', '__ID__')),
        copy: @json(route('skills.copy', '__ID__'))
    };
    function url(name, id) { return routes[name].replace('__ID__', encodeURIComponent(id)); }
    var token = document.querySelector('meta[name="csrf-token"]').content;

    function say(el, text, ok) {
        if (!el) return;
        el.textContent = text || '';
        el.className = 'small fw-medium ' + (el.id === 'skillStatus' ? 'mb-2 ' : 'mt-2 ') +
            (ok === true ? 'text-success' : ok === false ? 'text-danger' : 'text-muted');
    }

    function finish(response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
            if (!response.ok && data.errors) data.message = Object.values(data.errors)[0][0];
            if (response.status === 403) data.message = 'Only a system admin of this workspace can change its skills.';
            data.success = response.ok && data.success !== false;
            return data;
        });
    }

    function send(method, target, body) {
        return fetch(target, {
            method: method,
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token},
            body: body ? JSON.stringify(body) : undefined
        }).then(finish);
    }

    var modalEl = document.getElementById('skillModal');
    var form = document.getElementById('skillForm');
    var formStatus = document.getElementById('skillFormStatus');

    window.openSkillEditor = function (content, origin, id) {
        form.reset();
        form.sid.value = id || '';
        form.origin.value = origin || 'typed';
        form.content.value = content || TEMPLATE;
        say(formStatus, '');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    if (form) {
        document.querySelectorAll('[data-skill-edit]').forEach(function (button) {
            button.addEventListener('click', function () {
                window.openSkillEditor(button.dataset.content, 'typed', button.dataset.skillEdit);
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var id = form.sid.value;
            say(formStatus, 'Saving…');
            var body = {system_id: systemId, content: form.content.value, origin: form.origin.value};
            send(id ? 'PUT' : 'POST', id ? url('update', id) : routes.store, body).then(function (data) {
                say(formStatus, data.message, data.success);
                if (data.success) location.reload();
            });
        });

        document.getElementById('skillUpload').addEventListener('change', function (event) {
            var file = event.target.files[0];
            if (!file) return;
            var body = new FormData();
            body.append('system_id', systemId);
            body.append('file', file);
            say(pageStatus, 'Uploading…');
            fetch(routes.upload, {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': token}, body: body})
                .then(finish).then(function (data) {
                    say(pageStatus, data.message, data.success);
                    if (data.success) setTimeout(function () { location.reload(); }, 1500);
                });
            event.target.value = '';
        });
    }

    var draftForm = document.getElementById('skillDraftForm');
    if (draftForm) {
        draftForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var draftStatus = document.getElementById('skillDraftStatus');
            say(draftStatus, 'Writing… this can take a minute.');
            send('POST', @json(route('skills.draft')), {
                system_id: systemId, provider_id: draftForm.provider_id.value,
                model: draftForm.model.value, description: draftForm.description.value
            }).then(function (data) {
                if (!data.success) { say(draftStatus, data.message, false); return; }
                say(draftStatus, '');
                bootstrap.Modal.getOrCreateInstance(document.getElementById('skillDraftModal')).hide();
                window.openSkillEditor(data.skill, 'generated');
            });
        });
    }

    document.querySelectorAll('[data-skill-delete]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!confirm('Delete ' + button.dataset.name + '?')) return;
            send('DELETE', url('destroy', button.dataset.skillDelete)).then(function (data) {
                say(pageStatus, data.message, data.success);
                if (data.success) location.reload();
            });
        });
    });

    document.querySelectorAll('[data-skill-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            send('POST', url('copy', button.dataset.skillCopy), {system_id: systemId}).then(function (data) {
                say(pageStatus, data.message, data.success);
                if (data.success) location.reload();
            });
        });
    });
})();
</script>
@endpush
