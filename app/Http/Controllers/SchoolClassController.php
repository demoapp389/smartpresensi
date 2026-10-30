<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SchoolClassController extends Controller
{
    use LogsAuditTrail;

    public function index(): View
    {
        $classes = SchoolClass::withCount('students')
            ->with('homeroomTeacher')
            ->orderBy('name')
            ->get();

        return view('classes.index', compact('classes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'academic_year' => ['required', 'string', 'max:20'],
        ]);

        $class = SchoolClass::create($validated);

        $this->logAudit('create_class', 'SchoolClass', $class->id, null, $class->toArray());

        return redirect()->route('classes.index')
            ->with('success', 'Kelas '.$class->name.' berhasil ditambahkan.');
    }

    public function update(Request $request, SchoolClass $class): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'academic_year' => ['required', 'string', 'max:20'],
        ]);

        $before = $class->toArray();
        $class->update($validated);

        $this->logAudit('update_class', 'SchoolClass', $class->id, $before, $class->toArray());

        return redirect()->route('classes.index')
            ->with('success', 'Data kelas berhasil diperbarui.');
    }

    public function destroy(SchoolClass $class, Request $request): RedirectResponse
    {
        if ($class->students()->count() > 0) {
            return back()->withErrors(['Kelas ini tidak dapat dihapus karena masih memiliki data siswa.']);
        }

        $before = $class->toArray();
        $class->delete();

        $this->logAudit('delete_class', 'SchoolClass', $class->id, $before, null);

        return redirect()->route('classes.index')
            ->with('success', 'Kelas berhasil dihapus.');
    }
}
