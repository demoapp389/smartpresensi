<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackupService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseBackupController extends Controller
{
    /**
     * Unduh file SQL dump database langsung dari browser.
     * Hanya dapat diakses oleh Administrator (diproteksi via RBAC middleware).
     */
    public function download(Request $request, DatabaseBackupService $backupService): StreamedResponse
    {
        $filename = $backupService->getBackupFilename();

        return $backupService->streamDownload($filename, $request->ip());
    }
}
