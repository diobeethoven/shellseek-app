<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\Recon\WarningApprovalPolicy;
use App\Services\ScopeEnforcer\Decision;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ValidationController extends Controller
{
    public function index(Request $request): View
    {
        $decision = Decision::tryFrom((string) $request->query('decision', ''));

        $logs = ActivityLog::query()
            ->visibleTo($request->user())
            ->active()
            ->whereNotNull('decision')
            ->when($decision, fn ($q) => $q->where('decision', $decision->value))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('validations.index', ['logs' => $logs, 'decision' => $decision]);
    }

    public function show(ActivityLog $log, WarningApprovalPolicy $warningApproval): View
    {
        ActivityLog::abortUnlessVisible($log);

        return view('validations.show', [
            'log' => $log,
            'warningApprovalEnabled' => $warningApproval->isEnabled(),
        ]);
    }
}
