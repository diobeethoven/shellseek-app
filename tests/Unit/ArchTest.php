<?php

/*
| Guard rails for the whole project: application code must never execute
| commands through a shell. Command execution is only allowed via
| Symfony Process with an argument array, inside SymfonyProcessRunner.
*/

arch('application code does not use shell execution functions')
    ->expect('App')
    ->not->toUse(['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec']);

arch('application code does not leave debug calls')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'var_dump', 'print_r', 'ray']);

arch('only SymfonyProcessRunner may use Symfony Process')
    ->expect('Symfony\Component\Process')
    ->toOnlyBeUsedIn('App\Services\Execution\SymfonyProcessRunner');

/**
 * PHP source tokens of every file under app/, without comments.
 *
 * @return array<string, list<array|string>>
 */
function appTokens(): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));
    $result = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $tokens = array_values(array_filter(
            token_get_all(file_get_contents($file->getPathname())),
            fn ($t) => ! (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true))
        ));
        $result[$file->getPathname()] = $tokens;
    }

    return $result;
}

test('application code contains no backtick (shell) operator', function () {
    foreach (appTokens() as $path => $tokens) {
        expect(in_array('`', $tokens, true))->toBeFalse("backtick operator found in {$path}");
    }
});

test('only ExecutionGate creates AuthorizedCommand instances', function () {
    $creators = [];
    foreach (appTokens() as $path => $tokens) {
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_NEW && isset($tokens[$i + 1]) && is_array($tokens[$i + 1])
                && str_ends_with(ltrim($tokens[$i + 1][1], '\\'), 'AuthorizedCommand')) {
                $creators[] = basename($path);
            }
        }
    }

    expect($creators)->toBe(['ExecutionGate.php']);
});
