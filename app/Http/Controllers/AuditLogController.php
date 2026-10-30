<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $action = $request->input('action');
        $entityType = $request->input('entity_type');

        $query = AuditLog::with('actor')->latest('created_at');

        if ($action) {
            $query->where('action', $action);
        }

        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        $logs = $query->get();

        return view('audit.index', compact('logs', 'action', 'entityType'));
    }
}
