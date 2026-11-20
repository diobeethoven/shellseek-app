<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReconRequest;
use App\Models\ActivityLog;
use App\Services\Recon\ReconPipeline;
use App\Services\Recon\WarningApprovalPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Reconnaissance flow: natural-language instruction in, validated (and, only
 * if authorized, executed) command out. All logic lives in ReconPipeline.
 *
 * Post/Redirect/Get: after the pipeline ran, the browser is redirected to the
 * stored result (GET /recon/{log}), so a page refresh can never re-run the
 * LLM, the validation or an execution.
 */
class ChatController extends Controller
{
    public function index(): View
    {
        return view('recon.index');
    }

    public function show(ActivityLog $log, WarningApprovalPolicy $warningApproval): View
    {
        ActivityLog::abortUnlessVisible($log);

        return view('recon.show', [
            'log' => $log,
            'warningApprovalEnabled' => $warningApproval->isEnabled(),
        ]);
    }

    public function store(ReconRequest $request, ReconPipeline $pipeline): RedirectResponse
    {
        $outcome = $pipeline->run((string) $request->validated('instruction'), $request->user()->id);

        $flash = [
            'message' => $outcome->message,
            'tone' => $outcome->status->tone(),
            'status' => $outcome->status->value,
        ];

        if ($outcome->logId !== null) {
            return redirect()->route('recon.show', $outcome->logId)->with('recon', $flash);
        }

        return redirect()->route('recon.index')->withInput()->with('recon', $flash);
    }
}
