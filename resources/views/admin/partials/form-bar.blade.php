{{--
  Sticky total + save bar and inline validation for the invoice and quote forms.
  Needs $cancel (url), $actions (list of [value, label, primary?]). The total is mirrored from the line editor's data-sum="total".
--}}
<div class="form-bar" role="region" aria-label="Save">
    <div class="fb-total"><span>Total (<span data-cur-label>{{ $currency }}</span>)</span><b data-bar-total>0.00</b></div>
    <div class="fb-actions">
        <a class="btn ghost" href="{{ $cancel }}">Cancel</a>
        @foreach ($actions as $a)<button class="btn {{ ! empty($a[2]) ? 'primary' : '' }}" type="submit" name="action" value="{{ $a[0] }}">{{ $a[1] }}</button>@endforeach
    </div>
</div>
