<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use App\Models\System;
use Symfony\Component\HttpFoundation\Response;

class EnsureSystemAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $minRole = 'viewer'): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        // Get systems accessible by this user
        if ($user->isSuperAdmin()) {
            $userSystems = System::orderBy('name')->get();
        } else {
            $userSystems = $user->systems()->orderBy('name')->get();
        }

        // Determine active system
        $activeSystemId = session('active_system_id');
        $activeSystem = null;

        if ($activeSystemId) {
            $activeSystem = $userSystems->firstWhere('id', $activeSystemId);
        }

        // If no active system set or previous active system is not accessible, default to first accessible
        if (!$activeSystem && $userSystems->isNotEmpty()) {
            $activeSystem = $userSystems->first();
            session(['active_system_id' => $activeSystem->id]);
        }

        // Check permission if a minRole is required
        if ($activeSystem && $minRole) {
            if (!$user->canManageSystem($activeSystem->id, $minRole)) {
                abort(403, "You do not have the required [{$minRole}] role for system [{$activeSystem->name}].");
            }
        }

        // Share globally with all Blade views
        $currentRole = $activeSystem ? $user->roleInSystem($activeSystem->id) : null;
        View::share('activeSystem', $activeSystem);
        View::share('userSystems', $userSystems);
        View::share('currentSystemRole', $currentRole);

        return $next($request);
    }
}
