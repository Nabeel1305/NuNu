<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Merchant;

use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Subscribers (payers) and merchants (payees) the tenant has registered through the API. Read-only. */
class PartiesController extends Controller
{
    public function subscribers(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $like = addcslashes($q, '%_\\') . '%';

        $rows = Subscriber::query()
            ->withCount('codes')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('phone', 'like', $like)))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('portal.parties.subscribers', ['rows' => $rows, 'q' => $q]);
    }

    public function merchants(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $like = addcslashes($q, '%_\\') . '%';

        $rows = Merchant::query()
            ->withCount('codes')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('name', 'like', $like)))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('portal.parties.merchants', ['rows' => $rows, 'q' => $q]);
    }
}
