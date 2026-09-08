@extends('layouts.app')

@section('content')
<div class="d-flex flex-column gap-4">

    <!-- Header Banner -->
    <div class="card border-0 shadow-sm p-4 rounded-4" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #eef2f6 !important;">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.02em;">Conversations & Transcripts</h4>
                    <span class="badge bg-light text-secondary border font-monospace small">{{ $activeSystem->name }}</span>
                </div>
                <p class="text-secondary small mb-0">Audit chat sessions, inquiries, and LLM responses recorded in workspace: <strong>{{ $activeSystem->name }}</strong></p>
            </div>

            <!-- Filter by Bot Profile -->
            <form method="GET" action="{{ route('logs.index') }}" class="d-flex align-items-center gap-2">
                <select name="bot_id" class="form-select form-select-sm py-2 px-3 rounded-3 shadow-sm border" onchange="this.form.submit()">
                    <option value="">All Bot Profiles</option>
                    @foreach($bots as $b)
                        <option value="{{ $b->id }}" {{ $selectedBot === $b->id ? 'selected' : '' }}>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>

    <!-- Conversations Table Card -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 px-4 d-flex align-items-center justify-content-between border-bottom">
            <div>
                <h6 class="mb-0 fw-bold text-dark">Recorded Chat Sessions</h6>
                <small class="text-muted" style="font-size: 0.75rem;">Total: {{ $conversations->total() ?? $conversations->count() }} session(s)</small>
            </div>
            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2.5 py-1 rounded-pill small fw-semibold">
                Live Audit Trail
            </span>
        </div>

        @if($conversations->isEmpty())
            <div class="card-body p-5 text-center text-muted">
                <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
                    <i class="bi bi-chat-left-dots fs-3 text-secondary"></i>
                </div>
                <h6 class="fw-bold text-dark">No conversation sessions recorded yet</h6>
                <p class="small text-muted mb-0">Interactions from embedded website widgets and sandbox testing will appear here automatically.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                        <tr>
                            <th class="ps-4 py-3" style="min-width: 200px;">Bot Profile</th>
                            <th class="py-3" style="min-width: 260px;">First Message Preview</th>
                            <th class="py-3" style="width: 110px; white-space: nowrap;">Volume</th>
                            <th class="py-3" style="width: 170px; white-space: nowrap;">Origin / Domain</th>
                            <th class="py-3" style="width: 140px; white-space: nowrap;">Timestamp</th>
                            <th class="text-end pe-4 py-3" style="width: 160px; white-space: nowrap;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($conversations as $conv)
                            <tr>
                                <td class="ps-4 py-3.5">
                                    <div class="fw-semibold text-dark">{{ $conv->bot->name ?? 'Deleted Bot' }}</div>
                                    <small class="text-muted font-monospace" style="font-size: 0.7rem;">Session: {{ substr($conv->session_id, 0, 18) }}...</small>
                                </td>
                                <td class="text-truncate" style="max-width: 300px;">
                                    @php
                                        $firstMsg = $conv->messages->firstWhere('sender', 'user');
                                    @endphp
                                    <span class="text-dark fw-medium small">{{ $firstMsg ? $firstMsg->content : 'No messages' }}</span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-2.5 py-1 rounded-pill small fw-semibold btn-nowrap">
                                        {{ $conv->messages->count() }} msgs
                                    </span>
                                </td>
                                <td>
                                    <code class="small font-monospace text-secondary px-2 py-1 rounded bg-light border btn-nowrap" style="font-size: 0.72rem;">{{ $conv->origin ?: 'Direct / Sandbox' }}</code>
                                </td>
                                <td class="text-muted small text-nowrap">
                                    {{ $conv->created_at->format('M d, H:i') }}
                                </td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-outline-primary py-1.5 px-3 rounded-3 d-inline-flex align-items-center gap-1.5 shadow-sm btn-nowrap" onclick="viewTranscript('{{ $conv->id }}')" style="font-size: 0.78rem;">
                                        <i class="bi bi-chat-text"></i> View Transcript
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-3 px-4 border-top">
                {{ $conversations->links() }}
            </div>
        @endif
    </div>

    <!-- Transcript Modal with Spacious Chat Bubbles -->
    <div class="modal fade" id="transcriptModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header py-3 px-4 border-bottom">
                    <div>
                        <h6 class="modal-title fw-bold text-dark mb-0">Conversation Transcript</h6>
                        <small class="text-muted" id="modalBotName">Bot</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 bg-light overflow-auto" id="transcriptBody" style="max-height: 540px; min-height: 240px;">
                    <p class="text-muted text-center small my-4">Loading transcript...</p>
                </div>
                <div class="modal-footer py-2.5 px-4 bg-light border-top">
                    <button type="button" class="btn btn-sm btn-secondary px-3.5" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

</div>

@push('scripts')
<script>
    function viewTranscript(convId) {
        var modal = new bootstrap.Modal(document.getElementById('transcriptModal'));
        var body = document.getElementById('transcriptBody');
        var botNameEl = document.getElementById('modalBotName');

        body.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div><small>Loading conversation messages...</small></div>';
        modal.show();

        fetch('/logs/' + encodeURIComponent(convId) + '/transcript')
            .then(function(res) { return res.json(); })
            .then(function(data) {
                botNameEl.textContent = 'Bot: ' + data.bot_name;
                body.innerHTML = '';

                if (!data.messages || data.messages.length === 0) {
                    body.innerHTML = '<div class="text-center py-5 text-muted"><i class="bi bi-chat-left-dots fs-3 d-block mb-2"></i><small>No messages recorded in this session.</small></div>';
                    return;
                }

                data.messages.forEach(function(msg) {
                    var isUser = msg.sender === 'user';
                    var wrapper = document.createElement('div');
                    wrapper.className = 'd-flex flex-column mb-3 ' + (isUser ? 'align-items-end' : 'align-items-start');

                    var bubble = document.createElement('div');
                    bubble.className = 'p-3 shadow-sm small ' + (isUser ? 'bg-primary text-white' : 'bg-white text-dark border');
                    bubble.style.maxWidth = '82%';
                    bubble.style.lineHeight = '1.5';
                    bubble.style.borderRadius = '16px';
                    if (isUser) {
                        bubble.style.borderBottomRightRadius = '4px';
                    } else {
                        bubble.style.borderBottomLeftRadius = '4px';
                    }
                    bubble.textContent = msg.content;

                    var time = document.createElement('span');
                    time.className = 'text-muted mt-1 px-1';
                    time.style.fontSize = '0.68rem';
                    time.textContent = (isUser ? 'User' : data.bot_name) + ' • ' + (msg.created_at || 'Just now');

                    wrapper.appendChild(bubble);
                    wrapper.appendChild(time);
                    body.appendChild(wrapper);
                });
            })
            .catch(function(err) {
                body.innerHTML = '<div class="text-center py-5 text-danger"><i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i><small>Failed to load transcript data.</small></div>';
            });
    }
</script>
@endpush
@endsection
