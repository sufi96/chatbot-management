@extends('layouts.app')

@section('page-title', 'Conversations')

@section('content')

<div class="page-head mb-4">
    <div>
        <h1>Conversations</h1>
        <p>Every session recorded in {{ $activeSystem->name }}, from embedded widgets and from the preview sandbox.</p>
    </div>

    <form method="GET" action="{{ route('logs.index') }}">
        <label for="bot_filter" class="visually-hidden">Filter by bot profile</label>
        <select name="bot_id" id="bot_filter" class="form-select" style="min-width: 220px;" onchange="this.form.submit()">
            <option value="">All bot profiles</option>
            @foreach($bots as $b)
                <option value="{{ $b->id }}" {{ $selectedBot === $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
            @endforeach
        </select>
    </form>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <span>Recorded sessions</span>
        <span class="chip figure-mono">{{ $conversations->total() ?? $conversations->count() }} total</span>
    </div>

    @if($conversations->isEmpty())
        <div class="empty">
            <i class="bi bi-chat-left-text"></i>
            <h6>No sessions recorded</h6>
            <p>Once a visitor opens a widget on a host site, the session and every message land here.</p>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 190px;">Bot profile</th>
                        <th style="min-width: 260px;">Opening message</th>
                        <th style="width: 80px;">Turns</th>
                        <th style="min-width: 160px;">Origin</th>
                        <th style="width: 130px;">Started</th>
                        <th class="text-end" style="width: 120px;">Transcript</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($conversations as $conv)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $conv->bot->name ?? 'Deleted profile' }}</div>
                                <div class="figure-mono text-muted" style="font-size: 0.6875rem;">
                                    {{ \Illuminate\Support\Str::limit($conv->session_id, 22) }}
                                </div>
                            </td>
                            <td>
                                @php $firstMsg = $conv->messages->firstWhere('sender', 'user'); @endphp
                                <span class="d-block text-truncate" style="max-width: 340px;">
                                    {{ $firstMsg ? $firstMsg->content : 'No messages' }}
                                </span>
                            </td>
                            <td><span class="figure-mono">{{ $conv->messages->count() }}</span></td>
                            <td>
                                <span class="figure-mono text-muted text-truncate d-block" style="max-width: 200px;"
                                      title="{{ $conv->origin ?: 'preview sandbox' }}">
                                    {{ $conv->origin ?: 'preview sandbox' }}
                                </span>
                            </td>
                            <td class="text-muted figure-mono" style="font-size: 0.75rem;">
                                {{ $conv->created_at->format('M j, H:i') }}
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        onclick="viewTranscript('{{ $conv->id }}')">
                                    <i class="bi bi-chat-text"></i> Open
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="p-3" style="border-top: 1px solid var(--border);">
            {{ $conversations->links() }}
        </div>
    @endif
</div>

<div class="modal fade" id="transcriptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h6 class="modal-title mb-0">Transcript</h6>
                    <span class="text-muted" id="modalBotName" style="font-size: 0.75rem;">Loading</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body overflow-auto" id="transcriptBody"
                 style="max-height: 540px; min-height: 220px; background: var(--bg);">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
    function transcriptSkeleton() {
        var rows = '';
        var widths = ['62%', '44%', '72%'];
        for (var i = 0; i < widths.length; i++) {
            var alignSelf = i % 2 === 1 ? 'margin-left:auto;' : '';
            rows += '<div class="skeleton mb-3" style="height: 42px; width: ' + widths[i] + '; ' + alignSelf + '"></div>';
        }
        return rows;
    }

    function viewTranscript(convId) {
        var modal = new bootstrap.Modal(document.getElementById('transcriptModal'));
        var body = document.getElementById('transcriptBody');
        var botNameEl = document.getElementById('modalBotName');

        botNameEl.textContent = 'Loading';
        body.innerHTML = transcriptSkeleton();
        modal.show();

        fetch('/logs/' + encodeURIComponent(convId) + '/transcript')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                botNameEl.textContent = data.bot_name;
                body.innerHTML = '';

                if (!data.messages || data.messages.length === 0) {
                    body.innerHTML = '<div class="empty"><i class="bi bi-chat-left-text"></i>' +
                                     '<h6>No messages in this session</h6>' +
                                     '<p>The session was opened but nothing was sent.</p></div>';
                    return;
                }

                data.messages.forEach(function (msg) {
                    var isUser = msg.sender === 'user';

                    var wrapper = document.createElement('div');
                    wrapper.className = 'd-flex flex-column mb-3 ' + (isUser ? 'align-items-end' : 'align-items-start');

                    var bubble = document.createElement('div');
                    bubble.style.maxWidth = '78%';
                    bubble.style.padding = '0.5rem 0.75rem';
                    bubble.style.fontSize = '0.8125rem';
                    bubble.style.lineHeight = '1.55';
                    bubble.style.border = '1px solid var(--border)';
                    bubble.style.background = isUser ? 'var(--accent-soft)' : 'var(--surface)';
                    bubble.style.color = 'var(--text)';
                    bubble.style.borderRadius = isUser
                        ? 'var(--r-md) var(--r-md) var(--r-xs) var(--r-md)'
                        : 'var(--r-md) var(--r-md) var(--r-md) var(--r-xs)';
                    if (isUser) { bubble.style.borderColor = 'var(--accent-line)'; }
                    bubble.textContent = msg.content;

                    var meta = document.createElement('span');
                    meta.className = 'text-muted figure-mono mt-1';
                    meta.style.fontSize = '0.6875rem';
                    meta.textContent = (isUser ? 'visitor' : data.bot_name) + '  ' + (msg.created_at || 'just now');

                    wrapper.appendChild(bubble);
                    wrapper.appendChild(meta);
                    body.appendChild(wrapper);
                });
            })
            .catch(function () {
                body.innerHTML = '<div class="empty"><i class="bi bi-exclamation-triangle"></i>' +
                                 '<h6>Could not load the transcript</h6>' +
                                 '<p>The request to the server failed. Try again in a moment.</p></div>';
            });
    }
</script>
@endpush
