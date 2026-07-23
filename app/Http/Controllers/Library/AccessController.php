<?php

namespace App\Http\Controllers\Library;

use App\Enums\LibraryRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Library\UpdateUserRoleRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccessController extends Controller
{
    /**
     * Display members and their roles for access management.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString();

        return Inertia::render('library/AccessIndex', [
            'users' => User::query()
                ->with('roles:id,name')
                ->when($search !== '', static function (Builder $query) use ($search): void {
                    $query->where(static function (Builder $query) use ($search): void {
                        $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->paginate(20)
                ->through(static fn(User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames()->values()->all(),
                ])
                ->withQueryString(),
            'filters' => [
                'search' => $search,
            ],
            'roles' => array_map(
                static fn(LibraryRole $role): string => $role->value,
                LibraryRole::cases(),
            ),
        ]);
    }

    /**
     * Assign exactly one library role to a member.
     */
    public function update(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        $role = $request->validated('role');

        if ($request->user()->is($user) && $role !== LibraryRole::Admin->value) {
            throw ValidationException::withMessages([
                'role' => 'You cannot remove your own administrator access.',
            ]);
        }

        $user->syncRoles($role);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Member role updated.']);

        return to_route('access.index');
    }
}
