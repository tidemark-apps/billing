<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\Product;
use App\Plugins\ModuleRegistry;
use App\Support\Fields;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    use FormatsMoney;

    public function toArray($request): array
    {
        $staff = $this->isStaffRequest($request);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'group' => $this->whenLoaded('group', fn () => $this->group ? ['id' => $this->group->id, 'name' => $this->group->name] : null),
            'label_prompt' => $this->label_prompt,
            'tax_exempt' => $this->tax_exempt,
            'is_active' => $this->is_active,
            'is_visible' => $this->is_visible,
            'trial_days' => (int) $this->trial_days,
            'trial_requires_payment_method' => $this->when($staff, (bool) $this->trial_requires_payment_method),
            'allow_plan_changes' => $this->when($staff, (bool) $this->allow_plan_changes),
            'trial_available' => $this->when(! $staff, fn () => ($client = $request->attributes->get('catalog_client')) ? $this->trialAvailableTo($client) : null),
            'module' => $this->when($staff, $this->module),
            'module_config' => $this->when($staff, fn () => $this->visibleModuleConfig()),
            'prices' => ProductPriceResource::collection($this->whenLoaded('prices')),
            'meters' => UsageMeterResource::collection($this->whenLoaded('meters')),
        ];
    }

    /**
     * Staff see the module settings without password-type fields. If the module's plugin is not enabled we cannot tell
     * which keys are secret, so nothing is returned rather than risk leaking one.
     *
     * @return array<string, mixed>|null
     */
    private function visibleModuleConfig(): ?array
    {
        $config = $this->module_config;
        if (empty($config)) {
            return $config;
        }
        $module = app(ModuleRegistry::class)->get($this->module);

        return $module ? array_diff_key($config, array_flip(Fields::secretKeys($module->productFields()))) : null;
    }
}
