{{-- Which endpoint and model a bot talks to: the card, the provider editor
     and their scripts. On the new-bot form, and on Behaviour once the bot
     exists. Expects $bot, $providers and $providerSystemId. --}}
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <span>Model and endpoint</span>
        <button type="button" onclick="testConnection()" id="btnTestConn" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-plug"></i> Test inference
        </button>
    </div>
    <div class="p-3">
        {{-- The test's outcome. The button lives in the header, and
             this line only takes space once there is something to say. --}}
        <div id="testConnResult" class="test-conn-result"></div>

        <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
            <label for="providerTrigger" class="form-label mb-0">
                Provider <span style="color: var(--danger);">*</span>
            </label>
            <button type="button" class="btn btn-sm provider-new" onclick="openProviderModal('new')">
                <i class="bi bi-plus-lg"></i> New provider
            </button>
        </div>
        {{-- The select is what the form submits and what the script reads;
             the picker is how it is shown: the name on one line, then whose
             it is, the URL, the key and the bots on it, wrapping rather than
             cutting anything off. --}}
        <div class="provider-field">
            <div class="dropdown provider-picker">
                <button type="button" class="provider-trigger" id="providerTrigger"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="listbox"
                        @if($providers->isEmpty()) disabled @endif>
                    <span class="provider-trigger-body" id="providerTriggerBody"></span>
                    <i class="bi bi-chevron-expand provider-trigger-caret"></i>
                </button>
                <div class="dropdown-menu provider-menu">
                    <div class="model-menu-filter" id="providerFilterWrap" hidden>
                        <input type="search" class="form-control form-control-sm" id="providerFilter"
                               placeholder="Filter by name, URL or workspace" aria-label="Filter providers"
                               autocomplete="off">
                    </div>
                    <div class="provider-menu-list" id="providerMenuList" role="listbox"></div>
                </div>
            </div>
            <select name="provider_id" id="provider_id" class="visually-hidden" tabindex="-1" aria-hidden="true"
                    onchange="onProviderChange()"
                    @if($providers->isEmpty()) disabled @endif>
                @if($providers->isEmpty())
                    <option value="">No providers yet — add one</option>
                @endif
                {{-- Grouped by owner, and the owner repeated in each label so a
                     closed select still tells two same-named endpoints apart.
                     A locked entry is one a super admin set that this user
                     cannot pick, so it carries no URL. No entry carries its key:
                     only whether it has one. --}}
                @foreach($providers->groupBy(fn ($p) => $p->system_id ?? '') as $ownerId => $group)
                    <optgroup label="{{ $group->first()->ownerName() }}" data-system-id="{{ $ownerId }}">
                        @foreach($group as $provider)
                            @php
                                $locked = (bool) $provider->getAttribute('locked');
                                $editable = !$locked && $provider->system_id !== null;
                            @endphp
                            <option value="{{ $provider->id }}"
                                    data-name="{{ $provider->name }}"
                                    data-owner="{{ $provider->ownerName() }}"
                                    data-editable="{{ $editable ? '1' : '0' }}"
                                    data-own="{{ $provider->system_id === $providerSystemId ? '1' : '0' }}"
                                    data-scope="{{ $provider->system_id === null ? 'platform' : ($provider->system_id === $providerSystemId ? 'own' : 'other') }}"
                                    data-bots="{{ $provider->bots_count ?? 0 }}"
                                    @if($locked)
                                        data-locked="1"
                                    @else
                                        data-base-url="{{ $provider->base_url }}"
                                        data-merge-system="{{ $provider->merge_system_prompt ? '1' : '0' }}"
                                        data-has-key="{{ $provider->api_key ? '1' : '0' }}"
                                    @endif
                                    @selected(old('provider_id', $bot->provider_id) === $provider->id)>
                                {{ $locked ? $provider->name : $provider->label() }} · {{ $provider->ownerName() }}
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            {{-- Acts on the selected provider. One this user cannot change
                 shows why instead of two dead buttons. --}}
            <div class="provider-toolbar">
                <div class="provider-toolbar-actions" id="providerActions">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnEditProvider"
                            onclick="openProviderModal('edit')">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeleteProvider"
                            onclick="deleteProvider()">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                </div>
                <span class="provider-toolbar-note" id="providerLockedNote" hidden>
                    <i class="bi bi-lock"></i> Managed in Admin Settings
                </span>
            </div>
        </div>
        <div class="form-text mb-3" id="providerHint">
            One saved endpoint, shared by every bot pointing at it. Edit it once when the
            machine or the key changes.
        </div>

        <div class="mb-3">
            <div class="d-flex align-items-center justify-content-between mb-1">
                <label for="model_name" class="form-label mb-0">
                    Model <span style="color: var(--danger);">*</span>
                </label>
                <span id="fetchModelsBadge" style="font-size: 0.6875rem;"></span>
            </div>
            <div class="input-group">
                <input type="text" name="model_name" id="model_name" class="form-control font-monospace"
                       value="{{ old('model_name', $bot->model_name) }}"
                       placeholder="llama3.2" required>
                <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split"
                        data-bs-toggle="dropdown" aria-expanded="false" id="btnModelDropdownToggle"
                        title="Pick a discovered model">
                    <span class="visually-hidden">Show discovered models</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" id="modelsDropdownList"
                    style="max-height: 260px; overflow-y: auto; min-width: 250px;">
                    <li><span class="dropdown-item-text text-muted" style="font-size: 0.78125rem;">Fetch models to load the list</span></li>
                </ul>
                <button type="button" class="btn btn-brand" id="btnFetchModels"
                        onclick="fetchModelsFromBaseUrl()" title="Query the endpoint for available models">
                    <i class="bi bi-arrow-repeat" id="iconFetch"></i>
                    <span id="textFetch">Fetch models</span>
                </button>
            </div>
            <div class="form-text">Queries the base URL and confirms the endpoint answers.</div>
        </div>

    </div>
