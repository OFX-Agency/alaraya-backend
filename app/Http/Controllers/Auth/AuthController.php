<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AuthController extends Controller
{
    /** User and permission management is restricted to system admins. */
    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
    }

    private function userRules(?User $user = null): array
    {
        $required = $user ? 'sometimes' : 'required';

        return [
            'full_name'     => "{$required}|string|max:255",
            'user_name'     => ["{$required}", 'string', 'max:255', Rule::unique('users', 'user_name')->ignore($user?->id)],
            'email'         => ["{$required}", 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password'      => "{$required}|string|min:8",
            'phone_number'  => "{$required}|string|max:20",
            'role'          => ['sometimes', 'nullable', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            // Direct (per-user) permissions, granted in addition to the role's permissions.
            'permissions'   => 'sometimes|array',
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
            'treasury_id'   => 'nullable|array',
            'treasury_id.*' => 'exists:treasuries,id',
        ];
    }

    private function logPermissionChange(Request $request, User $user, array $before): void
    {
        $after = $user->permissions()->pluck('name')->sort()->values()->all();
        if ($before === $after) {
            return;
        }
        activity('permissions')
            ->causedBy($request->user())
            ->performedOn($user)
            ->event('updated')
            ->withProperties([
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ])
            ->log("تعديل صلاحيات المستخدم {$user->user_name}");
    }

    private function presentUser(User $user): User
    {
        return $user->load('roles:id,name', 'permissions:id,name', 'treasuries');
    }

    public function getUsers(Request $request)
    {
        $this->ensureAdmin($request);
        try {
            $users = User::with('roles:id,name', 'permissions:id,name', 'treasuries')->get();
            return response()->json(['users' => $users], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve users', 'message' => $e->getMessage()], 500);
        }
    }

    public function createUser(Request $request)
    {
        $this->ensureAdmin($request);
        try {
            $validatedData = $request->validate($this->userRules());
            $validatedData['password'] = bcrypt($validatedData['password']);

            $user = DB::transaction(function () use ($request, $validatedData) {
                $user = User::create($validatedData);
                if (! empty($validatedData['role'])) {
                    $user->assignRole($validatedData['role']);
                }
                if (isset($validatedData['permissions'])) {
                    $user->syncPermissions($validatedData['permissions']);
                    $this->logPermissionChange($request, $user, []);
                }
                if (isset($validatedData['treasury_id'])) {
                    $user->treasuries()->sync($validatedData['treasury_id']);
                }

                return $user;
            });

            return response()->json(['message' => 'User created successfully', 'user' => $this->presentUser($user)], 201);
        } catch (ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create user', 'message' => $e->getMessage()], 500);
        }
    }

    public function login(Request $request)
    {
        try {
            $credentials = $request->only('email', 'password');
            if (!auth()->attempt($credentials)) {
                return response()->json(['error' => 'Invalid credentials'], 401);
            }
            $user = auth()->user();
            $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json(['message' => 'Login successful', 'access_token' => $token, 'token_type' => 'Bearer'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Login failed', 'message' => $e->getMessage()], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();
            return response()->json(['message' => 'Logout successful'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Logout failed', 'message' => $e->getMessage()], 500);
        }
    }

    public function editUser(Request $request, $id)
    {
        $this->ensureAdmin($request);
        try {
            $user = User::findOrFail($id);
            $validatedData = $request->validate($this->userRules($user));

            // Prevent an admin from locking themselves out of user management.
            if ($user->is($request->user()) && array_key_exists('role', $validatedData) && $validatedData['role'] !== 'admin') {
                throw ValidationException::withMessages(['role' => 'لا يمكنك إزالة دور مدير النظام من حسابك.']);
            }

            if (isset($validatedData['password'])) {
                $validatedData['password'] = bcrypt($validatedData['password']);
            } else {
                unset($validatedData['password']);
            }

            DB::transaction(function () use ($request, $user, $validatedData) {
                $user->update($validatedData);
                if (array_key_exists('role', $validatedData)) {
                    $user->syncRoles($validatedData['role'] ? [$validatedData['role']] : []);
                }
                if (isset($validatedData['permissions'])) {
                    $before = $user->permissions()->pluck('name')->sort()->values()->all();
                    $user->syncPermissions($validatedData['permissions']);
                    $this->logPermissionChange($request, $user, $before);
                }
                if (isset($validatedData['treasury_id'])) {
                    $user->treasuries()->sync($validatedData['treasury_id']);
                }
            });

            return response()->json(['message' => 'User updated successfully', 'user' => $this->presentUser($user->fresh())], 200);
        } catch (ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update user', 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteUser(Request $request, $id)
    {
        $this->ensureAdmin($request);
        try {
            $user = User::findOrFail($id);
            if ($user->is($request->user())) {
                throw ValidationException::withMessages(['user' => 'لا يمكنك حذف حسابك.']);
            }
            $user->delete();
            return response()->json(['message' => 'User deleted successfully'], 200);
        } catch (ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete user', 'message' => $e->getMessage()], 500);
        }
    }
}
