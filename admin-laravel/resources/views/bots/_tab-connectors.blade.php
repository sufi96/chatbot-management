{{-- The Connectors tab: one switch for the whole tab, then what the bot may
     reach, a card each. MCP servers (which can be other agents) and APIs are
     the workspace's connectors, split by type; built-in tools are C⁴'s own.
     Everything below the switch is still saved while it is off, so an editor
     can prepare a bot's tools before letting it use them. --}}
@php
    $connectorCard = function (string $id, string $icon, string $title, string $hint) {
        return compact('id', 'icon', 'title', 'hint');
    };
    $ticked = old('tools', $attachedTools);
    $mcpServers = $connectors->where('type', 'mcp')->values();
    $apis = $connectors->where('type', '!=', 'mcp')->values();
    $builtins = old('builtin_tools', $bot->builtin_tools ?? []);
    $tickedSkills = old('skills', $attachedSkills);
@endphp

<div class="col-12">
    <div class="card mb-3 connectors-master">
        <button type="button" class="card-header card-toggle d-flex align-items-center justify-content-between gap-2"
                data-bs-toggle="collapse" data-bs-target="#bcard-agentic"
                aria-expanded="true" aria-controls="bcard-agentic">
            <span class="d-flex align-items-center gap-1.5"><i class="bi bi-plug"></i> Connectors</span>
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-chevron-down card-toggle-chevron" aria-hidden="true"></i>
            </span>
        </button>
        <div class="collapse show" id="bcard-agentic" data-card-collapse="agentic">
        <div class="p-3 d-flex flex-wrap align-items-start justify-content-between gap-3">
            <input type="hidden" name="agentic_present" value="1">
            <div style="max-width: 640px;">
                <div class="form-check form-switch d-flex align-items-center gap-2 mb-1">
                    <input class="form-check-input" type="checkbox" role="switch" name="agentic_enabled" value="1"
                           id="agentic_enabled" style="width: 2.6em; height: 1.4em;"
                           @checked(old('agentic_enabled', $bot->agentic_enabled))>
                    <label class="form-check-label fw-semibold" for="agentic_enabled" style="font-size: 0.95rem;">Use connectors</label>
                </div>
                <div class="form-text text-faint mb-0">
                    The master switch for this tab. On: when a question needs it, the bot may call what is ticked
                    below, in the place "Connected systems" holds in the Brain tab's source order. A call that
                    changes something waits for the visitor to press Confirm. Off: nothing below is called.
                </div>
            </div>
            <div>
                <label class="form-label small mb-1" for="agentic_max_calls">Calls per message, at most</label>
                <select class="form-select form-select-sm" name="agentic_max_calls" id="agentic_max_calls" style="width: 110px;">
                    @foreach(range(1, 5) as $n)
                        <option value="{{ $n }}" @selected((int) old('agentic_max_calls', $bot->agentic_max_calls) === $n)>{{ $n }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        </div>
    </div>
    @error('tools')
        <div class="alert alert-danger py-2 small">{{ $message }}</div>
    @enderror
    @error('skills')
        <div class="alert alert-danger py-2 small">{{ $message }}</div>
    @enderror
</div>

@foreach([
    $connectorCard('mcp', 'bi-hdd-network', 'MCP servers', 'Tools on MCP servers, including other agents. Add a server on the Connectors page.'),
    $connectorCard('api', 'bi-code-slash', 'APIs', 'Operations imported from REST APIs. Add an API on the Connectors page.'),
] as $card)
    @php($group = $card['id'] === 'mcp' ? $mcpServers : $apis)
    <div class="col-12 col-xl-6 connectors-part">
        <div class="card mb-3 h-100">
            <button type="button" class="card-header card-toggle d-flex align-items-center justify-content-between gap-2"
                    data-bs-toggle="collapse" data-bs-target="#bcard-{{ $card['id'] }}"
                    aria-expanded="true" aria-controls="bcard-{{ $card['id'] }}">
                <span class="d-flex align-items-center gap-1.5"><i class="bi {{ $card['icon'] }}"></i> {{ $card['title'] }}</span>
                <span class="d-flex align-items-center gap-2">
                    <span class="badge rounded-pill text-bg-secondary">{{ $group->sum(fn ($c) => $c->tools->whereIn('id', $ticked)->count()) }} on</span>
                    <i class="bi bi-chevron-down card-toggle-chevron" aria-hidden="true"></i>
                </span>
            </button>
            <div class="collapse show" id="bcard-{{ $card['id'] }}" data-card-collapse="{{ $card['id'] }}">
            <div class="p-3">
                <div class="form-text text-faint mt-0 mb-2">{{ $card['hint'] }}</div>
                @forelse($group as $connector)
                    <div class="fw-semibold small mt-2 mb-1">{{ $connector->name }}</div>
                    @foreach($connector->tools as $tool)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="tools[]" value="{{ $tool->id }}"
                                   id="tool_{{ $tool->id }}" @checked(in_array($tool->id, $ticked, true))>
                            <label class="form-check-label small" for="tool_{{ $tool->id }}">
                                <span class="figure-mono">{{ $tool->name }}</span>
                                @if($tool->writes)
                                    <span class="badge text-bg-warning ms-1">Writes</span>
                                @endif
                                @if($tool->description)
                                    <span class="text-muted">· {{ \Illuminate\Support\Str::limit($tool->description, 80) }}</span>
                                @endif
                            </label>
                        </div>
                    @endforeach
                @empty
                    <p class="text-muted small mb-0">
                        None with switched-on tools in this workspace yet.
                        <a href="{{ route('connectors.index') }}">Open Connectors</a>.
                    </p>
                @endforelse
            </div>
            </div>
        </div>
    </div>
@endforeach

<div class="col-12 col-xl-6 connectors-part">
    <div class="card mb-3 h-100">
        <button type="button" class="card-header card-toggle d-flex align-items-center justify-content-between gap-2"
                data-bs-toggle="collapse" data-bs-target="#bcard-builtins"
                aria-expanded="true" aria-controls="bcard-builtins">
            <span class="d-flex align-items-center gap-1.5"><i class="bi bi-tools"></i> Built-in tools</span>
            <span class="d-flex align-items-center gap-2">
                <i class="bi bi-chevron-down card-toggle-chevron" aria-hidden="true"></i>
            </span>
        </button>
        <div class="collapse show" id="bcard-builtins" data-card-collapse="builtins">
        <div class="p-3">
            <div class="form-text text-faint mt-0 mb-2">Tools that come with C⁴. Nothing outside C⁴ is called.</div>
            @foreach(\App\Models\BotProfile::BUILTIN_TOOLS as $name => $about)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="builtin_tools[]" value="{{ $name }}"
                           id="builtin_{{ $name }}" @checked(in_array($name, $builtins, true))>
                    <label class="form-check-label small" for="builtin_{{ $name }}">
                        <span class="figure-mono">{{ $name }}</span> <span class="text-muted">· {{ $about }}</span>
                    </label>
                </div>
            @endforeach
        </div>
        </div>
    </div>
</div>

<div class="col-12 col-xl-6 connectors-part">
    <div class="card mb-3 h-100">
        <button type="button" class="card-header card-toggle d-flex align-items-center justify-content-between gap-2"
                data-bs-toggle="collapse" data-bs-target="#bcard-skills"
                aria-expanded="true" aria-controls="bcard-skills">
            <span class="d-flex align-items-center gap-1.5"><i class="bi bi-journal-check"></i> Skills</span>
            <span class="d-flex align-items-center gap-2">
                <span class="badge rounded-pill text-bg-secondary">{{ $skills->whereIn('id', $tickedSkills)->count() }} on</span>
                <i class="bi bi-chevron-down card-toggle-chevron" aria-hidden="true"></i>
            </span>
        </button>
        <div class="collapse show" id="bcard-skills" data-card-collapse="skills">
        <div class="p-3">
            <div class="form-text text-faint mt-0 mb-2">Step-by-step instructions the bot follows when a request matches one.</div>
            @forelse($skills as $skill)
                @php($missing = array_values(array_diff($skill->tools ?? [], $toolNames)))
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="skills[]" value="{{ $skill->id }}"
                           id="skill_{{ $skill->id }}" @checked(in_array($skill->id, $tickedSkills, true))>
                    <label class="form-check-label small" for="skill_{{ $skill->id }}">
                        <span class="figure-mono">{{ $skill->name }}</span>
                        <span class="text-muted">· {{ \Illuminate\Support\Str::limit($skill->description, 80) }}</span>
                        @if(($skill->mode ?? 'progressive') === 'always')
                            <span class="badge text-bg-info ms-1" title="Added to every answer">always on</span>
                        @endif
                        @if($missing)
                            <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1"
                                  title="Offered once the bot has these operations">needs: {{ implode(', ', $missing) }}</span>
                        @endif
                    </label>
                </div>
            @empty
                <p class="text-muted small mb-0">
                    No skills in this workspace yet. <a href="{{ route('skills.index') }}">Add or copy one</a>.
                </p>
            @endforelse
        </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // With the master switch off, the cards below fade: still editable, so a
    // bot's tools can be prepared before it is let use them, but plainly idle.
    (function () {
        var master = document.getElementById('agentic_enabled');
        if (!master) { return; }
        function paint() {
            document.querySelectorAll('.connectors-part').forEach(function (part) {
                part.style.opacity = master.checked ? '' : '0.55';
            });
        }
        master.addEventListener('change', paint);
        paint();
    })();
</script>
@endpush
