<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\User;
use App\Services\Exports\PtaSuiviWorkbookExporter;
use App\Services\PtaSuiviService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PtaSuiviWebController extends Controller
{
    public function __construct(
        private readonly PtaSuiviService $ptaSuiviService,
        private readonly PtaSuiviWorkbookExporter $workbookExporter
    ) {
    }

    public function index(Request $request)
    {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        return view('workspace.pta-suivi.index', $this->ptaSuiviService->buildPagePayload($request, $user));
    }

    public function details(Request $request, Action $action)
    {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        return view('workspace.pta-suivi.partials.details', $this->ptaSuiviService->buildActionDetails($action, $user));
    }

    public function exportPdf(Request $request)
    {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        $payload = $this->ptaSuiviService->buildPagePayload($request, $user);
        $filename = $this->filename($payload, 'pdf');

        return Pdf::loadView('workspace.pta-suivi.pdf', $payload)
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $user = $this->user($request);
        $this->ptaSuiviService->denyUnlessAuthorized($user);

        $payload = $this->ptaSuiviService->buildPagePayload($request, $user);
        $filename = $this->filename($payload, 'xlsx');
        $tempPath = $this->workbookExporter->create($payload);

        return response()->streamDownload(function () use ($tempPath): void {
            $stream = fopen($tempPath, 'rb');
            if (! is_resource($stream)) {
                @unlink($tempPath);

                return;
            }

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        break;
                    }

                    echo $chunk;
                }
            } finally {
                fclose($stream);
                @unlink($tempPath);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function filename(array $payload, string $extension): string
    {
        $title = Str::slug((string) ($payload['title'] ?? 'suivi-pta'), '_');
        $date = now()->format('Ymd_His');

        return ($title !== '' ? $title : 'suivi_pta').'_'.$date.'.'.$extension;
    }
}
