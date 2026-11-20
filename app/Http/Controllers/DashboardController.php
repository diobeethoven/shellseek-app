<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\History\Outcome;
use App\Services\Status\SystemStatusService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SystemStatusService $status): View
    {
        // Archived rows are excluded everywhere on the dashboard; deleted rows are gone.
        $user = $request->user();
        $byDecision = ActivityLog::query()
            ->visibleTo($user)
            ->active()
            ->whereNotNull('decision')
            ->selectRaw('decision, count(*) as total')
            ->groupBy('decision')
            ->pluck('total', 'decision');

        $executedOutcomes = [Outcome::Executed->value, Outcome::TimedOut->value, Outcome::OutputLimitExceeded->value];

        return view('dashboard', [
            'total' => ActivityLog::visibleTo($user)->active()->count(),
            'allow' => (int) ($byDecision['ALLOW'] ?? 0),
            'warning' => (int) ($byDecision['WARNING'] ?? 0),
            'deny' => (int) ($byDecision['DENY'] ?? 0),
            'failClosed' => ActivityLog::visibleTo($user)->active()->where('origin', 'fail_closed')->count(),
            'executed' => ActivityLog::visibleTo($user)->active()->whereIn('outcome', $executedOutcomes)->count(),
            'recent' => ActivityLog::query()->visibleTo($user)->active()->latest('id')->limit(10)->get(),
            'status' => $status->current(fresh: $request->boolean('refresh')),
        ]);
    }
}
