@extends('layouts.app')

@section('content')
<div class="d-flex flex-column gap-4">

    <!-- Header Banner -->
    <div class="card border-0 shadow-sm p-4 rounded-4" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #eef2f6 !important;">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <div>
                <h4 class="fw-bold mb-1 text-dark" style="letter-spacing: -0.02em;">Systems & Workspaces</h4>
                <p class="text-secondary small mb-0">Multi-tenant architecture: Each system isolates bot profiles, CORS origins, and RBAC user permissions.</p>
            </div>

            @if(auth()->user()->isSuperAdmin())
                <button class="btn btn-sm btn-brand d-flex align-items-center gap-1.5 shadow-sm px-3.5 py-2" data-bs-toggle="modal" data-bs-target="#newSystemModal">
                    <i class="bi bi-plus-lg"></i> New System Workspace
                </button>
            @endif
        </div>
    </div>

    <!-- Systems Grid -->
    <div class="row g-4">
        @forelse($systems as $sys)
            <div class="col-12 col-lg-6 col-xxl-4">
                <div class="card card-interactive h-100 border-0 rounded-4 d-flex flex-column justify-content-between overflow-hidden">
                    <div class="p-4">
                        <div class="d-flex align-items-start justify-content-between mb-3">
                            <div class="rounded-3 d-flex align-items-center justify-content-center shadow-sm" style="width: 46px; height: 46px; background: rgba(99, 102, 241, 0.1); color: #4f46e5;">
                                <i class="bi bi-layers fs-4"></i>
                            </div>
                            <div class="d-flex align-items-center gap-1.5 flex-wrap justify-content-end">
                                <span class="badge bg-light text-muted border px-2 py-1 rounded-pill small font-monospace" style="font-size: 0.68rem;">
                                    #{{ $sys->id }}
                                </span>
                                <span class="badge bg-light text-secondary border px-2.5 py-1 rounded-pill small fw-semibold" style="font-size: 0.72rem;">
                                    {{ $sys->bot_profiles_count ?? 0 }} Bot(s)
                                </span>
                                @if(isset($sys->pivot->role))
                                    @if($sys->pivot->role === 'system_admin')
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill small fw-semibold text-uppercase" style="font-size: 0.68rem;">Admin</span>
                                    @elseif($sys->pivot->role === 'editor')
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1 rounded-pill small fw-semibold text-uppercase" style="font-size: 0.68rem;">Editor</span>
                                    @else
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1 rounded-pill small fw-semibold text-uppercase" style="font-size: 0.68rem;">Viewer</span>
                                    @endif
                                @elseif(auth()->user()->isSuperAdmin())
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 rounded-pill small fw-semibold text-uppercase" style="font-size: 0.68rem;">Super Admin</span>
                                @endif
                            </div>
                        </div>

                        <h5 class="fw-bold text-dark mb-1" style="letter-spacing: -0.01em;">{{ $sys->name }}</h5>
                        <p class="text-secondary small mb-3 text-truncate-2" style="min-height: 40px; line-height: 1.45;">
                            {{ $sys->description ?: 'System workspace isolating dedicated chatbots, embeddings, and authorized team members.' }}
                        </p>

                        <!-- Allowed Origins (CORS) -->
                        <div class="p-2.5 rounded-3 bg-light border">
                            <small class="text-secondary text-uppercase fw-semibold d-block mb-1" style="font-size: 0.68rem; letter-spacing: 0.05em;">CORS Whitelist Domains:</small>
                            <code class="small text-truncate d-block font-monospace text-dark" style="font-size: 0.75rem;" title="{{ $sys->allowed_origins }}">
                                {{ $sys->allowed_origins ?: '*' }}
                            </code>
                        </div>
                    </div>

                    <!-- Card Footer Actions: Responsive & Clean Placement -->
                    <div class="p-3 px-4 border-top bg-light bg-opacity-50 d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div>
                            @if(isset($activeSystem) && $activeSystem->id === $sys->id)
                                <span class="badge bg-success-subtle text-success border border-success-subtle py-1.5 px-3 rounded-pill small fw-semibold d-inline-flex align-items-center gap-1.5 btn-nowrap">
                                    <span class="status-pulse-dot" style="width: 6px; height: 6px;"></span> Active Workspace
                                </span>
                            @else
                                <form action="{{ route('systems.switch') }}" method="POST" class="d-inline m-0">
                                    @csrf
                                    <input type="hidden" name="system_id" value="{{ $sys->id }}">
                                    <button type="submit" class="btn btn-sm btn-outline-primary py-1.5 px-3 rounded-3 btn-nowrap d-inline-flex align-items-center gap-1" style="font-size: 0.78rem;">
                                        <i class="bi bi-arrow-repeat"></i> Select Workspace
                                    </button>
                                </form>
                            @endif
                        </div>

                        <div class="d-flex align-items-center gap-1.5 flex-nowrap">
                            @if(auth()->user()->isSuperAdmin())
                                <a href="{{ route('systems.users', $sys->id) }}" class="btn btn-sm btn-outline-secondary py-1.5 px-2.5 rounded-3 d-inline-flex align-items-center gap-1 btn-nowrap" title="Manage Workspace User Roles" style="font-size: 0.78rem;">
                                    <i class="bi bi-people"></i> Users
                                </a>
                                <button class="btn btn-sm btn-outline-secondary py-1.5 px-2 rounded-3 btn-nowrap" data-bs-toggle="modal" data-bs-target="#editModal{{ $sys->id }}" title="Edit Workspace Settings" style="font-size: 0.78rem;">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form action="{{ route('systems.destroy', $sys->id) }}" method="POST" onsubmit="return confirm('Delete system [{{ $sys->name }}] and all associated bot profiles?');" class="d-inline m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-1.5 px-2 rounded-3 btn-nowrap" title="Delete System" style="font-size: 0.78rem;">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>

                @if(auth()->user()->isSuperAdmin())
                    <!-- Edit Modal -->
                    <div class="modal fade" id="editModal{{ $sys->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                                <div class="modal-header py-3 px-4 border-bottom">
                                    <h6 class="modal-title fw-bold text-dark">Edit System Workspace</h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <form action="{{ route('systems.update', $sys->id) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    <div class="modal-body p-4">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-dark">System Name <span class="text-danger">*</span></label>
                                            <input type="text" name="name" class="form-control" value="{{ $sys->name }}" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-dark">Description</label>
                                            <textarea name="description" class="form-control" rows="2">{{ $sys->description }}</textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-dark">Allowed Origins (CORS Whitelist)</label>
                                            <input type="text" name="allowed_origins" class="form-control font-monospace" value="{{ $sys->allowed_origins }}" placeholder="* or https://app.example.com">
                                            <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Separate multiple domains with commas, or use <code>*</code> for all.</small>
                                        </div>
                                    </div>
                                    <div class="modal-footer py-2.5 px-4 bg-light border-top">
                                        <button type="button" class="btn btn-sm btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-sm btn-brand px-3.5">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="col-12">
                <div class="card p-5 text-center border-0 shadow-sm rounded-4 text-muted">
                    <p class="mb-0">No systems found. Please create a system workspace.</p>
                </div>
            </div>
        @endforelse
    </div>

    <!-- Create System Modal (Super Admin) -->
    @if(auth()->user()->isSuperAdmin())
        <div class="modal fade" id="newSystemModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                    <div class="modal-header py-3 px-4 border-bottom">
                        <h6 class="modal-title fw-bold text-dark">Create New System Workspace</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="{{ route('systems.store') }}" method="POST">
                        @csrf
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-dark">System Workspace Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. Production E-Commerce, Helpdesk Portal" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-dark">Description</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Brief summary of what this system represents..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-dark">Allowed Origins (CORS Whitelist)</label>
                                <input type="text" name="allowed_origins" class="form-control font-monospace" value="*" placeholder="* or https://client.example.com">
                                <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Defines which external client domains are authorized to fetch widget configuration & stream chat responses.</small>
                            </div>
                        </div>
                        <div class="modal-footer py-2.5 px-4 bg-light border-top">
                            <button type="button" class="btn btn-sm btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-sm btn-brand px-3.5">Create Workspace</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

</div>
@endsection
