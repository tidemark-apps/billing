@if ($maxFiles > 0)
<label for="{{ $id ?? 'reply-files' }}"><span>Attachments</span>
    <input id="{{ $id ?? 'reply-files' }}" type="file" name="attachments[]" multiple>
    <span class="help">Up to {{ $maxFiles }} file{{ $maxFiles === 1 ? '' : 's' }}, {{ $maxMb }} MB each.@if ($allowed) Allowed: {{ implode(', ', $allowed) }}.@endif</span>
</label>
@endif
