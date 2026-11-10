<label for="f-attachments"><span>Attachments (optional)</span>
    <input id="f-attachments" type="file" name="attachments[]" multiple @if (! empty($limits['extensions'])) accept="{{ collect($limits['extensions'])->map(fn ($e) => '.'.$e)->implode(',') }}" @endif>
    <span class="help">Up to {{ $limits['count'] }} {{ \Illuminate\Support\Str::plural('file', $limits['count']) }}, {{ $limits['mb'] }} MB each.@if (! empty($limits['extensions'])) Allowed types: {{ implode(', ', $limits['extensions']) }}.@endif</span>
    @error('attachments')<span class="field-error">{{ $message }}</span>@enderror
    @error('attachments.*')<span class="field-error">{{ $message }}</span>@enderror
</label>
