{{-- The bot picker: bots grouped by workspace, ticked one by one or a whole
     workspace at once. Sits inside a GET form, which Apply submits. Every box
     ticked sends nothing, which the list reads as all, so the address stays
     short. Needs $botGroups (workspace => bots) and $selectedBots (ids; empty
     is all); $pickerAlignEnd opens the menu from the right edge. --}}
@php
    // What the button says: all, the one bot, or how many.
    $allBots = $botGroups->flatMap(fn ($group) => $group['bots']);
    $pickerLabel = match (true) {
        empty($selectedBots) => 'All bot profiles',
        count($selectedBots) === 1 => $allBots->firstWhere('id', $selectedBots[0])?->name ?? '1 bot profile',
        default => count($selectedBots) . ' bot profiles',
    };
@endphp
<div class="dropdown bot-picker" id="botPicker">
    <button type="button" class="form-select list-select bot-picker-toggle" data-bs-toggle="dropdown"
            data-bs-auto-close="outside" aria-expanded="false" aria-haspopup="true">
        <span class="text-truncate">{{ $pickerLabel }}</span>
    </button>
    <div class="dropdown-menu bot-picker-menu {{ !empty($pickerAlignEnd) ? 'dropdown-menu-end' : '' }}">
        @if($allBots->count() > 8)
            <div class="bot-picker-filter">
                <input type="search" class="form-control form-control-sm" placeholder="Find a bot or workspace"
                       aria-label="Find a bot or workspace" autocomplete="off" data-pick-filter>
            </div>
        @endif

        <label class="bot-picker-row bot-picker-all">
            <input type="checkbox" class="form-check-input" data-pick-all @checked(empty($selectedBots))>
            <span class="bot-picker-name">All bot profiles</span>
            <span class="bot-picker-count figure-mono">{{ $allBots->count() }}</span>
        </label>

        <div class="bot-picker-list">
            @forelse($botGroups as $group)
                <div class="bot-picker-group" data-pick-group data-pick-text="{{ mb_strtolower($group['system']->name) }}">
                    <label class="bot-picker-row bot-picker-head">
                        <input type="checkbox" class="form-check-input" data-pick-workspace>
                        <span class="bot-picker-name">{{ $group['system']->name }}</span>
                        <span class="bot-picker-count figure-mono">{{ $group['bots']->count() }}</span>
                    </label>
                    @foreach($group['bots'] as $b)
                        <label class="bot-picker-row bot-picker-item" data-pick-text="{{ mb_strtolower($b->name) }}">
                            <input type="checkbox" class="form-check-input" name="bots[]" value="{{ $b->id }}" @checked(empty($selectedBots) || in_array($b->id, $selectedBots, true))>
                            <span class="bot-picker-name">{{ $b->name }}</span>
                            @if($b->trashed())
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Deleted</span>
                            @endif
                        </label>
                    @endforeach
                </div>
            @empty
                <div class="bot-picker-none">No bot profiles yet.</div>
            @endforelse
            <div class="bot-picker-none" data-pick-nomatch hidden>Nothing matches.</div>
        </div>

        <div class="bot-picker-foot">
            <span class="bot-picker-hint" data-pick-hint></span>
            <button type="submit" class="btn btn-sm btn-brand" data-pick-apply>Apply</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // ---- Bot picker ----------------------------------------------------------
    (function () {
        var picker = document.getElementById('botPicker');
        if (!picker) return;

        var form = picker.closest('form');
        var all = picker.querySelector('[data-pick-all]');
        var bots = Array.prototype.slice.call(picker.querySelectorAll('input[name="bots[]"]'));
        var groups = Array.prototype.slice.call(picker.querySelectorAll('[data-pick-group]'));
        var apply = picker.querySelector('[data-pick-apply]');
        var hint = picker.querySelector('[data-pick-hint]');
        var filter = picker.querySelector('[data-pick-filter]');
        var noMatch = picker.querySelector('[data-pick-nomatch]');

        // A box over several reads ticked, empty or part-ticked from them.
        function mirror(box, members) {
            var ticked = members.filter(function (m) { return m.checked; }).length;
            box.checked = members.length > 0 && ticked === members.length;
            box.indeterminate = ticked > 0 && ticked < members.length;
        }

        function groupBots(group) {
            return Array.prototype.slice.call(group.querySelectorAll('input[name="bots[]"]'));
        }

        function sync() {
            groups.forEach(function (group) {
                mirror(group.querySelector('[data-pick-workspace]'), groupBots(group));
            });
            mirror(all, bots);

            var ticked = bots.filter(function (b) { return b.checked; }).length;
            apply.disabled = bots.length > 0 && ticked === 0;
            hint.textContent = ticked === 0 ? 'Tick at least one'
                : (ticked === bots.length ? 'All selected' : ticked + ' of ' + bots.length + ' selected');
        }

        all.addEventListener('change', function () {
            bots.forEach(function (b) { b.checked = all.checked; });
            sync();
        });
        groups.forEach(function (group) {
            var head = group.querySelector('[data-pick-workspace]');
            head.addEventListener('change', function () {
                groupBots(group).forEach(function (b) { b.checked = head.checked; });
                sync();
            });
        });
        bots.forEach(function (b) { b.addEventListener('change', sync); });

        if (filter) {
            filter.addEventListener('input', function () {
                var q = filter.value.trim().toLowerCase();
                var shown = 0;
                groups.forEach(function (group) {
                    var groupHit = !q || group.dataset.pickText.indexOf(q) !== -1;
                    var any = false;
                    group.querySelectorAll('.bot-picker-item').forEach(function (item) {
                        var hit = groupHit || item.dataset.pickText.indexOf(q) !== -1;
                        item.hidden = !hit;
                        any = any || hit;
                    });
                    group.hidden = !any;
                    if (any) shown++;
                });
                noMatch.hidden = shown > 0;
            });
            picker.addEventListener('shown.bs.dropdown', function () { filter.focus(); });
        }

        // Everything ticked is the same as nothing chosen: send no ids.
        form.addEventListener('submit', function () {
            if (bots.every(function (b) { return b.checked; })) {
                bots.forEach(function (b) { b.disabled = true; });
            }
        });

        sync();
    })();
</script>
@endpush
