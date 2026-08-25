<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        return view('admin.users.index', [
            'users' => User::query()
                ->when($request->filled('q'), fn ($q) => $q
                    ->where(fn ($w) => $w->where('name', 'like', '%'.$request->string('q').'%')
                        ->orWhere('email', 'like', '%'.$request->string('q').'%')))
                ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
                ->withCount('articles')
                ->latest()
                ->paginate(30)
                ->withQueryString(),
            'roles' => UserRole::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'regex:/^01[3-9]\d{8}$/', 'unique:users,phone'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'designation' => ['nullable', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $validated['status'] = 'active';

        // Staff created by an admin are pre-verified — the admin vouched for
        // the address by typing it. email_verified_at is intentionally not
        // fillable, so it has to go through forceFill.
        User::create($validated)
            ->forceFill(['email_verified_at' => now()])
            ->save();

        return back()->with('status', 'ব্যবহারকারী যুক্ত হয়েছে।');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'regex:/^01[3-9]\d{8}$/', Rule::unique('users')->ignore($user->id)],
            'designation' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:active,suspended'],
        ]);

        // Role changes are separately authorised so nobody can promote
        // themselves by posting an extra field.
        if ($request->filled('role') && Gate::allows('changeRole', $user)) {
            $validated['role'] = $request->string('role')->toString();
        }

        $user->update($validated);

        return back()->with('status', 'ব্যবহারকারী হালনাগাদ হয়েছে।');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update(['password' => $validated['password']]);

        return back()->with('status', 'পাসওয়ার্ড পরিবর্তন হয়েছে।');
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        if ($user->articles()->exists()) {
            return back()->withErrors([
                'user' => 'এই ব্যবহারকারীর নামে খবর আছে। মুছে ফেলার বদলে স্থগিত করুন, নয়তো বাইলাইন হারিয়ে যাবে।',
            ]);
        }

        $user->delete();

        return back()->with('status', 'ব্যবহারকারী মুছে ফেলা হয়েছে।');
    }
}
