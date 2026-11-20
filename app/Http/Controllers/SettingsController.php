<?php

namespace App\Http\Controllers;

use App\Services\Status\SystemStatusService;
use Illuminate\View\View;

/**
 * Read-only view of the configuration. Secret files are described by path,
 * presence and permission bits only; their contents are never read here.
 */
class SettingsController extends Controller
{
    public function __invoke(SystemStatusService $status): View
    {
        return view('settings', [
            'scopeEnforcer' => config('scope_enforcer'),
            'llm' => config('llm'),
            'execution' => config('execution'),
            'history' => config('history'),
            'tools' => (array) config('tools'),
            'secretFiles' => [
                'API token' => self::fileStatus((string) config('scope_enforcer.token_file')),
                'Signing secret' => self::fileStatus((string) config('scope_enforcer.signing_secret_file')),
            ],
            'signatureVerificationAvailable' => $status->signatureVerificationAvailable(),
        ]);
    }

    /**
     * @return array{path: string, present: bool, mode: ?string, secure: bool}
     */
    private static function fileStatus(string $path): array
    {
        clearstatcache(true, $path);
        $present = $path !== '' && is_file($path);
        $perms = $present ? @fileperms($path) : false;

        return [
            'path' => $path,
            'present' => $present,
            'mode' => $perms === false ? null : sprintf('%04o', $perms & 0777),
            'secure' => $perms !== false && ($perms & 0077) === 0,
        ];
    }
}
