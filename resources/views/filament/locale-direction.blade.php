@php
    $locale = app()->getLocale();
    $direction = config("locales.supported.{$locale}.direction", 'ltr');
@endphp

<script>
    document.documentElement.lang = @js(str_replace('_', '-', $locale));
    document.documentElement.dir = @js($direction);
</script>
