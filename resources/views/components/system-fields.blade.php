@props(['fields', 'values' => [], 'prefix' => null])
{{-- Renders an App\Support\Fields list with system-field (secrets get the set / replace pattern). --}}
@foreach ($fields as $f)
    @php
        $type = $f['type'] ?? 'text';
        $name = $prefix ? $prefix.'['.$f['key'].']' : $f['key'];
        $val = $values[$f['key']] ?? ($f['default'] ?? null);
        $secret = $type === 'password';
        $required = ! $secret && ! empty($f['required']);
        $optional = in_array($type, ['text', 'email', 'url', 'textarea', 'password', 'number'], true) && ! $required && ! array_key_exists('default', $f);
    @endphp
    @if (! empty($f['heading']))<h3>{{ $f['heading'] }}</h3>@endif
    <x-system-field :name="$name" :label="$f['label'] ?? $f['key']" :type="$type" :value="$secret ? null : $val" :options="$f['options'] ?? []"
        :required="$required" :optional="$optional" :placeholder="$f['placeholder'] ?? null" :suffix="$f['suffix'] ?? null" :help="$f['help'] ?? null" :secret-set="$secret && ! empty($values[$f['key']])" autocomplete="off" />
@endforeach
