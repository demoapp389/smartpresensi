<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    use LogsAuditTrail;

    /**
     * Tampilkan formulir edit profil pengguna yang sedang login.
     */
    public function edit(): View
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user && ! $user->relationLoaded('schoolClass')) {
            $user->load('schoolClass');
        }

        return view('profile.edit', compact('user'));
    }

    /**
     * Perbarui data profil dan kata sandi pengguna.
     */
    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'email.required' => 'Alamat email wajib diisi.',
            'email.email' => 'Format alamat email tidak valid.',
            'email.unique' => 'Alamat email ini sudah terdaftar pada pengguna lain.',
            'current_password.required_with' => 'Kata sandi saat ini wajib diisi jika Anda ingin mengubah kata sandi.',
            'current_password.current_password' => 'Kata sandi saat ini tidak cocok dengan akun Anda.',
            'password.min' => 'Kata sandi baru minimal harus 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok.',
        ]);

        $before = [
            'name' => $user->name,
            'email' => $user->email,
        ];

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        $passwordChanged = false;
        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
            $passwordChanged = true;
        }

        $user->save();

        $this->logAudit('update_profile', 'User', $user->id, $before, [
            'name' => $user->name,
            'email' => $user->email,
            'password_changed' => $passwordChanged,
        ], $user->id);

        return redirect()->route('profile.edit')
            ->with('success', 'Profil dan informasi akun Anda berhasil diperbarui.');
    }
}
