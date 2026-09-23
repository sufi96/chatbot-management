{{-- Floating save bar shared by the Profile and Behaviour tabs.

     With $track on, the bar stays out of the way until a field differs from
     how the page loaded, then shows how many fields are unsaved. Fields are
     compared by name, so a radio group or a reordered list counts once.
     Some fields are set from script without an input event (source order,
     prompt presets), so the count is also refreshed on a short timer.

     After a failed save the fields hold what was submitted, not what is
     stored, so the bar shows from the start and says so.

     Expects: $formId, $saveLabel, $track. $hint is shown when not tracking. --}}
@php $unsavedOnLoad = $track && $errors->any(); @endphp
<div class="form-actions" id="{{ $formId }}Actions"
     @if($track) data-track-form="{{ $formId }}" @endif
     @if($track && !$unsavedOnLoad) hidden @endif>
    @if($track)
        <span class="unsaved-status" role="status" aria-live="polite">
            <span class="unsaved-dot"></span>
            <span data-unsaved-text>{{ $unsavedOnLoad ? 'Not saved' : '' }}</span>
        </span>
    @else
        <span class="text-muted d-none d-xl-inline text-nowrap" style="font-size: 0.75rem;">{{ $hint }}</span>
    @endif
    <div class="d-flex align-items-center gap-2 ms-auto">
        @if($track)
            {{-- Reloading is the only reset that also puts back what the page's
                 scripts arranged, such as the answer source order. --}}
            <button type="button" class="btn btn-outline-secondary" data-discard>Discard</button>
        @else
            <a href="{{ route('bots.index') }}" class="btn btn-outline-secondary">Cancel</a>
        @endif
        <button type="submit" class="btn btn-brand">{{ $saveLabel }}</button>
    </div>
</div>

@if($track)
    @once
        @push('scripts')
        <script>
            (function () {
                document.querySelectorAll('[data-track-form]').forEach(function (bar) {
                    var form = document.getElementById(bar.dataset.trackForm);
                    var text = bar.querySelector('[data-unsaved-text]');
                    var failedSave = !bar.hidden;
                    var baseline = null;

                    function snapshot() {
                        var fields = {};
                        new FormData(form).forEach(function (value, name) {
                            if (name === '_token' || name === '_method') return;
                            if (value instanceof File) {
                                if (!value.name) return;
                                value = value.name + ':' + value.size;
                            }
                            (fields[name] = fields[name] || []).push(String(value));
                        });
                        return fields;
                    }

                    function countChanges() {
                        var now = snapshot();
                        var names = Object.keys(Object.assign({}, baseline, now));
                        return names.filter(function (name) {
                            return JSON.stringify(baseline[name] || []) !== JSON.stringify(now[name] || []);
                        }).length;
                    }

                    function refresh() {
                        if (!baseline) return;
                        var count = countChanges();
                        if (failedSave) {
                            text.textContent = count
                                ? 'Not saved · ' + count + ' more ' + (count === 1 ? 'change' : 'changes')
                                : 'Not saved';
                            return;
                        }
                        bar.hidden = count === 0;
                        if (count) {
                            text.textContent = count + ' unsaved ' + (count === 1 ? 'change' : 'changes');
                        }
                    }

                    // Taken once the page's own scripts have filled their fields.
                    window.addEventListener('load', function () {
                        setTimeout(function () {
                            baseline = snapshot();
                            refresh();
                        }, 150);
                    });

                    form.addEventListener('input', refresh);
                    form.addEventListener('change', refresh);
                    form.addEventListener('click', function () { setTimeout(refresh, 0); });
                    setInterval(refresh, 700);

                    bar.querySelector('[data-discard]').addEventListener('click', function () {
                        window.location.assign(window.location.href);
                    });
                });
            })();
        </script>
        @endpush
    @endonce
@endif
