<?php

namespace App\Http\Controllers;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\AddonService;
use App\Services\Registration;
use App\Support\Countries;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The account step of checkout. A visitor who picks a plan without being signed in lands here instead of on the plain login page:
 * a sign-up form with the details an order needs, and a link to sign in instead. Either way they continue to the review step.
 */
class CheckoutAccountController extends Controller
{
    public static function registrationOpen(): bool
    {
        return (bool) config('billing.registration') && Settings::bool('general.registration', true);
    }

    public function show(Request $request)
    {
        abort_unless(self::registrationOpen(), 404);
        $order = $this->order($request);
        if (! $order) {
            return redirect()->route('store.index');
        }
        $params = $order['params'];
        if (Auth::check()) {
            return redirect()->route('portal.order.create', $params);
        }
        // Signing in instead should land on the review step, whichever way the visitor got here.
        redirect()->setIntendedUrl(route('portal.order.create', $params));

        return view('checkout.account', $order + ['countries' => ['' => 'Select…'] + Countries::all(), 'termsUrl' => config('billing.legal.terms')]);
    }

    public function store(Request $request, Registration $registration): RedirectResponse
    {
        abort_unless(self::registrationOpen(), 404);
        $order = $this->order($request);
        if (! $order) {
            return redirect()->route('store.index')->with('error', 'That plan is not available any more.');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_1' => ['required', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'max:30'],
            'country' => ['required', Rule::in(array_keys(Countries::all()))],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'password' => ['required', Password::min(8)],
        ], ['email.unique' => 'There is already an account with this email. Use "Log in" above to continue.'], [
            'address_1' => 'address', 'address_2' => 'address line 2', 'company_name' => 'company', 'tax_id' => 'VAT / tax ID', 'name' => 'name',
        ]);

        $result = $registration->createClient(collect($data)->except('password')->filter(fn ($v) => $v !== null && $v !== '')->all(), $data['password']);
        Auth::login($result['user']->refresh());
        $request->session()->regenerate();

        return redirect()->route('portal.order.create', $order['params']);
    }

    /** @return array{product: Product, cycle: BillingCycle, price: ProductPrice, addons: Collection, params: array<string, mixed>, currency: string, trial: bool}|null */
    private function order(Request $request): ?array
    {
        $product = Product::plans()->with('prices')->where('slug', (string) $request->input('product'))->where('is_active', true)->where('is_visible', true)->first();
        $cycle = BillingCycle::tryFrom((string) $request->input('cycle'));
        if (! $product || ! $cycle) {
            return null;
        }
        $currency = (string) Settings::get('billing.currency', config('billing.currency'));
        $price = $product->prices->first(fn ($p) => $p->cycle === $cycle && $p->currency === $currency);
        if (! $price) {
            return null;
        }
        try {
            $addons = app(AddonService::class)->resolveForOrder($product, $cycle, $currency, (array) $request->input('addons', []));
        } catch (\DomainException) {
            return null;
        }
        $label = trim((string) $request->input('label'));

        return [
            'product' => $product, 'cycle' => $cycle, 'price' => $price, 'addons' => $addons, 'currency' => $currency, 'label' => $label,
            'trial' => $cycle->isRecurring() && $product->offersTrial(),
            'params' => array_filter(['product' => $product->slug, 'cycle' => $cycle->value, 'label' => $label ?: null, 'addons' => $addons->pluck('product.slug')->all() ?: null]),
        ];
    }
}
