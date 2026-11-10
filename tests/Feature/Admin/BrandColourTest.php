<?php

namespace Tests\Feature\Admin;

use App\Support\Brand;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandColourTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_accepts_hex_in_a_few_shapes_and_rejects_the_rest(): void
    {
        $this->assertSame('#aabbcc', Brand::normalize('#AABBCC'));
        $this->assertSame('#aabbcc', Brand::normalize('abc'));
        $this->assertSame('#112233', Brand::normalize(' #123 '));
        foreach (['', 'red', '#12', '#12345', '#gggggg', null, 12] as $bad) {
            $this->assertNull(Brand::normalize($bad));
        }
    }

    public function test_every_colour_ends_up_readable_in_both_themes(): void
    {
        $samples = ['#ffffff', '#000000', '#ffff00', '#00ff00', '#ff0000', '#0000ff', '#808080', '#fafafa', '#0a0a0a', '#1a2a6c', '#ffd1dc', '#0e7c86', '#7a3cff', '#ff8800'];
        foreach ($samples as $hex) {
            $p = Brand::palette($hex);
            foreach (['light' => ['#fafafa', '#ffffff'], 'dark' => ['#000000', '#0a0a0a']] as $theme => [$page, $card]) {
                $t = $p[$theme];
                $this->assertGreaterThanOrEqual(3.0, Brand::contrast($t['brand'], $page), "$hex fill on $theme page");
                $this->assertGreaterThanOrEqual(4.5, Brand::contrast($t['brand'], $t['foreground']), "$hex button text on $theme");
                $this->assertGreaterThanOrEqual(4.5, Brand::contrast($t['ink'], $page), "$hex text on $theme page");
                $this->assertGreaterThanOrEqual(4.5, Brand::contrast($t['ink'], $card), "$hex text on $theme card");
                $this->assertGreaterThanOrEqual(4.5, Brand::contrast($t['ink'], $t['soft']), "$hex text on its $theme tint");
            }
        }
    }

    public function test_a_colour_that_already_works_is_kept_as_it_is(): void
    {
        $p = Brand::palette('#0e7c86');
        $this->assertSame('#0e7c86', $p['light']['brand']);
        $p = Brand::palette('#4fc3be');
        $this->assertSame('#4fc3be', $p['dark']['brand']);
    }

    public function test_a_too_pale_colour_keeps_its_hue_but_gets_darker_on_light(): void
    {
        $p = Brand::palette('#ffe066');
        $this->assertNotSame('#ffe066', $p['light']['ink']);
        $this->assertTrue($p['adjusted']['light']);
        $this->assertGreaterThan(Brand::luminance($p['light']['ink']), Brand::luminance('#ffe066'));
    }

    public function test_pages_use_the_default_teal_until_a_colour_is_set(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertDontSee('--brand-ink:', false);

        Settings::set('company.brand_color', '#7a3cff');
        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('--brand:#7a3cff', $html);
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);
    }

    public function test_saving_the_setting_stores_a_normalised_value_and_rejects_nonsense(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.settings.update', 'general'), ['s' => ['company__name' => 'Acme', 'company__brand_color' => 'ABC']])->assertSessionHasNoErrors();
        $this->assertSame('#aabbcc', Settings::get('company.brand_color'));

        $this->actingAs($admin)->put(route('admin.settings.update', 'general'), ['s' => ['company__name' => 'Acme', 'company__brand_color' => 'blue']])->assertSessionHasErrors('s.company__brand_color');

        $this->actingAs($admin)->put(route('admin.settings.update', 'general'), ['s' => ['company__name' => 'Acme', 'company__brand_color' => '']]);
        $this->assertNull(Settings::get('company.brand_color'));
    }
}
