<?php

namespace App\Services\ScopeEnforcer;

/**
 * Decision values defined by the Scope Enforcer backend.
 * Laravel never produces ALLOW or WARNING itself; it only produces DENY
 * when failing closed.
 */
enum Decision: string
{
    case Allow = 'ALLOW';
    case Warning = 'WARNING';
    case Deny = 'DENY';
}
