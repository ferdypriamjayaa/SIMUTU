<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * @param  string|null  $title  page heading, also used in the browser tab
     * @param  string|null  $subtitle  short description under the heading
     * @param  string|null  $back  URL for the "Kembali" link above the heading
     */
    public function __construct(
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $back = null,
    ) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
