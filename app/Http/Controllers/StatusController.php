<?php

namespace App\Http\Controllers;

use App\Services\Status\SystemStatusService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StatusController extends Controller
{
    public function __invoke(Request $request, SystemStatusService $status): View
    {
        return view('status', ['status' => $status->current(fresh: $request->boolean('refresh'))]);
    }
}
