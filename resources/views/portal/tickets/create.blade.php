@extends('layouts.app', ['area' => 'portal'])
@section('title', 'New ticket')
@section('content')
<x-page-head title="New ticket" :back="route('portal.tickets.index')" back-label="Tickets" />
@if ($departments->isEmpty())
<x-card><x-empty icon="life-buoy" title="Support is not available" text="There are no departments accepting tickets right now." /></x-card>
@else
<form method="POST" action="{{ route('portal.tickets.store') }}" enctype="multipart/form-data" data-ticket-form>
@csrf
<x-card title="Tell us what you need">
    <div class="stack">
        @if ($locked)
            <input type="hidden" name="department" value="{{ $selected->slug }}">
            <div><span class="muted small">Department</span><div><b>{{ $selected->name }}</b></div></div>
        @else
            <label for="f-department"><span>Department <span class="muted" aria-hidden="true">*</span></span>
                <select id="f-department" name="department" required data-dept-select>
                    @foreach ($departments as $d)<option value="{{ $d->slug }}" @selected(old('department', $selected?->slug) === $d->slug)>{{ $d->name }}</option>@endforeach
                </select>
                @foreach ($departments as $d)@if ($d->description)<span class="help" data-dept-help="{{ $d->slug }}" hidden>{{ $d->description }}</span>@endif @endforeach
            </label>
        @endif
        <x-field name="subject" label="Subject" required maxlength="200" />
        <label for="f-service"><span>Related service (optional)</span>
            <select id="f-service" name="service">
                <option value="">None</option>
                @foreach ($services as $s)<option value="{{ $s->uuid }}" @selected(old('service', $selectedService) === $s->uuid)>{{ $s->title() }}</option>@endforeach
            </select>
            @error('service')<span class="field-error">{{ $message }}</span>@enderror
        </label>

        @foreach ($fields as $slug => $list)
        <div class="stack" data-dept-fields="{{ $slug }}">
            @foreach ($list as $f)
                @php($name = 'custom['.$f->key.']')
                @php($fid = 'f-custom-'.$slug.'-'.$f->key)
                @if ($f->type === 'select')
                    <x-field :name="$name" :id="$fid" type="select" :label="$f->label" :help="$f->help" :required="$f->required" :options="['' => 'Select…'] + collect($f->options ?? [])->mapWithKeys(fn ($o) => [str_replace(',', '', $o) => $o])->all()" />
                @elseif ($f->type === 'checkbox')
                    <x-field :name="$name" :id="$fid" type="checkbox" :label="$f->label" :help="$f->help" />
                @elseif ($f->type === 'textarea')
                    <x-field :name="$name" :id="$fid" type="textarea" :label="$f->label" :help="$f->help" :required="$f->required" />
                @else
                    <x-field :name="$name" :id="$fid" :type="in_array($f->type, ['number', 'date']) ? $f->type : 'text'" :label="$f->label" :help="$f->help" :required="$f->required" :step="$f->type === 'number' ? 'any' : null" />
                @endif
            @endforeach
        </div>
        @endforeach

        <x-field name="body" label="Message" type="textarea" required />
        @include('portal.tickets._files', ['limits' => $limits])
        <div class="row"><button class="btn primary" type="submit">Open ticket</button><a class="btn" href="{{ route('portal.tickets.index') }}">Cancel</a></div>
    </div>
</x-card>
</form>
<script>
(function () {
    var form = document.querySelector('[data-ticket-form]');
    if (!form) return;
    var select = form.querySelector('[data-dept-select]');
    var current = function () { return select ? select.value : (form.querySelector('input[name=department]') || {}).value; };
    function apply() {
        var slug = current();
        form.querySelectorAll('[data-dept-fields]').forEach(function (g) {
            var on = g.getAttribute('data-dept-fields') === slug;
            g.hidden = !on;
            g.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !on; });
        });
        form.querySelectorAll('[data-dept-help]').forEach(function (h) { h.hidden = h.getAttribute('data-dept-help') !== slug; });
    }
    if (select) select.addEventListener('change', apply);
    apply();
})();
</script>
@endif
@endsection
