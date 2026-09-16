@extends('layouts.app')

@section('page-title', 'Workspaces')

@section('content')

<div class="page-head mb-4">
    <div>
        <h1>Workspaces</h1>
        <p>A workspace isolates its own bot profiles, allowed origins and member roles. Nothing crosses between them.</p>
    </div>

    @if(auth()->user()->isSuperAdmin())
        <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newSystemModal">
            <i class="bi bi-plus-lg"></i> New workspace
        </button>
    @endif
</div>

<div class="card">
    @if($systems->isEmpty())
        <div class="empty">
            <i class="bi bi-diagram-3"></i>
            <h6>No workspaces yet</h6>
            <p>Create one to start grouping bot profiles and granting people access to them.</p>
            @if(auth()->user()->isSuperAdmin())
                <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newSystemModal">New workspace</button>
            @endif
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 260px;">Workspace</th>
                        <th style="width: 110px;">Your role</th>
                        <th style="width: 80px;">Bots</th>
                        <th style="min-width: 180px;">Allowed origins</th>
                        <th class="text-end" style="width: 230px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($systems as $sys)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-1.5 mb-0.5">
                                    <span class="fw-semibold">{{ $sys->name }}</span>
                                    @if(isset($activeSystem) && $activeSystem->id === $sys->id)
                                        <span class="d-inline-flex align-items-center gap-1.5" style="color: var(--ok); font-size: 0.75rem;">
                                            <span class="state-dot is-live"></span> Current
                                        </span>
                                    @endif
                                </div>
                                <div class="text-muted" style="font-size: 0.75rem; max-width: 52ch;">
                                    {{ $sys->description ?: 'No description.' }}
                                </div>
                                <div class="figure-mono text-muted" style="font-size: 0.6875rem;">{{ $sys->id }}</div>
                            </td>
                            <td>
                                @if(isset($sys->pivot->role))
                                    @if($sys->pivot->role === 'system_admin')
                                        <span class="badge badge-role bg-primary-subtle">Admin</span>
                                    @elseif($sys->pivot->role === 'editor')
                                        <span class="badge badge-role bg-warning-subtle">Editor</span>
                                    @else
                                        <span class="badge badge-role bg-info-subtle">Viewer</span>
                                    @endif
                                @elseif(auth()->user()->isSuperAdmin())
                                    <span class="badge badge-role bg-danger-subtle">Super admin</span>
                                @else
                                    <span class="text-muted">None</span>
                                @endif
                            </td>
                            <td><span class="figure-mono">{{ $sys->bot_profiles_count ?? 0 }}</span></td>
                            <td>
                                <span class="figure-mono text-truncate d-block" style="max-width: 220px;" title="{{ $sys->allowed_origins ?: '*' }}">
                                    {{ $sys->allowed_origins ?: '*' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex align-items-center justify-content-end gap-1.5">
                                    @if(!(isset($activeSystem) && $activeSystem->id === $sys->id))
                                        <form action="{{ route('systems.switch') }}" method="POST" class="d-inline m-0">
                                            @csrf
                                            <input type="hidden" name="system_id" value="{{ $sys->id }}">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Switch to</button>
                                        </form>
                                    @endif

                                    @if(auth()->user()->isSuperAdmin())
                                        <a href="{{ route('systems.users', $sys->id) }}" class="btn btn-sm btn-outline-secondary" title="Manage members">
                                            <i class="bi bi-people"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                                data-bs-target="#editModal{{ $sys->id }}" title="Edit workspace">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form action="{{ route('systems.destroy', $sys->id) }}" method="POST"
                                              onsubmit="return confirm('Delete the workspace {{ $sys->name }}? Every bot profile inside it is deleted too.');"
                                              class="d-inline m-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete workspace">
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
    @endif
</div>

{{-- Edit modals --}}
@if(auth()->user()->isSuperAdmin())
    @foreach($systems as $sys)
        <div class="modal fade" id="editModal{{ $sys->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title mb-0">Edit workspace</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="{{ route('systems.update', $sys->id) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="edit_name_{{ $sys->id }}" class="form-label">Name <span style="color: var(--danger);">*</span></label>
                                <input type="text" id="edit_name_{{ $sys->id }}" name="name" class="form-control" value="{{ $sys->name }}" required>
                            </div>
                            <div class="mb-3">
                                <label for="edit_desc_{{ $sys->id }}" class="form-label">Description</label>
                                <textarea id="edit_desc_{{ $sys->id }}" name="description" class="form-control" rows="2">{{ $sys->description }}</textarea>
                            </div>
                            <div class="mb-0">
                                <label for="edit_origins_{{ $sys->id }}" class="form-label">Allowed origins</label>
                                <input type="text" id="edit_origins_{{ $sys->id }}" name="allowed_origins" class="form-control font-monospace"
                                       value="{{ $sys->allowed_origins }}" placeholder="* or https://app.example.com">
                                <div class="form-text">Which sites may load the widget and stream replies. Comma-separate several, or use <code>*</code> for any.</div>
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

    {{-- Create modal --}}
    <div class="modal fade" id="newSystemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">New workspace</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('systems.store') }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="new_name" class="form-label">Name <span style="color: var(--danger);">*</span></label>
                            <input type="text" id="new_name" name="name" class="form-control"
                                   placeholder="Storefront, Helpdesk, Internal tools" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_desc" class="form-label">Description</label>
                            <textarea id="new_desc" name="description" class="form-control" rows="2"
                                      placeholder="What this workspace covers."></textarea>
                        </div>
                        <div class="mb-0">
                            <label for="new_origins" class="form-label">Allowed origins</label>
                            <input type="text" id="new_origins" name="allowed_origins" class="form-control font-monospace"
                                   value="*" placeholder="* or https://client.example.com">
                            <div class="form-text">Which sites may load the widget and stream replies.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand">Create workspace</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

@endsection
