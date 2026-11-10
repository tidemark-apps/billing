{{-- Inputs for custom ticket fields. $fields: TicketField collection; $values: current answers. --}}
@foreach ($fields as $f)
    @php
        $type = match ($f->type) { 'textarea' => 'textarea', 'select' => 'select', 'checkbox' => 'checkbox', 'number' => 'number', 'date' => 'date', default => 'text' };
        $options = $type === 'select' ? ['' => 'Choose…'] + collect($f->options ?? [])->mapWithKeys(fn ($o) => [str_replace(',', '', $o) => $o])->all() : [];
    @endphp
    <x-field :name="'custom['.$f->key.']'" :label="$f->label.($f->staff_only ? ' (staff only)' : '')" :type="$type" :options="$options" :required="$f->required && $type !== 'checkbox'" :help="$f->help" :value="$values[$f->key] ?? null" />
@endforeach
