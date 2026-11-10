<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\VoiceCallLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Every call the voice provider handed the platform and what became of it: the first place to look when "the code was not redeemed". */
class CallsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $rows = VoiceCallLog::query()
            ->when($request->filled('tenant'), fn ($q) => $q->where('tenant_id', (int) $request->query('tenant')))
            ->when($request->filled('outcome'), fn ($q) => $q->where('outcome', $request->query('outcome')))
            ->when($request->filled('number'), fn ($q) => $q->where('destination', 'like', '%' . addcslashes((string) $request->query('number'), '%_\\') . '%'))
            ->when($request->boolean('hide_prompts'), fn ($q) => $q->where('step', '!=', 'prompt'))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('admin.calls.index', [
            'rows' => $rows,
            'tenants' => Tenant::orderBy('name')->pluck('name', 'id'),
            'reasons' => VoiceCallLog::REASONS,
            'lastCall' => VoiceCallLog::max('created_at'),
        ]);
    }
}
