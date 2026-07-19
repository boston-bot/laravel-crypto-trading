<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class OperationsConsolePageController extends Controller
{
    public function overview(): View
    {
        return $this->page('overview');
    }

    public function strategies(): View
    {
        return $this->page('strategies');
    }

    public function assets(?string $symbol = null): View
    {
        return $this->page('assets', $symbol);
    }

    public function activity(): View
    {
        return $this->page('activity');
    }

    public function paper(): View
    {
        return $this->page('paper');
    }

    public function operations(): View
    {
        return $this->page('operations');
    }

    public function research(): View
    {
        return $this->page('research');
    }

    private function page(string $page, ?string $symbol = null): View
    {
        return view('operations-console', ['page' => $page, 'symbol' => $symbol]);
    }
}
