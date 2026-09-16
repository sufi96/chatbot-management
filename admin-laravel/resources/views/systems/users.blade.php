@extends('layouts.app')

@section('page-title', 'Workspace members')

@section('content')

<div class="page-head mb-4">
    <div>
        <a href="{{ route('systems.index') }}" class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
            <i class="bi bi-arrow-left"></i> Workspaces
        </a>
        <h1>{{ $system->name }}</h1>
        <p>Who can work in this workspace, and what each of them is allowed to do.</p>
    </div>

    <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#assignUserModal">
        <i class="bi bi-plus-lg"></i> Add member
    </button>
</div>

<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <span>Members</span>
        <span class="chip figure-mono">{{ $system->users->count() }} assigned</span>
    </div>

    @if($system->users->isEmpty())
        <div class="empty">
            <i class="bi bi-people"></i>
            <h6>No members assigned</h6>
            <p>Super admins already reach every workspace. Add people here to give scoped access to this one.</p>
            <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#assignUserModal">Add the first member</button>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 220px;">Member</th>
                        <th style="width: 130px;">Platform role</th>
                        <th style="width: 150px;">Workspace role</th>
                        <th style="width: 120px;">Added</th>
                        <th class="text-end" style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($system->users as $member)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2.5">
                                    <span class="identity">{{ strtoupper(substr($member->name, 0, 1)) }}</span>
                                    <div class="min-w-0">
                                        <div class="fw-semibold text-truncate">{{ $member->name }}</div>
                                        <div class="figure-mono text-muted text-truncate" style="font-size: 0.6875rem;">{{ $member->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if($member->isSuperAdmin())
                                    <span class="badge badge-role bg-danger-subtle">Super admin</span>
                                @else
                                    <span class="badge badge-role bg-secondary-subtle">Standard</span>
                                @endif
                            </td>
                            <td>
                                @if($member->pivot->role === 'system_admin')
                                    <span class="badge badge-role bg-primary-subtle">Admin</span>
                                @elseif($member->pivot->role === 'editor')
                                    <span class="badge badge-role bg-warning-subtle">Editor</span>
                                @else
                                    <span class="badge badge-role bg-info-subtle">Viewer</span>
                                @endif
                            </td>
                            <td class="text-muted figure-mono" style="font-size: 0.75rem;">
                                {{ $member->pivot->created_at ? $member->pivot->created_at->format('M j, Y') : '-' }}
                            </td>
                            <td class="text-end">
                                <div class="d-flex align-items-center justify-content-end gap-1.5">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal" data-bs-target="#editRoleModal{{ $member->id }}">
                                        Change role
                                    </button>
                                    <form action="{{ route('systems.users.remove', [$system->id, $member->id]) }}" method="POST"
                                          onsubmit="return confirm('Remove {{ $member->name }} from {{ $system->name }}?');" class="d-inline m-0">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove from workspace">
                                            <i class="bi bi-person-dash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="card">
    <div class="card-header">What each role can do</div>
    <div class="p-3">
        <div class="kv">
            <span class="kv-key" style="min-width: 110px; text-align: left;"><span class="badge badge-role bg-primary-subtle">Admin</span></span>
            <span class="kv-val text-start" style="font-family: var(--font-sans); color: var(--text-muted); flex: 1; margin-left: 1rem;">
                Full control in this workspace. Creates, edits and deletes bot profiles, and manages allowed origins.
            </span>
        </div>
        <div class="kv">
            <span class="kv-key" style="min-width: 110px; text-align: left;"><span class="badge badge-role bg-warning-subtle">Editor</span></span>
            <span class="kv-val text-start" style="font-family: var(--font-sans); color: var(--text-muted); flex: 1; margin-left: 1rem;">
                Creates and configures bot profiles, uploads images, tests inference. Cannot delete the workspace.
            </span>
        </div>
        <div class="kv">
            <span class="kv-key" style="min-width: 110px; text-align: left;"><span class="badge badge-role bg-info-subtle">Viewer</span></span>
            <span class="kv-val text-start" style="font-family: var(--font-sans); color: var(--text-muted); flex: 1; margin-left: 1rem;">
                Read only. Browses profiles, copies embed snippets and reads conversation transcripts.
            </span>
        </div>
    </div>
</div>

{{-- Modals live outside the table: a div is not valid inside tbody. --}}
@foreach($system->users as $member)
    <div class="modal fade" id="editRoleModal{{ $member->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h6 class="modal-title mb-0">Change role</h6>
                        <span class="text-muted" style="font-size: 0.75rem;">{{ $member->name }} in {{ $system->name }}</span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('systems.users.update', [$system->id, $member->id]) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-body">
                        <fieldset>
                            <legend class="form-label">Role in this workspace</legend>
                            <div class="d-flex flex-column gap-1.5">
                                @foreach([
                                    'system_admin' => ['Admin', 'Full control, including deleting bot profiles and editing allowed origins.'],
                                    'editor'       => ['Editor', 'Creates and configures bot profiles and tests inference.'],
                                    'viewer'       => ['Viewer', 'Read only access to profiles, embed snippets and transcripts.'],
                                ] as $value => $copy)
                                    <label class="d-flex align-items-start gap-2 p-2.5"
                                           style="border: 1px solid {{ $member->pivot->role === $value ? 'var(--accent)' : 'var(--border)' }};
                                                  border-radius: var(--r-sm); cursor: pointer;">
                                        <input type="radio" name="role" value="{{ $value }}" class="form-check-input mt-0 flex-shrink-0"
                                               {{ $member->pivot->role === $value ? 'checked' : '' }}>
                                        <span>
                                            <span class="d-block fw-semibold" style="font-size: 0.8125rem;">{{ $copy[0] }}</span>
                                            <span class="text-muted" style="font-size: 0.75rem;">{{ $copy[1] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand">Save role</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach

<div class="modal fade" id="assignUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title mb-0">Add a member to {{ $system->name }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('systems.users.assign', $system->id) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="assign_user" class="form-label">Account <span style="color: var(--danger);">*</span></label>
                        <select id="assign_user" name="user_id" class="form-select" required>
                            <option value="">Choose an account</option>
                            @foreach($allUsers as $u)
                                @if(!$system->users->contains('id', $u->id))
                                    <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                                @endif
                            @endforeach
                        </select>
                    </div>

                    <fieldset>
                        <legend class="form-label">Role in this workspace <span style="color: var(--danger);">*</span></legend>
                        <div class="d-flex flex-column gap-1.5">
                            @foreach([
                                'system_admin' => ['Admin', 'Full control, including deleting bot profiles and editing allowed origins.'],
                                'editor'       => ['Editor', 'Creates and configures bot profiles and tests inference.'],
                                'viewer'       => ['Viewer', 'Read only access to profiles, embed snippets and transcripts.'],
                            ] as $value => $copy)
                                <label class="d-flex align-items-start gap-2 p-2.5"
                                       style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                    <input type="radio" name="role" value="{{ $value }}" class="form-check-input mt-0 flex-shrink-0"
                                           {{ $value === 'editor' ? 'checked' : '' }}>
                                    <span>
                                        <span class="d-block fw-semibold" style="font-size: 0.8125rem;">{{ $copy[0] }}</span>
                                        <span class="text-muted" style="font-size: 0.75rem;">{{ $copy[1] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand">Add member</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
