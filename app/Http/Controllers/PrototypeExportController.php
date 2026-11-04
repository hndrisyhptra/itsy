<?php

namespace App\Http\Controllers;

use App\Services\PrototypeReportService;
use App\Services\PrototypeWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrototypeExportController extends Controller
{
    public function __invoke(Request $request, PrototypeWorkspaceService $workspaces, PrototypeReportService $reports)
    {
        $filters = $request->validate([
            'report' => ['required', Rule::in(PrototypeReportService::REPORTS)],
            'branch' => ['sometimes', 'string', 'max:200'], 'unit' => ['sometimes', 'string', 'max:200'],
            'project' => ['sometimes', 'string', 'max:100'], 'mandor' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', Rule::in(['Draft', 'Terbit', 'Sah', 'Belum diimpor', 'Review', 'Final', 'Diajukan', 'Disetujui', 'Dikembalikan', 'Ditolak'])],
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => array_filter(['sometimes', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : null]),
        ]);
        $data = $reports->build($workspaces->payload($workspaces->workspace($request))['state'], $filters);
        $name = 'area3-'.str_replace(' ', '-', strtolower($filters['report'])).'.csv';

        return response()->streamDownload(function () use ($data) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ([$data['headers'], ...$data['rows']] as $row) {
                $row = array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $row);
                fputcsv($stream, $row, ',', '"', '', "\r\n");
            }
            fclose($stream);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
