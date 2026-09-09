@extends('layouts.app')

@section('page-title', 'Users and roles')

@section('content')

<div class="page-head mb-4">
    <div>
        <h1>Users and roles</h1>
        <p>Platform accounts and the role each one holds in each workspace. Super admins bypass workspace scoping entirely.</p>
    </div>

    <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newUserModal">
        <i class="bi bi-plus-lg"></i> New user
    </button>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <span>Accounts</span>
        <span class="chip figure-mono">{{ $users->count() }} total</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th style="min-width: 220px;">User</th>
                    <th style="width: 140px;">Platform role</th>
                    <th style="min-width: 280px;">Workspace access</th>
                    <th style="width: 120px;">Created</th>
                    <th class="text-end" style="width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $u)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2.5">
                                <span class="identity">{{ strtoupper(substr($u->name, 0, 1)) }}</span>
                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate">{{ $u->name }}</div>
                                    <div class="figure-mono text-muted text-truncate" style="font-size: 0.6875rem;">{{ $u->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            @if($u->isSuperAdmin())
                                <span class="badge badge-role bg-danger-subtle">Super admin</span>
                            @else
                                <span class="badge badge-role bg-secondary-subtle">Standard</span>
                            @endif
                        </td>
                        <td>
                            @if($u->isSuperAdmin())
                                <span class="text-muted">Every workspace ({{ $systems->count() }})</span>
                            @elseif($u->systems->isEmpty())
                                <span class="text-muted">No access assigned</span>
                            @else
                                <div class="d-flex flex-wrap gap-1.5" style="max-width: 460px;">
                                    @foreach($u->systems as $sys)
                                        <span class="chip">
                                            {{ $sys->name }}
                                            <span style="color: var(--text-faint);">/</span>
                                            @if($sys->pivot->role === 'system_admin')
                                                <span style="color: var(--accent);">admin</span>
                                            @elseif($sys->pivot->role === 'editor')
                                                <span style="color: var(--warn);">editor</span>
                                            @else
                                                <span style="color: var(--info);">viewer</span>
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="text-muted figure-mono" style="font-size: 0.75rem;">
                            {{ $u->created_at ? $u->created_at->format('M j, Y') : '-' }}
                        </td>
                        <td class="text-end">
                            <div class="d-flex align-items-center justify-content-end gap-1.5">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal" data-bs-target="#editUserModal{{ $u->id }}">
                                    Edit access
                                </button>

                                @if(auth()->id() !== $u->id)
                                    <form action="{{ route('users.destroy', $u->id) }}" method="POST"
                                          onsubmit="return confirm('Delete the account for {{ $u->name }}?');" class="d-inline m-0">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete account">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Modals live outside the table: a div is not valid inside tbody. --}}
@foreach($users as $u)
    <div class="modal fade" id="editUserModal{{ $u->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h6 class="modal-title mb-0">Edit {{ $u->name }}</h6>
                        <span class="text-muted" style="font-size: 0.75rem;">Account details and workspace roles.</span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('users.update', $u->id) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-body">
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-md-6">
                                <label for="eu_name_{{ $u->id }}" class="form-label">Full name <span style="color: var(--danger);">*</span></label>
                                <input type="text" id="eu_name_{{ $u->id }}" name="name" class="form-control" value="{{ $u->name }}" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="eu_email_{{ $u->id }}" class="form-label">Email <span style="color: var(--danger);">*</span></label>
                                <input type="email" id="eu_email_{{ $u->id }}" name="email" class="form-control font-monospace" value="{{ $u->email }}" required>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label for="eu_role_{{ $u->id }}" class="form-label">Platform role <span style="color: var(--danger);">*</span></label>
                                <select id="eu_role_{{ $u->id }}" name="global_role" class="form-select" required>
                                    <option value="user" {{ $u->global_role === 'user' ? 'selected' : '' }}>Standard user</option>
                                    <option value="super_admin" {{ $u->global_role === 'super_admin' ? 'selected' : '' }}>Super admin</option>
                                </select>
                                <div class="form-text">Standard users reach only the workspaces set below. Super admins reach all of them.</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="eu_pw_{{ $u->id }}" class="form-label">New password</label>
                                <input type="password" id="eu_pw_{{ $u->id }}" name="password" class="form-control" placeholder="Leave blank to keep the current one">
                                <div class="form-text">Six characters minimum when changing it.</div>
                            </div>
                        </div>

                        <div class="mt-4 pt-3" style="border-top: 1px solid var(--border);">
                            <div class="fw-semibold mb-1" style="font-size: 0.8125rem;">Workspace access</div>
                            <p class="text-muted mb-2" style="font-size: 0.75rem;">Set the role this person holds in each workspace.</p>

                            <div class="ws-list">
                                @foreach($systems as $sys)
                                    @php
                                        $assigned = $u->systems->firstWhere('id', $sys->id);
                                        $currentRole = $assigned ? $assigned->pivot->role : '';
                                    @endphp
                                    <div class="ws-row">
                                        <div class="fw-semibold text-truncate" style="font-size: 0.8125rem;">{{ $sys->name }}</div>
                                        <label for="eu_ws_{{ $u->id }}_{{ $sys->id }}" class="visually-hidden">Role in {{ $sys->name }}</label>
                                        <select id="eu_ws_{{ $u->id }}_{{ $sys->id }}" name="system_roles[{{ $sys->id }}]"
                                                class="form-select form-select-sm">
                                            <option value="none" {{ empty($currentRole) ? 'selected' : '' }}>No access</option>
                                            <option value="viewer" {{ $currentRole === 'viewer' ? 'selected' : '' }}>Viewer</option>
                                            <option value="editor" {{ $currentRole === 'editor' ? 'selected' : '' }}>Editor</option>
                                            <option value="system_admin" {{ $currentRole === 'system_admin' ? 'selected' : '' }}>Admin</option>
                                        </select>
                                    </div>
                                @endforeach
                            </div>
                            <div class="form-text">Viewer reads only. Editor configures bots. Admin also deletes them and edits allowed origins.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach

<div class="modal fade" id="newUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h6 class="modal-title mb-0">New user</h6>
                    <span class="text-muted" style="font-size: 0.75rem;">Create an account and grant initial access.</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('users.store') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="nu_name" class="form-label">Full name <span style="color: var(--danger);">*</span></label>
                            <input type="text" id="nu_name" name="name" class="form-control" placeholder="Nadia Rahman" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="nu_email" class="form-label">Email <span style="color: var(--danger);">*</span></label>
                            <input type="email" id="nu_email" name="email" class="form-control font-monospace" placeholder="nadia@company.com" required>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="nu_role" class="form-label">Platform role <span style="color: var(--danger);">*</span></label>
                            <select id="nu_role" name="global_role" class="form-select" required>
                                <option value="user" selected>Standard user</option>
                                <option value="super_admin">Super admin</option>
                            </select>
                            <div class="form-text">Standard users reach only the workspaces set below. Super admins reach all of them.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="nu_pw" class="form-label">Password <span style="color: var(--danger);">*</span></label>
                            <input type="password" id="nu_pw" name="password" class="form-control" placeholder="Six characters minimum" required>
                        </div>
                    </div>

                    <div class="mt-4 pt-3" style="border-top: 1px solid var(--border);">
                        <div class="fw-semibold mb-1" style="font-size: 0.8125rem;">Workspace access</div>
                        <p class="text-muted mb-2" style="font-size: 0.75rem;">Optional. You can grant this later.</p>

                        <div class="ws-list">
                            @foreach($systems as $sys)
                                <div class="ws-row">
                                    <div class="fw-semibold text-truncate" style="font-size: 0.8125rem;">{{ $sys->name }}</div>
                                    <label for="nu_ws_{{ $sys->id }}" class="visually-hidden">Role in {{ $sys->name }}</label>
                                    <select id="nu_ws_{{ $sys->id }}" name="system_roles[{{ $sys->id }}]"
                                            class="form-select form-select-sm">
                                        <option value="none" selected>No access</option>
                                        <option value="viewer">Viewer</option>
                                        <option value="editor">Editor</option>
                                        <option value="system_admin">Admin</option>
                                    </select>
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text">Viewer reads only. Editor configures bots. Admin also deletes them and edits allowed origins.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand">Create user</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
