<form class="searchbox {{ $large ?? false ? 'large' : '' }}" action="{{ route('kb.search') }}" method="GET" role="search">
    <x-icon name="search" :size="16" />
    <input type="search" name="q" value="{{ $q ?? '' }}" placeholder="Search articles" aria-label="Search articles" autocomplete="off">
</form>
