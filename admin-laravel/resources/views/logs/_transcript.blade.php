{{-- The transcript modal, opened with viewTranscript(id). Shared by the
     Conversations and Analytics pages. --}}
<div class="modal fade" id="transcriptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            {{-- Who, when and where from on the left; the session id on the
                 right, for matching a conversation to the widget's own logs. --}}
            <div class="modal-header transcript-head">
                <span class="identity transcript-avatar" id="transcriptAvatar"><i class="bi bi-chat-text"></i></span>
                <div class="transcript-heading">
                    <h6 class="modal-title" id="modalBotName">Transcript</h6>
                    <div class="transcript-facts" id="transcriptFacts">Loading</div>
                </div>
                <div class="transcript-session" id="transcriptSession" hidden>
                    <span class="transcript-session-label">Session</span>
                    <span class="transcript-session-id figure-mono" id="transcriptSessionId"></span>
                </div>
                <button type="button" class="btn-close transcript-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body overflow-auto transcript-body" id="transcriptBody"></div>
            {{-- Which model did each job across the session, out of the way. --}}
            <div class="modal-footer transcript-foot" id="transcriptFoot" hidden>
                <span class="transcript-foot-label">Models</span>
                <div class="transcript-models" id="transcriptModels"></div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
{{-- The same renderer the widget uses, so a transcript reads the way the
     visitor saw it rather than as raw Markdown. --}}
