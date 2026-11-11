<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $action = (string) $request->input('action', '');
        $entityType = (string) $request->input('entity_type', '');
        $dateFrom = (string) $request->input('date_from', '');
        $dateTo = (string) $request->input('date_to', '');
        $showSystem = $request->boolean('show_system');

        $query = AuditLog::with('actor')->latest('created_at');

        // Aksi sistem (Auto-Alpa, pemrosesan log gagal) sangat banyak dan bukan
        // jejak tindakan pengguna, jadi disembunyikan kecuali diminta.
        if (! $showSystem) {
            $query->humanActions();
        }

        if ($action !== '') {
            $query->where('action', $action);
        }

        if ($entityType !== '') {
            $query->where('entity_type', $entityType);
        }

        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Paginasi server: tabel audit dapat membesar tanpa batas, sehingga
        // tidak boleh dimuat seluruhnya ke memori setiap kali halaman dibuka.
        $logs = $query->paginate($this->resolvePerPage(50))->withQueryString();

        $actions = AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action');
        $entityTypes = AuditLog::query()->select('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type');

        return view('audit.index', compact(
            'logs',
            'action',
            'entityType',
            'dateFrom',
            'dateTo',
            'showSystem',
            'actions',
            'entityTypes',
        ));
    }
}
