<?php

/*
|--------------------------------------------------------------------------
| Command execution (only for commands that passed the ExecutionGate)
|--------------------------------------------------------------------------
| Per-tool binaries and timeouts live in config/tools.php. A tool missing
| there can never run, even if it was ALLOWed.
*/

return [

    // Hard upper bound for any process (seconds). Backend policy: nmap max 300 s.
    'max_timeout' => 300,

    // Combined stdout+stderr cap. The process is stopped when exceeded.
    'max_output_bytes' => 1024 * 1024,

    'working_directory' => storage_path('app/executions'),

    // The child process gets ONLY these variables (nothing from .env).
    'env' => [
        'PATH' => '/usr/bin:/bin',
        'LANG' => 'C.UTF-8',
    ],

];