<script src="{{ \App\Services\EngineClient::baseUrl() }}/widget-markdown.js"></script>
<style>
    .transcript-md > *:first-child { margin-top: 0; }
    .transcript-md > *:last-child { margin-bottom: 0; }
    .transcript-md p { margin: 0 0 0.5rem; }
    .transcript-md ul, .transcript-md ol { margin: 0 0 0.5rem; padding-left: 1.25rem; }
    .transcript-md li { margin: 0.1rem 0; }
    .transcript-md code { font-size: 0.75rem; background: var(--surface-2, #f4f4f5); border: 1px solid var(--border); border-radius: 4px; padding: 0 3px; }
    .transcript-md pre { margin: 0 0 0.5rem; padding: 0.5rem 0.65rem; background: #18181B; border-radius: 6px; overflow-x: auto; }
    .transcript-md pre code { background: none; border: 0; padding: 0; color: #F4F4F5; }
    .transcript-md .md-table { overflow-x: auto; margin: 0 0 0.5rem; }
    .transcript-md table { border-collapse: collapse; font-size: 0.75rem; width: 100%; }
    .transcript-md th, .transcript-md td { border: 1px solid var(--border); padding: 0.25rem 0.4rem; vertical-align: top; overflow-wrap: anywhere; min-width: 84px; }
    .transcript-head { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; padding: 0.875rem 1rem; }
    .transcript-avatar { flex-shrink: 0; }
    .transcript-heading { flex: 1 1 200px; min-width: 0; }
    .transcript-heading .modal-title { font-size: 0.9375rem; font-weight: 600; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .transcript-facts { font-size: 0.6875rem; line-height: 1.45; color: var(--text-muted); margin-top: 0.125rem; }
    .transcript-facts > div { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .transcript-origin { color: var(--text-faint); }
    .transcript-session { order: 2; display: flex; flex-direction: column; align-items: flex-end; min-width: 0; max-width: 45%; text-align: right; }
    .transcript-session[hidden] { display: none; }
    .transcript-session-label { font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-faint); font-weight: 500; }
    .transcript-session-id { font-size: 0.71875rem; color: var(--text-muted); max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .transcript-foot { justify-content: flex-start; gap: 0.5rem; padding: 0.625rem 1rem; }
    .transcript-foot[hidden] { display: none; }
    .transcript-foot > * { margin: 0; }
    .transcript-foot-label { font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-faint); font-weight: 500; }
    .transcript-models { display: flex; flex-wrap: wrap; gap: 0.3125rem; }
    .transcript-model { font-family: var(--font-mono); font-size: 0.6875rem; }
    .transcript-model b { font-family: 'Geist', sans-serif; font-weight: 500; color: var(--text-faint); text-transform: uppercase; letter-spacing: 0.04em; font-size: 0.625rem; }
    .transcript-close { order: 3; margin: 0 0 0 0.25rem !important; }
    .transcript-body { max-height: 560px; min-height: 220px; background: var(--bg); }
    @media (max-width: 575.98px) {
        .transcript-session { order: 4; max-width: none; width: 100%; align-items: flex-start; text-align: left; }
    }
    .transcript-stamp { display: flex; flex-wrap: wrap; gap: 0 0.5rem; margin-top: 0.25rem; font-size: 0.65625rem; color: var(--text-faint); }
    .transcript-gap::before { content: '\00b7'; margin-right: 0.5rem; }
    .transcript-gap.is-long { color: var(--accent); }
    .transcript-day { display: flex; align-items: center; gap: 0.625rem; margin: 0.25rem 0 0.875rem; font-size: 0.6875rem; color: var(--text-faint); }
    .transcript-day::before, .transcript-day::after { content: ''; flex: 1; border-top: 1px solid var(--border); }
    .transcript-md blockquote { margin: 0 0 0.5rem; padding-left: 0.6rem; border-left: 3px solid var(--border); }
</style>
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

    // An ISO timestamp read as local time, or left as it came.
    function transcriptTime(value) {
        var date = value ? new Date(value) : null;
        return date && !isNaN(date) ? date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : (value || '');
    }

    // How long passed between two bubbles, in the two largest units that
    // matter: "+4s", "+2m 14s", "+1h 3m", "+2d 4h".
    function transcriptGap(ms) {
        var s = Math.max(0, Math.round(ms / 1000));
        if (s < 60) return '+' + s + 's';
        var m = Math.floor(s / 60);
        if (m < 60) return '+' + m + 'm' + (s % 60 ? ' ' + (s % 60) + 's' : '');
        var h = Math.floor(m / 60);
        if (h < 24) return '+' + h + 'h' + (m % 60 ? ' ' + (m % 60) + 'm' : '');
        var d = Math.floor(h / 24);
        return '+' + d + 'd' + (h % 24 ? ' ' + (h % 24) + 'h' : '');
    }

    // A pause longer than this reads as the visitor having left and come back,
    // so its gap is drawn in the accent rather than faint.
    var TRANSCRIPT_LONG_PAUSE_MS = 5 * 60 * 1000;

    function renderTranscriptHead(data) {
        document.getElementById('modalBotName').textContent = data.bot_name;
        document.getElementById('transcriptAvatar').textContent = (data.bot_name || '?').charAt(0).toUpperCase();

        // When and how many on one line, where from on the next.
        var facts = document.getElementById('transcriptFacts');
        facts.innerHTML = '';
        var when = document.createElement('div');
        when.textContent = [data.bot_deleted ? 'Deleted bot' : null, data.started_at, data.message_count + (data.message_count === 1 ? ' message' : ' messages')]
            .filter(Boolean).join(' \u00b7 ');
        facts.appendChild(when);
        var origin = document.createElement('div');
        origin.className = 'figure-mono transcript-origin';
        origin.textContent = data.origin || 'preview sandbox';
        facts.appendChild(origin);
        // Here rather than in the table: it is for matching a conversation to
        // the widget's own logs, not for scanning a list.
        var sessionId = document.getElementById('transcriptSessionId');
        sessionId.textContent = data.session_id || '';
        sessionId.title = data.session_id || '';
        document.getElementById('transcriptSession').hidden = !data.session_id;

        // One chip per job; a job whose model changed mid-session lists both.
        var models = document.getElementById('transcriptModels');
        models.innerHTML = '';
        Object.keys(data.models || {}).forEach(function (job) {
            var chip = document.createElement('span');
            chip.className = 'chip transcript-model';
            chip.title = job + ': ' + data.models[job].join(', ');
            var label = document.createElement('b');
            label.textContent = job;
            chip.appendChild(label);
            chip.appendChild(document.createTextNode(data.models[job].join(' / ')));
            models.appendChild(chip);
        });
        document.getElementById('transcriptFoot').hidden = models.children.length === 0;
    }

    function viewTranscript(convId) {
        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('transcriptModal'));
        var body = document.getElementById('transcriptBody');

        document.getElementById('modalBotName').textContent = 'Transcript';
        document.getElementById('transcriptAvatar').innerHTML = '<i class="bi bi-chat-text"></i>';
        document.getElementById('transcriptFacts').textContent = 'Loading';
        document.getElementById('transcriptModels').innerHTML = '';
        document.getElementById('transcriptFoot').hidden = true;
        document.getElementById('transcriptSession').hidden = true;
        body.innerHTML = transcriptSkeleton();
        modal.show();

        fetch('/logs/' + encodeURIComponent(convId) + '/transcript')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                renderTranscriptHead(data);
                body.innerHTML = '';

                if (!data.messages || data.messages.length === 0) {
                    body.innerHTML = '<div class="empty"><i class="bi bi-chat-left-text"></i>' +
                                     '<h6>No messages in this session</h6>' +
                                     '<p>The session was opened but nothing was sent.</p></div>';
                    return;
                }

                var previousAt = null;
                var previousDay = null;

                data.messages.forEach(function (msg) {
                    var isUser = msg.sender === 'user';

                    // A divider whenever the day turns over, so a time on its
                    // own is never ambiguous.
                    var at = msg.created_at ? new Date(msg.created_at) : null;
                    if (at && isNaN(at)) at = null;
                    var day = at ? at.toDateString() : null;
                    if (day && day !== previousDay) {
                        var divider = document.createElement('div');
                        divider.className = 'transcript-day';
                        divider.textContent = at.toLocaleDateString([], { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
                        body.appendChild(divider);
                        previousDay = day;
                    }

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
                    // A visitor's own words stay literal; only the bot's
                    // Markdown is turned into markup.
                    var renderer = window.__ChatbotMarkdown;
                    if (!isUser && renderer) {
                        bubble.innerHTML = renderer.render(msg.content);
                        bubble.classList.add('transcript-md');
                    } else {
                        bubble.style.whiteSpace = 'pre-wrap';
                        bubble.textContent = msg.content;
                    }

                    if (!isUser && msg.reasoning) {
                        var think = document.createElement('details');
                        think.style.maxWidth = '78%';
                        think.style.marginBottom = '0.35rem';
                        think.style.fontSize = '0.75rem';
                        think.style.border = '1px solid var(--border)';
                        think.style.borderRadius = 'var(--r-sm, 6px)';
                        think.style.background = 'var(--surface-2, var(--surface))';
                        think.style.padding = '0.35rem 0.6rem';

                        var summary = document.createElement('summary');
                        summary.className = 'text-muted';
                        summary.style.cursor = 'pointer';
                        summary.textContent = 'Thinking';
                        think.appendChild(summary);

                        var thought = document.createElement('div');
                        thought.className = 'text-muted mt-2';
                        thought.style.whiteSpace = 'pre-wrap';
                        thought.style.lineHeight = '1.55';
                        thought.style.maxHeight = '220px';
                        thought.style.overflowY = 'auto';
                        thought.textContent = msg.reasoning;
                        think.appendChild(thought);

                        wrapper.appendChild(think);
                    }

                    if (!isUser && msg.db_sql) {
                        // Auditing a wrong answer needs the statement, not a
                        // guess at it. Folded away, like the reasoning above.
                        var query = document.createElement('details');
                        query.style.maxWidth = '78%';
                        query.style.marginBottom = '0.35rem';
                        query.style.fontSize = '0.75rem';
                        query.style.border = '1px solid var(--border)';
                        query.style.borderRadius = 'var(--r-sm, 6px)';
                        query.style.background = 'var(--surface-2, var(--surface))';
                        query.style.padding = '0.35rem 0.6rem';

                        var querySummary = document.createElement('summary');
                        querySummary.className = 'text-muted';
                        querySummary.style.cursor = 'pointer';
                        querySummary.textContent = 'Answered from live data, '
                            + (msg.db_row_count === null ? 'unknown' : msg.db_row_count) + ' rows';
                        query.appendChild(querySummary);

                        var statement = document.createElement('pre');
                        statement.className = 'figure-mono text-muted mt-2 mb-0';
                        statement.style.whiteSpace = 'pre-wrap';
                        statement.style.fontSize = '0.72rem';
                        statement.textContent = msg.db_sql;
                        query.appendChild(statement);

                        wrapper.appendChild(query);
                    }

                    // The time stays within reach on hover. Who spoke is the side
                    // the bubble sits on, and the models are in the header.
                    bubble.title = (isUser ? 'Visitor' : data.bot_name) + ', ' + transcriptTime(msg.created_at);

                    wrapper.appendChild(bubble);

                    // The time of every bubble, and how long after the one
                    // before it, so pauses and slow answers can be read off.
                    if (at) {
                        var stamp = document.createElement('div');
                        stamp.className = 'transcript-stamp figure-mono';
                        var clock = document.createElement('time');
                        clock.dateTime = msg.created_at;
                        clock.textContent = at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                        stamp.appendChild(clock);
                        if (previousAt) {
                            var waited = at - previousAt;
                            var gap = document.createElement('span');
                            gap.className = 'transcript-gap' + (waited >= TRANSCRIPT_LONG_PAUSE_MS ? ' is-long' : '');
                            gap.textContent = transcriptGap(waited);
                            gap.title = isUser ? 'Since the previous message' : 'Until this reply was saved';
                            stamp.appendChild(gap);
                        }
                        // The bot's own wait, when the engine measured it.
                        if (!isUser && msg.first_token_ms !== null && msg.first_token_ms !== undefined) {
                            var first = document.createElement('span');
                            first.className = 'transcript-gap';
                            first.title = 'Time to first token, and to the whole reply';
                            first.textContent = 'first token ' + (msg.first_token_ms / 1000).toFixed(1) + 's'
                                + (msg.response_ms !== null && msg.response_ms !== undefined
                                    ? ', done ' + (msg.response_ms / 1000).toFixed(1) + 's' : '');
                            stamp.appendChild(first);
                        }
                        wrapper.appendChild(stamp);
                        previousAt = at;
                    }

                    // What the intent step made of a visitor's message. Without
                    // it, an answer about the wrong product reads as the bot's
                    // mistake rather than the question's.
                    if (isUser && (msg.intent === 'chat' || msg.intent_query)) {
                        var understood = document.createElement('span');
                        understood.className = 'text-muted mt-1';
                        understood.style.fontSize = '0.6875rem';
                        understood.style.maxWidth = '78%';
                        understood.textContent = msg.intent === 'chat'
                            ? 'Read as small talk, so no source was searched'
                            : 'Searched as: ' + msg.intent_query;
                        wrapper.appendChild(understood);
                    }

                    // What the guard named. On a visitor's message it was
                    // refused; on an answer it had already been sent.
                    if (msg.guard_flag) {
                        var flag = document.createElement('span');
                        flag.className = 'badge bg-danger-subtle text-danger-emphasis mt-1';
                        flag.style.fontSize = '0.6875rem';
                        flag.textContent = (isUser ? 'Refused by the guard: ' : 'Flagged by the guard: ') + msg.guard_flag;
                        wrapper.appendChild(flag);
                    }

                    body.appendChild(wrapper);
                });
            })
            .catch(function () {
                document.getElementById('transcriptFacts').textContent = 'Not loaded';
                body.innerHTML = '<div class="empty"><i class="bi bi-exclamation-triangle"></i>' +
                                 '<h6>Could not load the transcript</h6>' +
                                 '<p>The request to the server failed. Try again in a moment.</p></div>';
            });
    }
</script>
@endpush
