@extends('layouts.app', ['area' => 'portal'])
@section('title', 'New ticket')
@section('content')
<x-page-head title="New ticket" :back="route('portal.tickets.index')" back-label="Tickets" />
@if ($departments->isEmpty())
<x-card><x-empty icon="life-buoy" title="Support is not available" text="There are no departments accepting tickets right now." /></x-card>
@else
<form method="POST" action="{{ route('portal.tickets.store') }}" enctype="multipart/form-data" class="card setting form-narrow" data-ticket-form data-validate>
@csrf
<div class="setting-h"><h2>Tell us what you need</h2><p>We reply by email and here in your account.</p></div>
<div class="setting-b">
        @if ($locked)
            <input type="hidden" name="department" value="{{ $selected->slug }}">
            <div><span class="muted small">Department</span><div><b>{{ $selected->name }}</b></div></div>
        @elseif ($departments->count() <= 6)
            <div><b>Who should help?</b>
                <div class="choice-group mt-8" role="radiogroup" aria-label="Who should help?">
                    @foreach ($departments as $d)
                    <label class="choice"><input type="radio" name="department" value="{{ $d->slug }}" data-dept-radio @checked(old('department', $selected?->slug) === $d->slug)><span><b>{{ $d->name }}</b>@if ($d->description)<span class="help">{{ $d->description }}</span>@endif</span></label>
                    @endforeach
                </div>
                @error('department')<span class="field-error">{{ $message }}</span>@enderror
            </div>
        @else
            <label for="f-department"><span>Department</span>
                <select id="f-department" name="department" data-dept-select>
                    @foreach ($departments as $d)<option value="{{ $d->slug }}" @selected(old('department', $selected?->slug) === $d->slug)>{{ $d->name }}</option>@endforeach
                </select>
                @foreach ($departments as $d)@if ($d->description)<span class="help" data-dept-help="{{ $d->slug }}" hidden>{{ $d->description }}</span>@endif @endforeach
            </label>
        @endif
        <x-field name="subject" label="Subject" maxlength="200" aria-required="true" help="A few words that sum up the problem." />
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
                    <x-field :name="$name" :id="$fid" type="select" :label="$f->label.($f->required ? '' : ' (optional)')" :help="$f->help" :required="false" :aria-required="$f->required ? 'true' : null" :options="['' => 'Select…'] + collect($f->options ?? [])->mapWithKeys(fn ($o) => [str_replace(',', '', $o) => $o])->all()" />
                @elseif ($f->type === 'checkbox')
                    <x-field :name="$name" :id="$fid" type="checkbox" :label="$f->label" :help="$f->help" />
                @elseif ($f->type === 'textarea')
                    <x-field :name="$name" :id="$fid" type="textarea" :label="$f->label.($f->required ? '' : ' (optional)')" :help="$f->help" :required="false" :aria-required="$f->required ? 'true' : null" />
                @else
                    <x-field :name="$name" :id="$fid" :type="in_array($f->type, ['number', 'date']) ? $f->type : 'text'" :label="$f->label.($f->required ? '' : ' (optional)')" :help="$f->help" :required="false" :aria-required="$f->required ? 'true' : null" :step="$f->type === 'number' ? 'any' : null" />
                @endif
            @endforeach
        </div>
        @endforeach

        <x-field name="body" label="How can we help?" type="textarea" rows="7" aria-required="true" help="Include any error messages, domain names or steps to reproduce." />
        @include('portal.tickets._files', ['limits' => $limits])
</div>
<div class="setting-f"><span class="help">You will get an email when we reply.</span><div class="row"><a class="btn sm" href="{{ route('portal.tickets.index') }}">Cancel</a><button class="btn primary sm" type="submit">Open ticket</button></div></div>
</form>
<script>
(function () {
    var form = document.querySelector('[data-ticket-form]');
    if (!form) return;
    var select = form.querySelector('[data-dept-select]');
    var current = function () {
        if (select) return select.value;
        var r = form.querySelector('input[name=department]:checked') || form.querySelector('input[name=department]');
        return r ? r.value : '';
    };
    function apply() {
        var slug = current();
        form.querySelectorAll('[data-dept-fields]').forEach(function (g) {
            var on = g.getAttribute('data-dept-fields') === slug;
            g.classList.toggle('hidden', !on);
            g.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !on; });
        });
        form.querySelectorAll('[data-dept-help]').forEach(function (h) { h.hidden = h.getAttribute('data-dept-help') !== slug; });
    }
    if (select) select.addEventListener('change', apply);
    form.querySelectorAll('[data-dept-radio]').forEach(function (r) { r.addEventListener('change', apply); });
    apply();
})();
</script>
@endif
@endsection
