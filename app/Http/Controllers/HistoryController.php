<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\History\Outcome;
use App\Services\ScopeEnforcer\Decision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Log" page (route /history): every request as a timeline – prompt,
 * generated command, decision and execution status.
 *
 * Every account sees only its own entries (ActivityLogPolicy).
 *
 * Manual management per entry (own entries only): Archive (hidden from Log,
 * Dashboard and Validation Results, restorable), Restore, Delete
 * (permanent). These only change history rows: they never validate or
 * execute anything. Disabled by HISTORY_MANAGEMENT_ENABLED=false; rows of a
 * request that is still running are locked (see ActivityLog::isManageable()).
 */
class HistoryController extends Controller
{
    public function index(Request $request): View
    {
        $outcome = Outcome::tryFrom((string) $request->query('outcome', ''));
        $decision = Decision::tryFrom((string) $request->query('decision', ''));
        $archived = $request->query('view') === 'archived';

        $user = $request->user();
        $logs = ActivityLog::query()
            ->visibleTo($user)
            ->when($archived, fn ($q) => $q->archived(), fn ($q) => $q->active())
            ->when($outcome, fn ($q) => $q->where('outcome', $outcome->value))
            ->when($decision, fn ($q) => $q->where('decision', $decision->value))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('history.index', [
            'logs' => $logs,
            'outcome' => $outcome,
            'decision' => $decision,
            'outcomes' => Outcome::cases(),
            'archived' => $archived,
            'archivedCount' => ActivityLog::visibleTo($user)->archived()->count(),
            'manage' => self::managementEnabled() && $user->can('manage', ActivityLog::class),
        ]);
    }

    public function archive(ActivityLog $log): RedirectResponse
    {
        $this->guard($log);
        if (! $log->isArchived()) {
            $log->forceFill(['archived_at' => now()])->save();
        }

        return self::done('Entry #'.$log->id.' archived. It no longer counts on the Dashboard.', 'archived');
    }

    public function restore(ActivityLog $log): RedirectResponse
    {
        $this->guard($log);
        if ($log->isArchived()) {
            $log->forceFill(['archived_at' => null])->save();
        }

        return self::done('Entry #'.$log->id.' restored.', 'restored');
    }

    public function destroy(ActivityLog $log): RedirectResponse
    {
        $this->guard($log);
        $id = $log->id;
        $log->delete();

        return self::done('Entry #'.$id.' deleted permanently.', 'deleted');
    }

    private function guard(ActivityLog $log): void
    {
        abort_unless(self::managementEnabled(), 403, 'History management is disabled.');
        abort_unless(auth()->user()?->can('manage', $log) === true, 404);
        abort_unless($log->isManageable(), 409, 'This request is still running and cannot be changed yet.');
    }

    private static function managementEnabled(): bool
    {
        return (bool) config('history.management_enabled', false);
    }

    private static function done(string $message, string $status): RedirectResponse
    {
        return redirect()->back(fallback: route('history.index'))
            ->with('history', ['message' => $message, 'tone' => 'ok', 'status' => $status]);
    }
}
