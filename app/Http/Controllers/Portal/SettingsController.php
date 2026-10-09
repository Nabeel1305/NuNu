<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\VoiceNumber;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** What the platform operator has configured for this tenant. Read-only here on purpose. */
class SettingsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $max = $tenant->setting('max_amount_minor');

        return view('portal.settings', [
            'numbers' => VoiceNumber::where('tenant_id', $tenant->id)->orderBy('number')->get(),
            'maxAmount' => $max ? Money::format($max, 'NGN') : null,
            'apiBase' => url('/api/v1'),
        ]);
    }
}
