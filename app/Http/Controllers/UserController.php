<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    use LogsAuditTrail;

    /**
     * Tampilkan daftar seluruh pengguna sistem (Admin, Operator, Wali Kelas).
     */
    public function index(Request $request): View
    {
        $search = $request->input('search');
        $role = $request->input('role');
        $status = $request->input('status');

        $query = User::with('schoolClass')
            ->orderByRaw("CASE role WHEN 'admin' THEN 1 WHEN 'operator' THEN 2 WHEN 'wali_kelas' THEN 3 ELSE 4 END")
            ->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role) {
            $query->where('role', $role);
        }

        if ($status !== null && $status !== '') {
            $query->where('is_active', (bool) $status);
        }

        $users = $query->get();
        $classes = SchoolClass::orderBy('name')->get();

        return view('users.index', compact('users', 'classes', 'search', 'role', 'status'));
    }

    /**
     * Simpan pengguna baru ke database.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->userRules(), $this->userValidationMessages());

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'class_id' => $validated['role'] === 'wali_kelas' ? $validated['class_id'] : null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->logAudit('create_user', 'User', $user->id, null, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'class_id' => $user->class_id,
            'is_active' => $user->is_active,
        ]);

        return redirect()->route('users.index')
            ->with('success', "Pengguna {$user->name} berhasil ditambahkan.");
    }

    /**
     * Perbarui data pengguna yang ada.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate($this->userRules($user), $this->userValidationMessages());

        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'class_id' => $user->class_id,
            'is_active' => $user->is_active,
        ];

        $newRole = $validated['role'];
        $newActive = $request->boolean('is_active', true);

        if ($this->wouldRemoveLastActiveAdmin($user, $newRole, $newActive)) {
            return redirect()->route('users.index')
                ->with('error', 'Perubahan ditolak: sistem harus memiliki minimal satu Administrator aktif.');
        }

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $newRole,
            'class_id' => $newRole === 'wali_kelas' ? $validated['class_id'] : null,
            'is_active' => $newActive,
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = Hash::make($validated['password']);
        }

        $user->update($payload);

        $this->logAudit('update_user', 'User', $user->id, $before, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'class_id' => $user->class_id,
            'is_active' => $user->is_active,
        ]);

        return redirect()->route('users.index')
            ->with('success', "Data pengguna {$user->name} berhasil diperbarui.");
    }

    /**
     * Hapus akun pengguna.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return redirect()->route('users.index')
                ->with('error', 'Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif digunakan.');
        }

        if ($this->isLastActiveAdmin($user)) {
            return redirect()->route('users.index')
                ->with('error', 'Penghapusan ditolak: sistem harus memiliki minimal satu Administrator aktif.');
        }

        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'class_id' => $user->class_id,
        ];

        $userName = $user->name;
        $user->delete();

        $this->logAudit('delete_user', 'User', null, $before, null);

        return redirect()->route('users.index')
            ->with('success', "Pengguna {$userName} berhasil dihapus.");
    }

    /**
     * Cek apakah perubahan role/status akan menghilangkan Administrator aktif terakhir.
     */
    private function wouldRemoveLastActiveAdmin(User $user, string $newRole, bool $newActive): bool
    {
        if ($user->role !== 'admin' || ! $user->is_active) {
            return false;
        }

        if ($newRole === 'admin' && $newActive) {
            return false;
        }

        return User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }

    /**
     * Cek apakah pengguna adalah satu-satunya Administrator aktif.
     */
    private function isLastActiveAdmin(User $user): bool
    {
        if ($user->role !== 'admin' || ! $user->is_active) {
            return false;
        }

        return User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }

    /**
     * Aturan validasi formulir pengguna.
     *
     * Dipakai bersama oleh store() dan update() agar aturan tidak menyimpang:
     * saat membuat akun password wajib, saat mengubah akun password opsional.
     *
     * @return array<string, array<int, mixed>>
     */
    private function userRules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                $user === null
                    ? 'unique:users,email'
                    : Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => $user === null
                ? ['required', 'string', 'min:8']
                : ['nullable', 'string', 'min:8'],
            'role' => ['required', 'string', 'in:admin,operator,wali_kelas'],
            'class_id' => ['nullable', 'required_if:role,wali_kelas', 'exists:classes,id'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Pesan validasi formulir pengguna.
     *
     * @return array<string, string>
     */
    private function userValidationMessages(): array
    {
        return [
            'class_id.required_if' => 'Kelas yang diampu wajib dipilih jika peran adalah Wali Kelas.',
            'email.unique' => 'Email ini sudah digunakan oleh pengguna lain.',
            'password.min' => 'Password minimal harus 8 karakter.',
        ];
    }
}
