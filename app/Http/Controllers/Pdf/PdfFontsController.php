<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Services\PdfFonts;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Installs and removes the optional CJK font packs from Settings > System. Admins only. */
class PdfFontsController extends Controller
{
    public function install(Request $request, PdfFonts $fonts): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can install fonts.');
        $pack = (string) $request->input('pack', 'default');
        $keys = $pack === 'default' ? (array) config('pdf-fonts.default') : [$pack];
        abort_unless(! array_diff($keys, array_keys($fonts->packs())), 422, 'Unknown font pack.');
        @set_time_limit(0);

        try {
            foreach ($keys as $key) {
                if ($request->input('action') === 'remove') {
                    $fonts->uninstall($key);
                } else {
                    $fonts->install($key);
                }
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['fonts' => $e->getMessage()]);
        }
        Activity::log('pdf.fonts', null, ($request->input('action') === 'remove' ? 'Removed' : 'Installed').' PDF font pack(s): '.implode(', ', $keys));

        return back()->with('status', ($request->input('action') === 'remove' ? 'Font pack removed.' : 'Font pack installed. PDFs with Chinese, Japanese or Korean text will now render them.'));
    }
}
