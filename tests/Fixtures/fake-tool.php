<?php

/*
| FAKE tool used by tests in place of a real binary (never nmap/whois).
| Tests copy this file to a temp dir with a "#!<PHP_BINARY>" shebang.
| It performs NO network activity. Mode = first argument:
|   echo   -> prints its arguments as JSON
|   env    -> prints its environment as JSON
|   sleep  -> sleeps 10 s
|   flood  -> writes ~13 MB to stdout
|   exit3  -> writes to stderr and exits with code 3
*/

$args = array_slice($argv, 1);

switch ($args[0] ?? '') {
    case 'echo':
        echo json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        break;
    case 'env':
        echo json_encode(getenv());
        break;
    case 'sleep':
        sleep(10);
        break;
    case 'flood':
        $chunk = str_repeat('A', 65536);
        for ($i = 0; $i < 200; $i++) {
            echo $chunk;
        }
        break;
    case 'exit3':
        fwrite(STDERR, 'simulated failure');
        exit(3);
    default:
        fwrite(STDERR, 'unknown mode');
        exit(64);
}