</div>

{{-- The provider editor. A modal rather than a page of its own, so the half-filled
     bot form behind it survives adding an endpoint. --}}
<div class="modal fade" id="providerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="providerModalTitle">New provider</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="providerEditId">

                <div class="mb-3">
                    <label for="providerName" class="form-label">
                        Name <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="text" id="providerName" class="form-control"
                           placeholder="Office PC" maxlength="255">
                    <div class="form-text">What you will recognise it by in the list.</div>
                </div>

                <div class="mb-3">
                    <label for="providerBaseUrl" class="form-label">
                        Base URL <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="text" id="providerBaseUrl" class="form-control font-monospace"
                           placeholder="http://localhost:11434/v1" maxlength="500">
                </div>

                {{-- The saved key is never sent to the page. On an edit the box starts
                     empty: blank keeps the saved key, a new one replaces it, and
                     removing it is a separate tick. --}}
                <div class="mb-3">
                    <label for="providerApiKey" class="form-label">API key</label>
                    <input type="password" id="providerApiKey" class="form-control font-monospace"
                           placeholder="Not needed for local Ollama" maxlength="500" autocomplete="new-password">
                    <div class="form-text" id="providerApiKeyHelp">Leave blank for a local endpoint.</div>
                    <div class="form-check mt-2" id="providerClearKeyWrap" hidden>
                        <input class="form-check-input" type="checkbox" id="providerClearKey">
                        <label class="form-check-label" for="providerClearKey">Remove the saved key</label>
                    </div>
                </div>

                {{-- For a gateway that silently drops system messages: the bot then
                     answers without its prompt, its knowledge or its database. --}}
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="providerMergeSystem">
                    <label class="form-check-label" for="providerMergeSystem">Send instructions inside the message</label>
                    <div class="form-text">Tick only if bots on this provider ignore their prompt, knowledge base or database. Some gateways drop system messages.</div>
                </div>

                <div id="providerModalResult" class="small fw-medium"></div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary" id="btnTestProvider"
                        onclick="testProviderDraft()">
                    <i class="bi bi-plug"></i> Test
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-brand" id="btnSaveProvider" onclick="saveProvider()">
                        Save provider
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function selectDiscoveredModel(modelName) {
        var input = document.getElementById('model_name');
        input.value = modelName;
        
        // Highlight active item in dropdown
        var items = document.querySelectorAll('#modelsDropdownList .dropdown-item');
        items.forEach(function(el) {
            var isCurrent = el.getAttribute('data-model') === modelName;
            el.classList.toggle('active', isCurrent);
            var check = el.querySelector('.model-check');
            if (check) check.classList.toggle('d-none', !isCurrent);
        });

        var badge = document.getElementById('fetchModelsBadge');
        if (badge) {
            badge.className = 'text-success';
            badge.innerHTML = '<i class="bi bi-check2-circle"></i> Selected: ' + modelName;
        }
    }

    function fetchModelsFromBaseUrl() {
        var provider = selectedProviderOption();
        var currentModel = document.getElementById('model_name').value.trim();
        var btn = document.getElementById('btnFetchModels');
        var icon = document.getElementById('iconFetch');
        var text = document.getElementById('textFetch');
        var badge = document.getElementById('fetchModelsBadge');
        var dropdownList = document.getElementById('modelsDropdownList');
        var testConnResult = document.getElementById('testConnResult');

        if (!provider || !provider.value) {
            badge.className = 'text-danger';
            badge.innerHTML = '<i class="bi bi-exclamation-circle"></i> Choose a provider first';
            return;
        }

        btn.disabled = true;
        icon.className = 'spinner-border spinner-border-sm';
        text.textContent = 'Fetching...';
        badge.className = 'text-muted';
        badge.innerHTML = '<i class="bi bi-hourglass-split"></i> Querying endpoint...';

        fetch(providerRoutes.models, {
            method: 'POST',
            headers: providerHeaders(),
            body: JSON.stringify({ provider_id: provider.value, bot_id: providerRoutes.botId })
        })
        .then(providerJson)
        .then(function(data) {
            btn.disabled = false;
            icon.className = 'bi bi-arrow-repeat';
            text.textContent = 'Fetch models';

            if (data.success && data.models && data.models.length > 0) {
                dropdownList.innerHTML = '';

                var header = document.createElement('li');
                header.innerHTML = '<h6 class="dropdown-header small text-uppercase fw-bold text-muted py-1" style="font-size: 0.68rem;"><i class="bi bi-hdd-network me-1"></i> Available Models (' + data.count + ')</h6>';
                dropdownList.appendChild(header);

                data.models.forEach(function(model) {
                    var isSelected = (model === currentModel) || (!currentModel && data.models.indexOf(model) === 0);
                    var li = document.createElement('li');
                    li.innerHTML = '<a class="dropdown-item font-monospace small d-flex align-items-center justify-content-between py-1.5 ' + (isSelected ? 'active' : '') + '" href="javascript:void(0)" data-model="' + model + '" onclick="selectDiscoveredModel(\'' + model + '\')">' +
                        '<span>' + model + '</span>' +
                        '<i class="bi bi-check2 model-check ' + (isSelected ? '' : 'd-none') + '"></i>' +
                    '</a>';
                    dropdownList.appendChild(li);
                });

                if (!currentModel || currentModel === 'llama3.2') {
                    selectDiscoveredModel(data.models[0]);
                } else if (data.models.includes(currentModel)) {
                    selectDiscoveredModel(currentModel);
                }

                badge.className = 'text-success';
                badge.innerHTML = '<i class="bi bi-check-circle-fill"></i> ' + data.count + ' model(s) found';

                if (testConnResult) {
                    testConnResult.className = 'test-conn-result text-success';
                    testConnResult.innerHTML = '<i class="bi bi-check2-circle"></i> Endpoint reachable, ' + data.count + ' model(s) available';
                }

                var toggleBtn = document.getElementById('btnModelDropdownToggle');
                var bsDropdown = bootstrap.Dropdown.getOrCreateInstance(toggleBtn);
                bsDropdown.show();
            } else {
                badge.className = 'text-danger';
                badge.innerHTML = '<i class="bi bi-exclamation-circle"></i> ' + (data.message || 'No models returned');

                dropdownList.innerHTML = '<li><span class="dropdown-item-text text-danger small"><i class="bi bi-exclamation-triangle me-1"></i> ' + (data.message || 'No models found') + '</span></li>';

                if (testConnResult) {
                    testConnResult.className = 'test-conn-result text-danger';
                    testConnResult.innerHTML = '<i class="bi bi-exclamation-circle"></i> ' + (data.message || 'Connection failed');
                }
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            icon.className = 'bi bi-arrow-repeat';
            text.textContent = 'Fetch models';

            badge.className = 'text-danger';
            badge.innerHTML = '<i class="bi bi-exclamation-circle"></i> Could not list models';

            dropdownList.innerHTML = '<li><span class="dropdown-item-text text-danger small"><i class="bi bi-x-circle me-1"></i> Could not list models</span></li>';

            if (testConnResult) {
                testConnResult.className = 'test-conn-result text-danger';
                testConnResult.textContent = 'Could not list models: ' + err.message;
            }
        });
    }

    // ---- Providers -------------------------------------------------------
    //
    // The select is the source of truth for which endpoint this bot talks to.
    // No key ever reaches this page: Fetch models and Test inference name the
    // selected provider, and the portal looks its key up and calls the engine.

    var providerRoutes = {
        store: '{{ route('providers.store') }}',
        base: '{{ url('/providers') }}',
        models: '{{ route('providers.models') }}',
        test: '{{ route('providers.test') }}',
        systemId: @json($providerSystemId),
        // Lets a bot's editor test the provider it already uses, even one a
        // super admin set from outside their reach.
        botId: @json($bot->exists ? $bot->id : null),
    };

    // Reads a JSON answer, turning a refusal into an error with its message.
    function providerJson(res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
            if (!res.ok && data && data.success === undefined) {
                throw new Error(firstProviderError(data));
            }
            return data;
        });
    }

    function selectedProviderOption() {
        var select = document.getElementById('provider_id');
        return select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
    }

    function onProviderChange() {
        var option = selectedProviderOption();
        var hasProvider = !!(option && option.value);

        // Platform providers are edited in Admin Settings, and one set from
        // above is not this user's to change.
        var editable = hasProvider && option.dataset.editable === '1';
        document.getElementById('providerActions').hidden = !editable;
        document.getElementById('providerLockedNote').hidden = !hasProvider || editable;

        renderProviderPicker();
    }

    // ---- Provider picker ---------------------------------------------------
    //
    // Drawn from the select every time it changes, so adding, editing and
    // deleting only ever touch the select.

    function providerMeta(option) {
        if (option.dataset.locked === '1') {
            return ['Set by a super admin'];
        }
        var bots = parseInt(option.dataset.bots || '0', 10);
        return [
            option.dataset.hasKey === '1' ? 'Key saved' : 'No key',
            bots === 0 ? 'No bots yet' : bots + (bots === 1 ? ' bot' : ' bots'),
        ];
    }

    function providerOwnerBadge(label, scope) {
        var badge = document.createElement('span');
        badge.className = 'provider-owner-badge is-' + (scope || 'other');
        badge.innerHTML = scope === 'platform'
            ? '<i class="bi bi-globe2"></i> '
            : '<i class="bi bi-diagram-3"></i> ';
        badge.appendChild(document.createTextNode(label));
        return badge;
    }

    function providerEntry(option, withOwner) {
        var wrap = document.createElement('span');
        wrap.className = 'provider-entry';

        var name = document.createElement('span');
        name.className = 'provider-entry-name';
        name.textContent = option.dataset.name;
        wrap.appendChild(name);

        // Whose it is leads the second line as a badge coloured by scope: this
        // workspace, another workspace or the platform. A long name wraps
        // inside the badge rather than being cut off.
        var sub = document.createElement('span');
        sub.className = 'provider-entry-sub';
        if (withOwner) {
            sub.appendChild(providerOwnerBadge(option.dataset.owner, option.dataset.scope));
        }
        if (option.dataset.baseUrl) {
            var url = document.createElement('span');
            url.className = 'provider-entry-url';
            url.textContent = option.dataset.baseUrl;
            sub.appendChild(url);
        }
        providerMeta(option).forEach(function (text) {
            var item = document.createElement('span');
            item.className = 'provider-entry-meta';
            item.textContent = text;
            sub.appendChild(item);
        });
        wrap.appendChild(sub);

        return wrap;
    }

    function renderProviderPicker() {
        var select = document.getElementById('provider_id');
        var trigger = document.getElementById('providerTrigger');
        var body = document.getElementById('providerTriggerBody');
        var list = document.getElementById('providerMenuList');
        var filterWrap = document.getElementById('providerFilterWrap');
        var filter = document.getElementById('providerFilter');
        if (!select || !trigger) return;

        var selected = selectedProviderOption();
        body.innerHTML = '';
        if (selected && selected.value) {
            body.appendChild(providerEntry(selected, true));
        } else {
            var empty = document.createElement('span');
            empty.className = 'provider-entry-empty';
            empty.textContent = 'No providers yet. Add one with New.';
            body.appendChild(empty);
        }
        trigger.disabled = select.disabled;

        var query = filter.value.trim().toLowerCase();
        var total = select.querySelectorAll('option[value]:not([value=""])').length;
        filterWrap.hidden = total < 6;

        list.innerHTML = '';
        Array.prototype.forEach.call(select.querySelectorAll('optgroup'), function (group) {
            var matches = Array.prototype.filter.call(group.querySelectorAll('option'), function (option) {
                var haystack = [option.dataset.name, option.dataset.owner, option.dataset.baseUrl || ''].join(' ').toLowerCase();
                return !query || haystack.indexOf(query) !== -1;
            });
            if (!matches.length) return;

            var header = document.createElement('div');
            header.className = 'dropdown-header provider-menu-header';
            header.appendChild(providerOwnerBadge(group.label, matches[0].dataset.scope));
            list.appendChild(header);

            matches.forEach(function (option) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'dropdown-item provider-item' + (option.selected ? ' active' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
                item.appendChild(providerEntry(option, false));
                item.addEventListener('click', function () {
                    select.value = option.value;
                    onProviderChange();
                });
                list.appendChild(item);
            });
        });

        if (!list.children.length) {
            var none = document.createElement('div');
            none.className = 'provider-menu-none';
            none.textContent = query ? 'Nothing matches that filter.' : 'No providers yet.';
            list.appendChild(none);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var filter = document.getElementById('providerFilter');
        var trigger = document.getElementById('providerTrigger');
        if (!filter || !trigger) return;

        filter.addEventListener('input', renderProviderPicker);
        filter.addEventListener('click', function (event) { event.stopPropagation(); });
        trigger.addEventListener('shown.bs.dropdown', function () {
            if (!document.getElementById('providerFilterWrap').hidden) filter.focus();
        });
        trigger.addEventListener('hidden.bs.dropdown', function () {
            if (filter.value) { filter.value = ''; renderProviderPicker(); }
        });
    });

    function openProviderModal(mode) {
        var result = document.getElementById('providerModalResult');
        result.className = 'small fw-medium';
        result.textContent = '';

        if (mode === 'edit') {
            var option = selectedProviderOption();
            if (!option || !option.value) { return; }

            document.getElementById('providerModalTitle').textContent = 'Edit provider';
            document.getElementById('providerEditId').value = option.value;
            document.getElementById('providerName').value = option.dataset.name || '';
            document.getElementById('providerBaseUrl').value = option.dataset.baseUrl || '';
            setProviderKeyField(option.dataset.hasKey === '1');
            document.getElementById('providerMergeSystem').checked = option.dataset.mergeSystem === '1';
        } else {
            document.getElementById('providerModalTitle').textContent = 'New provider';
            document.getElementById('providerEditId').value = '';
            document.getElementById('providerName').value = '';
            document.getElementById('providerBaseUrl').value = 'http://localhost:11434/v1';
            setProviderKeyField(false);
            document.getElementById('providerMergeSystem').checked = false;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('providerModal')).show();
    }

    // The key box always starts empty. With a key already saved it says so,
    // and offers to remove it.
    function setProviderKeyField(hasSavedKey) {
        var input = document.getElementById('providerApiKey');
        input.value = '';
        input.placeholder = hasSavedKey ? '•••••••• saved, type to replace' : 'Not needed for local Ollama';
        document.getElementById('providerApiKeyHelp').textContent = hasSavedKey
            ? 'The saved key is never shown. Leave blank to keep it.'
            : 'Leave blank for a local endpoint.';
        document.getElementById('providerClearKey').checked = false;
        document.getElementById('providerClearKeyWrap').hidden = !hasSavedKey;
    }

    function providerHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        };
    }

    function saveProvider() {
        var id = document.getElementById('providerEditId').value;
        var name = document.getElementById('providerName').value.trim();
        var baseUrl = document.getElementById('providerBaseUrl').value.trim();
        var apiKey = document.getElementById('providerApiKey').value.trim();
        var result = document.getElementById('providerModalResult');
        var btn = document.getElementById('btnSaveProvider');

        if (!name || !baseUrl) {
            result.className = 'small fw-medium text-danger';
            result.textContent = 'A name and a base URL are both needed.';
            return;
        }

        btn.disabled = true;
        result.className = 'small fw-medium text-secondary';
        result.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

        fetch(id ? providerRoutes.base + '/' + id : providerRoutes.store, {
            method: id ? 'PUT' : 'POST',
            headers: providerHeaders(),
            body: JSON.stringify({
                system_id: providerRoutes.systemId, name: name, base_url: baseUrl, api_key: apiKey,
                clear_api_key: !!id && document.getElementById('providerClearKey').checked,
                merge_system_prompt: document.getElementById('providerMergeSystem').checked
            })
        })
        .then(function(res) {
            return res.json().then(function(data) { return { ok: res.ok, data: data }; });
        })
        .then(function(payload) {
            btn.disabled = false;

            if (!payload.ok) {
                result.className = 'small fw-medium text-danger';
                result.textContent = firstProviderError(payload.data);
                return;
            }

            applySavedProvider(payload.data.provider);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('providerModal')).hide();

            var hint = document.getElementById('providerHint');
            hint.className = 'form-text mb-3 text-success';
            hint.textContent = payload.data.message;
        })
        .catch(function(error) {
            btn.disabled = false;
            result.className = 'small fw-medium text-danger';
            result.textContent = 'Could not save: ' + error.message;
        });
    }

    // Puts the saved provider into the select and selects it, so a new endpoint
    // is in use the moment the modal closes.
    function applySavedProvider(provider) {
        var select = document.getElementById('provider_id');
        var option = select.querySelector('option[value="' + provider.id + '"]');

        if (!option) {
            var placeholder = select.querySelector('option[value=""]');
            if (placeholder) { placeholder.remove(); }

            var group = select.querySelector('optgroup[data-system-id="' + provider.system_id + '"]');
            if (!group) {
                group = document.createElement('optgroup');
                group.label = provider.owner;
                group.dataset.systemId = provider.system_id;
                select.insertBefore(group, select.firstChild);
            }

            option = document.createElement('option');
            option.value = provider.id;
            group.appendChild(option);
        }

        option.textContent = provider.label + ' · ' + provider.owner;
        option.dataset.name = provider.name;
        option.dataset.owner = provider.owner;
        option.dataset.editable = '1';
        option.dataset.own = provider.system_id === providerRoutes.systemId ? '1' : '0';
        option.dataset.scope = provider.system_id === null ? 'platform'
            : (option.dataset.own === '1' ? 'own' : 'other');
        option.dataset.bots = option.dataset.bots || '0';
        option.dataset.baseUrl = provider.base_url;
        option.dataset.hasKey = provider.has_key ? '1' : '0';
        option.dataset.mergeSystem = provider.merge_system_prompt ? '1' : '0';

        select.disabled = false;
        select.value = provider.id;
        onProviderChange();
    }

    function deleteProvider() {
        var option = selectedProviderOption();
        if (!option || !option.value) { return; }

        var bots = parseInt(option.dataset.bots || '0', 10);
        confirmDialog({
            title: 'Delete this provider?',
            subject: option.dataset.name,
            detail: [option.dataset.baseUrl, option.dataset.owner].filter(Boolean).join(' · '),
            message: bots > 0
                ? 'It is still used by ' + bots + (bots === 1 ? ' bot' : ' bots') + ', so the delete will be refused until they point elsewhere.'
                : 'No bot uses it. This cannot be undone.',
            confirmLabel: 'Delete provider',
        }).then(function (confirmed) {
            if (confirmed) removeProvider(option);
        });
    }

    function removeProvider(option) {
        var hint = document.getElementById('providerHint');

        fetch(providerRoutes.base + '/' + option.value, {
            method: 'DELETE',
            headers: providerHeaders()
        })
        .then(function(res) {
            return res.json().then(function(data) { return { ok: res.ok, data: data }; });
        })
        .then(function(payload) {
            if (!payload.ok) {
                hint.className = 'form-text mb-3 text-danger';
                hint.textContent = payload.data.message || 'Could not delete that provider.';
                return;
            }

            var group = option.parentNode;
            option.remove();
            if (group.tagName === 'OPTGROUP' && !group.children.length) { group.remove(); }
            hint.className = 'form-text mb-3 text-success';
            hint.textContent = payload.data.message;

            var select = document.getElementById('provider_id');
            if (select.options.length === 0) {
                var placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'No providers yet, add one';
                select.appendChild(placeholder);
                select.disabled = true;
            }
            onProviderChange();
        })
        .catch(function(error) {
            hint.className = 'form-text mb-3 text-danger';
            hint.textContent = 'Could not delete: ' + error.message;
        });
    }

    // Checks what is typed in the modal, before any of it is saved.
    function testProviderDraft() {
        var baseUrl = document.getElementById('providerBaseUrl').value.trim();
        var apiKey = document.getElementById('providerApiKey').value.trim();
        var editId = document.getElementById('providerEditId').value;
        var clearKey = !!editId && document.getElementById('providerClearKey').checked;
        var result = document.getElementById('providerModalResult');
        var btn = document.getElementById('btnTestProvider');

        if (!baseUrl) {
            result.className = 'small fw-medium text-danger';
            result.textContent = 'Enter a base URL to test.';
            return;
        }

        btn.disabled = true;
        result.className = 'small fw-medium text-secondary';
        result.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Querying endpoint...';

        // An edit with the key box blank is tested with the saved key, as it
        // would be saved; ticking Remove tests it with none.
        fetch(providerRoutes.models, {
            method: 'POST',
            headers: providerHeaders(),
            body: JSON.stringify({
                system_id: providerRoutes.systemId, base_url: baseUrl, api_key: apiKey,
                provider_id: clearKey ? null : (editId || null)
            })
        })
        .then(providerJson)
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                result.className = 'small fw-medium text-success';
                result.textContent = 'Reachable. ' + data.count + ' model(s) available.';
            } else {
                result.className = 'small fw-medium text-danger';
                result.textContent = data.message || 'The endpoint did not answer.';
            }
        })
        .catch(function(error) {
            btn.disabled = false;
            result.className = 'small fw-medium text-danger';
            result.textContent = 'Could not reach it: ' + error.message;
        });
    }

    function firstProviderError(data) {
        if (data && data.errors) {
            for (var field in data.errors) {
                return data.errors[field][0];
            }
        }
        return (data && data.message) || 'Could not save that provider.';
    }

    document.addEventListener('DOMContentLoaded', onProviderChange);

    function testConnection() {
        var provider = selectedProviderOption();
        var modelName = document.getElementById('model_name').value.trim();
        var statusEl = document.getElementById('testConnResult');
        var btn = document.getElementById('btnTestConn');

        if (!provider || !provider.value) {
            statusEl.className = 'test-conn-result fw-medium text-danger';
            statusEl.textContent = 'Choose a provider first.';
            return;
        }

        if (!modelName) {
            statusEl.className = 'test-conn-result fw-medium text-warning text-dark';
            statusEl.textContent = 'Choose a model first, or fetch the list.';
            return;
        }

        statusEl.className = 'test-conn-result fw-medium text-secondary';
        statusEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Testing inference response (model may be loading)...';
        btn.disabled = true;

        fetch(providerRoutes.test, {
            method: 'POST',
            headers: providerHeaders(),
            body: JSON.stringify({ provider_id: provider.value, bot_id: providerRoutes.botId, model_name: modelName })
        })
        .then(providerJson)
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                statusEl.className = 'test-conn-result fw-medium text-success';
                statusEl.textContent = data.message;
            } else {
                statusEl.className = 'test-conn-result fw-medium text-danger';
                statusEl.textContent = data.message;
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            statusEl.className = 'test-conn-result fw-medium text-danger';
            statusEl.textContent = 'Could not run the test: ' + err.message;
        });
    }
</script>
@endpush
