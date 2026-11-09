<?php

namespace Tests\Feature\Admin;

use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxRatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_rate_crud(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('admin.tax-rates.index'))->assertOk()->assertSee('No tax rates');
        $this->post(route('admin.tax-rates.store'), ['name' => 'VAT', 'rate' => '20', 'country' => 'gb', 'is_active' => '1'])->assertSessionHas('status');
        $rate = TaxRate::firstOrFail();
        $this->assertSame('GB', $rate->country);
        $this->assertSame(20.0, $rate->rate);

        $this->get(route('admin.tax-rates.index'))->assertOk()->assertSee('VAT');
        $this->put(route('admin.tax-rates.update', $rate), ['name' => 'UK VAT', 'rate' => '19.5', 'country' => 'GB', 'is_active' => '0'])->assertSessionHas('status');
        $rate->refresh();
        $this->assertSame('UK VAT', $rate->name);
        $this->assertFalse($rate->is_active);

        $this->post(route('admin.tax-rates.store'), ['name' => 'Bad', 'rate' => '150'])->assertSessionHasErrors('rate');
        $this->delete(route('admin.tax-rates.destroy', $rate))->assertSessionHas('status');
        $this->assertSame(0, TaxRate::count());
    }
}
