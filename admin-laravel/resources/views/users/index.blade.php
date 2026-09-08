@extends('layouts.app')

@section('content')
<div class="d-flex flex-column gap-4">

    <!-- Header Banner -->
    <div class="card border-0 shadow-sm p-4 rounded-4" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #eef2f6 !important;">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <div>
                <h4 class="fw-bold mb-1 text-dark" style="letter-spacing: -0.02em;">Platform Users & RBAC Directory</h4>
                <p class="text-secondary small mb-0">Manage global accounts, super admins, and edit user workspace roles.</p>
            </div>

            <button class="btn btn-sm btn-brand d-inline-flex align-items-center gap-2 shadow-sm px-3.5 py-2 btn-nowrap rounded-3" data-bs-toggle="modal" data-bs-target="#newUserModal">
                <i class="bi bi-plus-lg"></i> New User Account
            </button>
        </div>
    </div>

    <!-- Users Table Card -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 px-4 d-flex align-items-center justify-content-between border-bottom">
            <div>
                <h6 class="mb-0 fw-bold text-dark">All Platform Users</h6>
                <small class="text-muted" style="font-size: 0.75rem;">Total: {{ $users->count() }} user(s) registered</small>
            </div>
            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2.5 py-1 rounded-pill small fw-semibold btn-nowrap">
                Universal RBAC Directory
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                    <tr>
                        <th class="ps-4 py-3" style="min-width: 220px;">User</th>
                        <th class="py-3" style="width: 160px; white-space: nowrap;">Platform Role</th>
                        <th class="py-3" style="min-width: 280px;">Assigned Workspaces & System Roles</th>
                        <th class="py-3" style="width: 140px; white-space: nowrap;">Created Date</th>
                        <th class="text-end pe-4 py-3" style="width: 170px; white-space: nowrap;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $u)
                        <tr>
                            <td class="ps-4 py-3.5">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold shadow-sm flex-shrink-0" style="width: 38px; height: 38px; font-size: 0.95rem; background: linear-gradient(135deg, #e0e7ff, #c7d2fe); color: #4338ca;">
                                        {{ strtoupper(substr($u->name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <div class="fw-semibold text-dark">{{ $u->name }}</div>
                                        <small class="text-muted font-monospace" style="font-size: 0.72rem;">{{ $u->email }}</small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if($u->isSuperAdmin())
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5 rounded-pill small fw-semibold btn-nowrap">
                                        <i class="bi bi-shield-check me-1"></i> Super Admin
                                    </span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2.5 py-1.5 rounded-pill small fw-semibold btn-nowrap">
                                        Standard User
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if($u->isSuperAdmin())
                                    <span class="text-success small fw-medium d-inline-flex align-items-center gap-1 btn-nowrap">
                                        <i class="bi bi-unlock-fill text-success"></i> Universal Access (All {{ $systems->count() }} Workspaces)
                                    </span>
                                @elseif($u->systems->isEmpty())
                                    <span class="text-muted small fst-italic">No workspaces assigned</span>
                                @else
                                    <div class="d-flex flex-wrap gap-1.5" style="max-width: 480px;">
                                        @foreach($u->systems as $sys)
                                            <span class="badge bg-light text-dark border px-2.5 py-1 rounded-pill small d-inline-flex align-items-center gap-1.5 btn-nowrap">
                                                <i class="bi bi-layers text-primary" style="font-size: 0.75rem;"></i>
                                                <span class="fw-medium">{{ $sys->name }}:</span>
                                                @if($sys->pivot->role === 'system_admin')
                                                    <strong class="text-primary text-uppercase" style="font-size: 0.68rem;">Admin</strong>
                                                @elseif($sys->pivot->role === 'editor')
                                                    <strong class="text-warning-emphasis text-uppercase" style="font-size: 0.68rem;">Editor</strong>
                                                @else
                                                    <strong class="text-info-emphasis text-uppercase" style="font-size: 0.68rem;">Viewer</strong>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="text-muted small text-nowrap">
                                {{ $u->created_at ? $u->created_at->format('M d, Y') : '-' }}
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex align-items-center justify-content-end gap-1.5 flex-nowrap">
                                    <!-- Edit User Button -->
                                    <button type="button" class="btn btn-sm btn-outline-primary py-1.5 px-2.5 rounded-3 d-inline-flex align-items-center gap-1 btn-nowrap" data-bs-toggle="modal" data-bs-target="#editUserModal{{ $u->id }}" title="Edit User & RBAC Roles" style="font-size: 0.78rem;">
                                        <i class="bi bi-pencil-square"></i> Edit RBAC
                                    </button>

                                    @if(auth()->id() !== $u->id)
                                        <form action="{{ route('users.destroy', $u->id) }}" method="POST" onsubmit="return confirm('Delete user account [{{ $u->name }}]?');" class="d-inline m-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-1.5 px-2 rounded-3 btn-nowrap" title="Delete User" style="font-size: 0.78rem;">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>

                        <!-- Edit User & RBAC Modal -->
                        <div class="modal fade" id="editUserModal{{ $u->id }}" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                                    <div class="modal-header py-3 px-4 border-bottom">
                                        <div>
                                            <h6 class="modal-title fw-bold text-dark">Edit User & RBAC Permissions: {{ $u->name }}</h6>
                                            <small class="text-muted" style="font-size: 0.75rem;">Modify platform account details and workspace-scoped roles.</small>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <form action="{{ route('users.update', $u->id) }}" method="POST">
                                        @csrf
                                        @method('PUT')
                                        <div class="modal-body p-4">
                                            <div class="row g-3 mb-3">
                                                <div class="col-12 col-md-6">
                                                    <label class="form-label small fw-bold text-dark">Full Name <span class="text-danger">*</span></label>
                                                    <input type="text" name="name" class="form-control" value="{{ $u->name }}" required>
                                                </div>

                                                <div class="col-12 col-md-6">
                                                    <label class="form-label small fw-bold text-dark">Email Address <span class="text-danger">*</span></label>
                                                    <input type="email" name="email" class="form-control font-monospace" value="{{ $u->email }}" required>
                                                </div>
                                            </div>

                                            <div class="row g-3 mb-3">
                                                <div class="col-12 col-md-6">
                                                    <label class="form-label small fw-bold text-dark">Global Platform Role <span class="text-danger">*</span></label>
                                                    <select name="global_role" class="form-select" required>
                                                        <option value="user" {{ $u->global_role === 'user' ? 'selected' : '' }}>Standard User (Workspace Restricted)</option>
                                                        <option value="super_admin" {{ $u->global_role === 'super_admin' ? 'selected' : '' }}>Super Administrator (Universal Access)</option>
                                                    </select>
                                                    <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Super Admins have bypass access to all workspaces and bots.</small>
                                                </div>

                                                <div class="col-12 col-md-6">
                                                    <label class="form-label small fw-bold text-dark">Change Password</label>
                                                    <input type="password" name="password" class="form-control" placeholder="Leave blank to keep unchanged">
                                                    <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Minimum 6 characters if updating password.</small>
                                                </div>
                                            </div>

                                            <hr class="my-4">
                                            <h6 class="fw-bold text-dark small mb-2 d-flex align-items-center gap-2">
                                                <i class="bi bi-shield-lock text-primary"></i> Workspace Permissions Assignment
                                            </h6>
                                            <p class="text-muted small mb-3">Assign individual permissions for each workspace in the organization:</p>

                                            <div class="d-flex flex-column gap-2.5" style="max-height: 260px; overflow-y: auto;">
                                                @foreach($systems as $sys)
                                                    @php
                                                        $assigned = $u->systems->firstWhere('id', $sys->id);
                                                        $currentRole = $assigned ? $assigned->pivot->role : '';
                                                    @endphp
                                                    <div class="p-3 rounded-3 border bg-light d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
                                                        <div class="d-flex align-items-center gap-2">
                                                            <div class="rounded-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: rgba(99, 102, 241, 0.1); color: #4f46e5;">
                                                                <i class="bi bi-layers"></i>
                                                            </div>
                                                            <div>
                                                                <span class="fw-semibold text-dark small d-block">{{ $sys->name }}</span>
                                                                <code class="text-muted font-monospace" style="font-size: 0.68rem;">ID: {{ $sys->id }}</code>
                                                            </div>
                                                        </div>

                                                        <div class="d-flex align-items-center gap-2">
                                                            <select name="system_roles[{{ $sys->id }}]" class="form-select form-select-sm" style="min-width: 170px;">
                                                                <option value="none" {{ empty($currentRole) ? 'selected' : '' }}>-- No Access --</option>
                                                                <option value="viewer" {{ $currentRole === 'viewer' ? 'selected' : '' }}>Viewer (Read-only)</option>
                                                                <option value="editor" {{ $currentRole === 'editor' ? 'selected' : '' }}>Editor (Manage Bots)</option>
                                                                <option value="system_admin" {{ $currentRole === 'system_admin' ? 'selected' : '' }}>System Admin (Full)</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                        <div class="modal-footer py-2.5 px-4 bg-light border-top">
                                            <button type="button" class="btn btn-sm btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-sm btn-brand px-3.5">Save User & Roles</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create User Modal -->
    <div class="modal fade" id="newUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header py-3 px-4 border-bottom">
                    <div>
                        <h6 class="modal-title fw-bold text-dark">Create New User Account</h6>
                        <small class="text-muted" style="font-size: 0.75rem;">Add a new team member and assign initial workspace access.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('users.store') }}" method="POST">
                    @csrf
                    <div class="modal-body p-4">
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold text-dark">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. Jane Doe" required>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold text-dark">Email Address <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control font-monospace" placeholder="jane@company.com" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold text-dark">Platform Role <span class="text-danger">*</span></label>
                                <select name="global_role" class="form-select" required>
                                    <option value="user" selected>Standard User (Workspace Restricted)</option>
                                    <option value="super_admin">Super Administrator (Universal Platform Access)</option>
                                </select>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-bold text-dark">Password <span class="text-danger">*</span></label>
                                <input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" required>
                            </div>
                        </div>

                        <hr class="my-4">
                        <h6 class="fw-bold text-dark small mb-2 d-flex align-items-center gap-2">
                            <i class="bi bi-shield-lock text-primary"></i> Initial Workspace Permissions
                        </h6>
                        <p class="text-muted small mb-3">Optionally assign access to workspaces right away:</p>

                        <div class="d-flex flex-column gap-2.5" style="max-height: 240px; overflow-y: auto;">
                            @foreach($systems as $sys)
                                <div class="p-3 rounded-3 border bg-light d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background: rgba(99, 102, 241, 0.1); color: #4f46e5;">
                                            <i class="bi bi-layers"></i>
                                        </div>
                                        <div>
                                            <span class="fw-semibold text-dark small d-block">{{ $sys->name }}</span>
                                            <code class="text-muted font-monospace" style="font-size: 0.68rem;">ID: {{ $sys->id }}</code>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-center gap-2">
                                        <select name="system_roles[{{ $sys->id }}]" class="form-select form-select-sm" style="min-width: 170px;">
                                            <option value="none" selected>-- No Access --</option>
                                            <option value="viewer">Viewer (Read-only)</option>
                                            <option value="editor">Editor (Manage Bots)</option>
                                            <option value="system_admin">System Admin (Full)</option>
                                        </select>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="modal-footer py-2.5 px-4 bg-light border-top">
                        <button type="button" class="btn btn-sm btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-brand px-3.5">Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
@endsection
