<?php

namespace App\Services\Execution;

enum SignatureCheck: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unavailable = 'unavailable';
}
