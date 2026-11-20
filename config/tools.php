<?php

/*
|--------------------------------------------------------------------------
| Tool catalog (PROVISIONAL)
|--------------------------------------------------------------------------
| The list of recon tools ShellSeek knows about. The research on which tools,
| actions and flags are acceptable is still ongoing; update this file with
| the results. No code change is needed to add, remove or describe a tool.
|
| What this catalog is used for:
|   - prompt guidance for the LLM (action name, target kind, hint);
|   - the absolute binary + timeout used if (and only if) the Scope Enforcer
|     ALLOWs a command and the ExecutionGate verifies its authorization.
|
| What it is NOT: a policy. Laravel never accepts or rejects a command based
| on this file. Commands for tools that are not listed are still sent to the
| Scope Enforcer (which decides); they simply cannot be executed locally.
|
| Fields:
|   action   action name to use in the request
|   target   what the target is (shown to the LLM), e.g. "an IP address"
|   hint     optional extra guidance for the LLM about parameters
|   binary   absolute path of the executable
|   timeout  seconds (capped by execution.max_timeout)
|
| argv is always [binary, ...parameters, target]. Binaries are not
| configurable through .env on purpose.
*/

return [

    'nmap' => [
        'action' => 'service_detection',
        'target' => 'an IP address',
        'hint' => 'Ports go as "-p" followed by a comma-separated list, e.g. ["-sV", "-p", "80,443"].',
        'binary' => '/usr/bin/nmap',
        'timeout' => 300,
    ],

    'whois' => [
        'action' => 'domain_lookup',
        'target' => 'a domain name',
        'hint' => 'Usually no parameters: [].',
        'binary' => '/usr/bin/whois',
        'timeout' => 30,
    ],

];
