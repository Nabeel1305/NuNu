<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Platform-wide defaults, editable without a server login. A blank field goes back to the built-in default. */
class SettingsController extends Controller
{
    public function edit(): View
    {
        $rows = [];
        foreach (PlatformSettings::DEFINITIONS as $key => [$label, $kind, $cfg]) {
            $saved = PlatformSettings::get($key);
            $rows[] = ['key' => $key, 'label' => $label, 'kind' => $kind,
                'saved' => $saved === null ? null : ($kind === 'money' ? $saved / 100 : $saved),
                'default' => $kind === 'money' ? config($cfg) / 100 : config($cfg)];
        }

        return view('admin.settings.edit', ['rows' => $rows]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (PlatformSettings::DEFINITIONS as $key => [, $kind]) {
            $rules['s.' . str_replace('.', '_', $key)] = ['nullable', 'numeric', 'min:' . ($key === 'admin.idle_minutes' ? 0 : ($kind === 'money' ? 1 : 1)), 'max:100000000'];
        }
        $data = $request->validate($rules)['s'] ?? [];

        foreach (PlatformSettings::DEFINITIONS as $key => [, $kind]) {
            $v = $data[str_replace('.', '_', $key)] ?? null;
            PlatformSettings::put($key, ($v === null || $v === '') ? null : (int) round($kind === 'money' ? ((float) $v) * 100 : (float) $v), $request->user('admin')->id);
        }

        return back()->with('status', 'Saved.');
    }
}
