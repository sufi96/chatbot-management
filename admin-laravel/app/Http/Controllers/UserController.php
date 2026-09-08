<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\System;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized.');
        }

        $users = User::with('systems')->latest()->get();
        $systems = System::orderBy('name')->get();

        return view('users.index', [
            'users' => $users,
            'systems' => $systems,
        ]);
    }

    public function store(Request $request)
    {
        if (!$request->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized.');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:6'],
            'global_role' => ['required', 'in:super_admin,user'],
            'system_id' => ['nullable', 'exists:systems,id'],
            'system_role' => ['nullable', 'in:system_admin,editor,viewer'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'global_role' => $request->global_role,
        ]);

        if ($request->system_id && $request->system_role) {
            $user->systems()->attach($request->system_id, ['role' => $request->system_role]);
        }

        return redirect()->route('users.index')->with('success', 'User account created successfully.');
    }

    public function update(Request $request, int $id)
    {
        if (!$request->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized.');
        }

        $user = User::findOrFail($id);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'global_role' => ['required', 'in:super_admin,user'],
            'password' => ['nullable', 'string', 'min:6'],
        ]);

        $userData = [
            'name' => $request->name,
            'email' => $request->email,
            'global_role' => $request->global_role,
        ];

        if ($request->filled('password')) {
            $userData['password'] = Hash::make($request->password);
        }

        $user->update($userData);

        // Update system workspace assignments if provided
        if ($request->has('system_roles') && is_array($request->system_roles)) {
            $syncData = [];
            foreach ($request->system_roles as $sysId => $role) {
                if ($role && in_array($role, ['system_admin', 'editor', 'viewer'])) {
                    $syncData[$sysId] = ['role' => $role];
                }
            }
            $user->systems()->sync($syncData);
        }

        return redirect()->route('users.index')->with('success', "User [{$user->name}] and RBAC settings updated successfully.");
    }

    public function destroy(Request $request, int $id)
    {
        if (!$request->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized.');
        }

        if ($request->user()->id === $id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user = User::findOrFail($id);
        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully.');
    }
}
