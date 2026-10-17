<?php

namespace App\Http\Controllers;

use App\Support\Mcp\DatabaseReadiness;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(DatabaseReadiness $database)
    {
        try {
            $ready = $database->inspect()['migrations_present'];
        } catch (Throwable) {
            $ready = false;
        }

        return response()->json(['status' => $ready ? 'ready' : 'unavailable'], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
