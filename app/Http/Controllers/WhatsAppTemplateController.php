<?php

namespace App\Http\Controllers;

use App\Concerns\LogsAuditTrail;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WhatsAppTemplateController extends Controller
{
    use LogsAuditTrail;

    /**
     * Tampilkan daftar template pesan WhatsApp.
     */
    public function index(): View
    {
        $templates = WhatsAppTemplate::orderBy('id')->get();

        $placeholders = [
            ':nama_siswa' => 'Nama lengkap siswa (contoh: Muhammad Rizky)',
            ':kelas' => 'Kelas siswa (contoh: X-IPA-1)',
            ':tanggal' => 'Tanggal presensi (contoh: '.now()->translatedFormat('d F Y').')',
            ':waktu' => 'Waktu scan (contoh: '.now()->format('H:i').' WIB)',
            ':status' => 'Status kehadiran (Hadir, Terlambat, Pulang)',
            ':nama_sekolah' => 'Nama sekolah (contoh: '.config('whatsapp.school_name', 'SMA Negeri 1').')',
            ':nama_wali' => 'Nama orang tua / wali (contoh: Bapak Hendra)',
            ':pesan' => 'Isi pesan pengumuman sekolah (khusus template siaran)',
        ];

        return view('settings.whatsapp-templates', compact('templates', 'placeholders'));
    }

    /**
     * Perbarui konten template pesan WhatsApp.
     */
    public function update(Request $request, WhatsAppTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Nama template wajib diisi.',
            'content.required' => 'Format isi pesan template tidak boleh kosong.',
            'content.max' => 'Panjang template pesan maksimal 2000 karakter.',
        ]);

        $before = [
            'name' => $template->name,
            'content' => $template->content,
            'is_active' => $template->is_active,
        ];

        $template->update([
            'name' => $validated['name'],
            'content' => $validated['content'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->logAudit('update_whatsapp_template', 'WhatsAppTemplate', $template->id, $before, [
            'name' => $template->name,
            'content' => $template->content,
            'is_active' => $template->is_active,
        ]);

        return redirect()->route('whatsapp.templates.index')
            ->with('success', "Template pesan '{$template->name}' berhasil diperbarui.");
    }

    /**
     * Pratinjau render pesan secara live (AJAX / JSON).
     */
    public function preview(Request $request): JsonResponse
    {
        $content = $request->input('content', '');
        $schoolName = config('whatsapp.school_name', 'SMA Negeri 1');

        $rendered = str_replace(
            [':nama_siswa', ':kelas', ':tanggal', ':waktu', ':status', ':nama_sekolah', ':nama_wali', ':pesan'],
            [
                'Muhammad Rizky Pratama',
                'X-IPA-1',
                now()->translatedFormat('d F Y'),
                now()->format('H:i').' WIB',
                'Hadir Tepat Waktu',
                $schoolName,
                'Bapak Hendra Saputra',
                'Diberitahukan bahwa besok kegiatan belajar mengajar dimulai pukul 07.00 WIB sehubungan dengan pelaksanaan Upacara Bendera.',
            ],
            $content
        );

        return response()->json([
            'success' => true,
            'rendered' => $rendered,
            'char_count' => mb_strlen($rendered),
        ]);
    }
}
