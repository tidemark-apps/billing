@props(['fields', 'values' => [], 'prefix' => null])
{{-- Renders a field list described with App\Support\Fields (gateway, module, plugin and server settings). --}}
@foreach ($fields as $f)
    @php
        $type = $f['type'] ?? 'text';
        $name = $prefix ? $prefix.'['.$f['key'].']' : $f['key'];
        $val = $values[$f['key']] ?? ($f['default'] ?? null);
        $isSecret = $type === 'password';
        $hasSecret = $isSecret && ! empty($values[$f['key']]);
    @endphp
    <x-field :name="$name" :label="$f['label'] ?? $f['key']" :type="$type === 'password' ? 'password' : $type"
        :value="$isSecret ? null : $val" :options="$f['options'] ?? []" :required="$isSecret ? false : ! empty($f['required'])"
        :placeholder="$hasSecret ? '•••••••• (saved, leave blank to keep)' : ($f['placeholder'] ?? null)" :help="$f['help'] ?? null" autocomplete="off" />
@endforeach
