@extends('layouts.app')

@section('content')
<div class="d-flex flex-column gap-4">

    <!-- Breadcrumb & Header Banner -->
    <div class="card border-0 shadow-sm p-4 rounded-4" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #eef2f6 !important;">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <div>
                <a href="{{ route('systems.index') }}" class="text-decoration-none small text-primary d-inline-flex align-items-center gap-1.5 mb-1.5 fw-semibold">
                    <i class="bi bi-arrow-left"></i> Back to Systems
                </a>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.02em;">User Access & RBAC: {{ $system->name }}</h4>
                    <span class="badge bg-light text-secondary border font-monospace small btn-nowrap">ID: {{ $system->id }}</span>
                </div>
                <p class="text-secondary small mb-0 mt-1">Configure role permissions (Admin, Editor, Viewer) for this isolated workspace.</p>
            </div>
            <button class="btn btn-sm btn-brand d-inline-flex align-items-center gap-2 shadow-sm px-3.5 py-2 rounded-3 btn-nowrap" data-bs-toggle="modal" data-bs-target="#assignUserModal">
                <i class="bi bi-plus-lg"></i> Assign User to System
            </button>
        </div>
    </div>

    <!-- Assigned Users Table Card -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 px-4 d-flex align-items-center justify-content-between border-bottom">
            <div>
                <h6 class="mb-0 fw-bold text-dark">Current Workspace Members</h6>
                <small class="text-muted" style="font-size: 0.75rem;">Total: {{ $system->users->count() }} assigned member(s)</small>
            </div>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 rounded-pill small fw-semibold btn-nowrap">
                Workspace Scoped RBAC
            </span>
        </div>

        @if($system->users->isEmpty())
            <div class="card-body p-5 text-center text-muted">
                <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
                    <i class="bi bi-people text-secondary fs-3"></i>
                </div>
                <h6 class="fw-bold text-dark">No users assigned to this workspace yet.</h6>
                <p class="small text-muted mb-3">Super Admins have universal platform access. Assign members with specific roles below.</p>
                <button class="btn btn-sm btn-brand px-3.5 py-2 rounded-3" data-bs-toggle="modal" data-bs-target="#assignUserModal">
                    <i class="bi bi-plus-lg me-1"></i> Assign First User
                </button>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                        <tr>
                            <th class="ps-4 py-3" style="min-width: 220px;">Member</th>
                            <th class="py-3" style="width: 160px; white-space: nowrap;">Platform Role</th>
                            <th class="py-3" style="width: 180px; white-space: nowrap;">Workspace Role</th>
                            <th class="py-3" style="width: 140px; white-space: nowrap;">Assigned Date</th>
                            <th class="text-end pe-4 py-3" style="width: 170px; white-space: nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($system->users as $member)
                            <tr>
                                <td class="ps-4 py-3.5">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold shadow-sm flex-shrink-0" style="width: 38px; height: 38px; font-size: 0.9rem; background: linear-gradient(135deg, #e0e7ff, #c7d2fe); color: #4338ca;">
                                            {{ strtoupper(substr($member->name, 0, 1)) }}
                                        </div>
                                        <div>
                                            <div class="fw-semibold text-dark">{{ $member->name }}</div>
                                            <small class="text-muted font-monospace" style="font-size: 0.72rem;">{{ $member->email }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    @if($member->isSuperAdmin())
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 rounded-pill small fw-semibold btn-nowrap">
                                            <i class="bi bi-shield-check me-0.5"></i> Super Admin
                                        </span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1 rounded-pill small fw-semibold btn-nowrap">
                                            User
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if($member->pivot->role === 'system_admin')
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1.5 rounded-pill small fw-semibold btn-nowrap">
                                            <i class="bi bi-shield-lock me-1"></i> System Admin
                                        </span>
                                    @elseif($member->pivot->role === 'editor')
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-1.5 rounded-pill small fw-semibold btn-nowrap">
                                            <i class="bi bi-pencil-square me-1"></i> Editor
                                        </span>
                                    @else
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2.5 py-1.5 rounded-pill small fw-semibold btn-nowrap">
                                            <i class="bi bi-eye me-1"></i> Viewer
                                        </span>
                                    @endif
                                </td>
                                <td class="text-muted small text-nowrap">
                                    {{ $member->pivot->created_at ? $member->pivot->created_at->format('M d, Y') : '-' }}
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex align-items-center justify-content-end gap-1.5 flex-nowrap">
                                        <!-- Edit Role Button -->
                                        <button type="button" class="btn btn-sm btn-outline-primary py-1.5 px-2.5 rounded-3 d-inline-flex align-items-center gap-1 btn-nowrap" data-bs-toggle="modal" data-bs-target="#editRoleModal{{ $member->id }}" title="Edit Workspace Role" style="font-size: 0.78rem;">
                                            <i class="bi bi-pencil-square"></i> Edit Role
                                        </button>

                                        <!-- Remove Button -->
                                        <form action="{{ route('systems.users.remove', [$system->id, $member->id]) }}" method="POST" onsubmit="return confirm('Remove {{ $member->name }} from this workspace?');" class="d-inline m-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-1.5 px-2 rounded-3 btn-nowrap" title="Remove from workspace" style="font-size: 0.78rem;">
                                                <i class="bi bi-person-x"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>

                            <!-- Edit Member Role Modal -->
                            <div class="modal fade" id="editRoleModal{{ $member->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                                        <div class="modal-header py-3 px-4 border-bottom">
                                            <div>
                                                <h6 class="modal-title fw-bold text-dark">Edit Workspace Role</h6>
                                                <small class="text-muted">{{ $member->name }} &bull; {{ $system->name }}</small>
                                            </div>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <form action="{{ route('systems.users.update', [$system->id, $member->id]) }}" method="POST">
                                            @csrf
                                            @method('PUT')
                                            <div class="modal-body p-4">
                                                <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 mb-3 border">
                                                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 40px; height: 40px; background-color: #4f46e5; color: #ffffff;">
                                                        {{ strtoupper(substr($member->name, 0, 1)) }}
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold text-dark">{{ $member->name }}</div>
                                                        <small class="text-muted">{{ $member->email }}</small>
                                                    </div>
                                                </div>

                                                <label class="form-label small fw-bold text-dark mb-2">Select Permission Level</label>
                                                <div class="d-flex flex-column gap-2">
                                                    <label class="d-flex align-items-start gap-2.5 p-3 rounded-3 border cursor-pointer {{ $member->pivot->role === 'system_admin' ? 'border-primary bg-primary-subtle bg-opacity-25' : 'bg-white' }}">
                                                        <input type="radio" name="role" value="system_admin" class="form-check-input mt-1" {{ $member->pivot->role === 'system_admin' ? 'checked' : '' }}>
                                                        <div>
                                                            <div class="fw-semibold text-dark small"><i class="bi bi-shield-lock text-primary me-1"></i> System Admin</div>
                                                            <small class="text-muted d-block" style="font-size: 0.76rem;">Full control to create, modify, configure, and delete all chatbots within this workspace.</small>
                                                        </div>
                                                    </label>

                                                    <label class="d-flex align-items-start gap-2.5 p-3 rounded-3 border cursor-pointer {{ $member->pivot->role === 'editor' ? 'border-primary bg-primary-subtle bg-opacity-25' : 'bg-white' }}">
                                                        <input type="radio" name="role" value="editor" class="form-check-input mt-1" {{ $member->pivot->role === 'editor' ? 'checked' : '' }}>
                                                        <div>
                                                            <div class="fw-semibold text-dark small"><i class="bi bi-pencil-square text-warning me-1"></i> Editor</div>
                                                            <small class="text-muted d-block" style="font-size: 0.76rem;">Can create, edit, test models, and grab embed codes. Cannot delete the system workspace.</small>
                                                        </div>
                                                    </label>

                                                    <label class="d-flex align-items-start gap-2.5 p-3 rounded-3 border cursor-pointer {{ $member->pivot->role === 'viewer' ? 'border-primary bg-primary-subtle bg-opacity-25' : 'bg-white' }}">
                                                        <input type="radio" name="role" value="viewer" class="form-check-input mt-1" {{ $member->pivot->role === 'viewer' ? 'checked' : '' }}>
                                                        <div>
                                                            <div class="fw-semibold text-dark small"><i class="bi bi-eye text-info me-1"></i> Viewer</div>
                                                            <small class="text-muted d-block" style="font-size: 0.76rem;">Read-only access. Can view bot profiles, copy embed scripts, and inspect analytics.</small>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="modal-footer py-2.5 px-4 bg-light border-top">
                                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-sm btn-brand px-3.5">Save Role Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Role Permission Reference Guide -->
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm rounded-4 p-3.5 h-100 bg-white">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle p-1.5 rounded-3">
                        <i class="bi bi-shield-lock fs-5"></i>
                    </span>
                    <h6 class="fw-bold mb-0 text-dark small">System Admin</h6>
                </div>
                <p class="text-secondary small mb-0" style="font-size: 0.78rem; line-height: 1.5;">
                    Has complete authority within this specific workspace. Can create, edit, reconfigure, test inference, manage CORS domains, and remove chatbots.
                </p>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm rounded-4 p-3.5 h-100 bg-white">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle p-1.5 rounded-3">
                        <i class="bi bi-pencil-square fs-5"></i>
                    </span>
                    <h6 class="fw-bold mb-0 text-dark small">Editor</h6>
                </div>
                <p class="text-secondary small mb-0" style="font-size: 0.78rem; line-height: 1.5;">
                    Ideal for developers and designers. Can create bot profiles, upload custom icons and avatars, discover models via Ollama, and test inference.
                </p>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm rounded-4 p-3.5 h-100 bg-white">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle p-1.5 rounded-3">
                        <i class="bi bi-eye fs-5"></i>
                    </span>
                    <h6 class="fw-bold mb-0 text-dark small">Viewer</h6>
                </div>
                <p class="text-secondary small mb-0" style="font-size: 0.78rem; line-height: 1.5;">
                    Read-only workspace member. Can browse configured bot profiles, view and copy the 1-line HTML embed snippet, and review conversation logs.
                </p>
            </div>
        </div>
    </div>

    <!-- Assign User Modal -->
    <div class="modal fade" id="assignUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header py-3 px-4 border-bottom">
                    <h6 class="modal-title fw-bold text-dark">Assign User to {{ $system->name }}</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('systems.users.assign', $system->id) }}" method="POST">
                    @csrf
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-dark">Select Registered User <span class="text-danger">*</span></label>
                            <select name="user_id" class="form-select" required>
                                <option value="">-- Choose a user --</option>
                                @foreach($allUsers as $u)
                                    @if(!$system->users->contains('id', $u->id))
                                        <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }}) [{{ $u->role }}]</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>

                        <label class="form-label small fw-bold text-dark mb-2">Assign Workspace Role <span class="text-danger">*</span></label>
                        <div class="d-flex flex-column gap-2">
                            <label class="d-flex align-items-start gap-2.5 p-3 rounded-3 border bg-white cursor-pointer hover-bg-light">
                                <input type="radio" name="role" value="system_admin" class="form-check-input mt-1">
                                <div>
                                    <div class="fw-semibold text-dark small"><i class="bi bi-shield-lock text-primary me-1"></i> System Admin</div>
                                    <small class="text-muted d-block" style="font-size: 0.76rem;">Full control to create, modify, configure, and delete bots in this system.</small>
                                </div>
                            </label>

                            <label class="d-flex align-items-start gap-2.5 p-3 rounded-3 border bg-white cursor-pointer hover-bg-light">
                                <input type="radio" name="role" value="editor" class="form-check-input mt-1" checked>
                                <div>
                                    <div class="fw-semibold text-dark small"><i class="bi bi-pencil-square text-warning me-1"></i> Editor</div>
                                    <small class="text-muted d-block" style="font-size: 0.76rem;">Can create, edit, test models, and grab embed codes.</small>
                                </div>
                            </label>

                            <label class="d-flex align-items-start gap-2.5 p-3 rounded-3 border bg-white cursor-pointer hover-bg-light">
                                <input type="radio" name="role" value="viewer" class="form-check-input mt-1">
                                <div>
                                    <div class="fw-semibold text-dark small"><i class="bi bi-eye text-info me-1"></i> Viewer</div>
                                    <small class="text-muted d-block" style="font-size: 0.76rem;">Read-only access to bot list and embed code snippets.</small>
                                </div>
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer py-2.5 px-4 bg-light border-top">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-brand px-3.5">Assign User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
@endsection
