<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ChangelogController extends Controller
{
    public function __invoke(): View
    {
        $file = base_path('CHANGELOG.md');
        $html = is_file($file) ? Str::markdown(file_get_contents($file), ['html_input' => 'strip', 'allow_unsafe_links' => false]) : '<p>No changelog yet.</p>';

        return view('admin.changelog', ['html' => $html]);
    }
}
