<?php

namespace App\Services\Execution;

enum ExecutionStatus: string
{
    case Completed = 'completed';
    case TimedOut = 'timed_out';
    case OutputLimitExceeded = 'output_limit_exceeded';
    case Refused = 'refused';
    case Error = 'error';
}
