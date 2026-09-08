<?php

namespace App\Http\Controllers;

use App\Models\System;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SystemController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $systems = System::withCount(['botProfiles', 'users'])->latest()->get();
        } else {
            $systems = $user->systems()->withCount('botProfiles')->latest()->get();
        }

        return view('systems.index', [
            'systems' => $systems,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'allowed_origins' => ['nullable', 'string'],
        ]);

        $id = 'sys_' . Str::random(8);

        $system = System::create([
            'id' => $id,
            'name' => $request->name,
            'description' => $request->description,
            'allowed_origins' => $request->allowed_origins ?: '*',
        ]);

        // Auto-assign the creator if not already super admin
        if (!$request->user()->isSuperAdmin()) {
            $system->users()->attach($request->user()->id, ['role' => 'system_admin']);
        }

        session(['active_system_id' => $system->id]);

        return redirect()->route('systems.index')->with('success', 'System workspace created successfully.');
    }

    public function update(Request $request, string $id)
    {
        $system = System::findOrFail($id);
        $user = $request->user();

        if (!$user->canManageSystem($id, 'system_admin')) {
            abort(403, 'Unauthorized. System Admin role required.');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'allowed_origins' => ['nullable', 'string'],
        ]);

        $system->update([
            'name' => $request->name,
            'description' => $request->description,
            'allowed_origins' => $request->allowed_origins ?: '*',
        ]);

        return redirect()->route('systems.index')->with('success', 'System workspace updated successfully.');
    }

    public function destroy(Request $request, string $id)
    {
        if (!$request->user()->isSuperAdmin()) {
            abort(403, 'Only Super Administrators can delete systems.');
        }

        $system = System::findOrFail($id);
        $system->delete();

        if (session('active_system_id') === $id) {
            session()->forget('active_system_id');
        }

        return redirect()->route('systems.index')->with('success', 'System deleted successfully.');
    }

    public function switch(Request $request)
    {
        $systemId = $request->input('system_id');
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $system = System::findOrFail($systemId);
        } else {
            $system = $user->systems()->findOrFail($systemId);
        }

        session(['active_system_id' => $system->id]);

        return back()->with('success', "Switched workspace to {$system->name}.");
    }

    public function users(Request $request, string $id)
    {
        if (!$request->user()->isSuperAdmin()) {
            abort(403, 'Only Super Administrators can manage user assignments.');
        }

        $system = System::with('users')->findOrFail($id);
        $allUsers = User::orderBy('name')->get();

        return view('systems.users', [
            'system' => $system,
            'allUsers' => $allUsers,
        ]);
    }

    public function assignUser(Request $request, string $id)
    {
        if (!$request->user()->isSuperAdmin()) {
            abort(403, 'Only Super Administrators can assign users.');
        }

        $system = System::findOrFail($id);

        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['required', 'in:system_admin,editor,viewer'],
        ]);

        $system->users()->syncWithoutDetaching([
            $request->user_id => ['role' => $request->role],
        ]);

        return back()->with('success', 'User assigned to system successfully.');
    }

    public function updateUserRole(Request $request, string $id, int $userId)
    {
        if (!$request->user()->isSuperAdmin()) {
            abort(403, 'Only Super Administrators can edit user roles.');
        }

        $system = System::findOrFail($id);

        $request->validate([
            'role' => ['required', 'in:system_admin,editor,viewer'],
        ]);

        $system->users()->updateExistingPivot($userId, [
            'role' => $request->role,
        ]);

        return back()->with('success', 'User role updated successfully.');
    }

    public function removeUser(Request $request, string $id, int $userId)
    {
        if (!$request->user()->isSuperAdmin()) {
            abort(403, 'Only Super Administrators can remove users from systems.');
        }

        $system = System::findOrFail($id);
        $system->users()->detach($userId);

        return back()->with('success', 'User removed from system.');
    }
}
