<div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <h2>{{ $subjectLine }}</h2>
    @foreach ($lines as $line)
        <p>{{ $line }}</p>
    @endforeach
</div>
