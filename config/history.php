<?php

/*
|--------------------------------------------------------------------------
| Activity history management (Log page)
|--------------------------------------------------------------------------
| Archive hides a row from the Log, Dashboard and Validation Results (it can
| be restored); Delete removes it permanently. Neither ever triggers a
| validation or an execution. Set HISTORY_MANAGEMENT_ENABLED=false to make
| the history read-only (buttons hidden, routes answer 403).
*/

return [

    'management_enabled' => (bool) env('HISTORY_MANAGEMENT_ENABLED', true),

    // A request that is still running (in progress / allowed / authorized)
    // is locked so the pipeline never writes to a removed row. A crashed run
    // is unlocked after this many seconds (longer than LLM + validation +
    // the 300 s execution limit).
    'stale_after_seconds' => (int) env('HISTORY_STALE_AFTER_SECONDS', 900),

];
