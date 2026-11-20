<?php

namespace App\Models;

use App\Services\History\Outcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * History of one user request. Written only through HistoryLogService
 * (which masks secrets); read by the UI. Archived rows (archived_at set)
 * are hidden from the Log, Dashboard and Validation Results.
 */
class ActivityLog extends Model
{
    // user_id is set explicitly by HistoryLogService (forceFill), never mass-assigned.
    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'reasons' => 'array',
            'validation' => 'array',
            'argv' => 'array',
            'execution_allowed' => 'boolean',
            'revalidated' => 'boolean',
            'llm_duration_ms' => 'integer',
            'execution_duration_ms' => 'integer',
            'exit_code' => 'integer',
            'authorization_issued_at' => 'integer',
            'authorization_expires_at' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** Local account that made the request (null for rows from before login existed). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Rows a user may see: only their own. Fail-closed: no user => no rows.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $query->where('user_id', $user?->id ?? 0)->whereNotNull('user_id');
    }

    /**
     * Detail pages: a log the user may not see answers 404 (not 403), so
     * nobody can probe which ids exist.
     */
    public static function abortUnlessVisible(self $log): void
    {
        abort_unless(auth()->user()?->can('view', $log) === true, 404);
    }

    /** Rows shown in the Log, Dashboard and Validation Results. */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Archive/delete is allowed only when the pipeline is done with the row
     * (final outcome), or when a run crashed and the row went stale.
     */
    public function isManageable(): bool
    {
        $outcome = Outcome::tryFrom((string) $this->outcome);
        if ($outcome !== null && $outcome->isFinal()) {
            return true;
        }

        $staleAfter = max(1, (int) config('history.stale_after_seconds', 900));
        $lastWrite = $this->updated_at ?? $this->created_at;

        return $lastWrite !== null && $lastWrite->lte(now()->subSeconds($staleAfter));
    }
}
