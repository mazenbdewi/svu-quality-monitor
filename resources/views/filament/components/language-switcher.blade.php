@php
    use Filament\Support\Icons\Heroicon;

    $currentLocale = app()->getLocale();
    $supportedLocales = config('locales.supported', []);
    $languageCodes = [
        'ar' => 'Ar',
        'en' => 'En',
    ];
    $currentLanguageCode = $languageCodes[$currentLocale] ?? strtoupper($currentLocale);
@endphp

<div
    class="fi-language-switcher ms-2 me-3 flex items-center"
    aria-label="{{ __('monitoring.language_switcher.label') }}"
>
    <x-filament::dropdown
        placement="bottom-end"
        width="w-36"
        shift
        teleport
    >
        <x-slot name="trigger">
            <button
                type="button"
                class="inline-flex min-w-[44px] items-center justify-center rounded-full border border-gray-200 bg-white px-3 py-1.5 text-center text-xs font-semibold leading-5 text-gray-700 shadow-sm transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
            >
                {{ $currentLanguageCode }}
            </button>
        </x-slot>

        <x-filament::dropdown.list>
            @foreach ($supportedLocales as $locale => $localeConfig)
                @php
                    $isCurrent = $locale === $currentLocale;
                @endphp

                <x-filament::dropdown.list.item
                    :tag="$isCurrent ? 'button' : 'a'"
                    :href="$isCurrent ? null : route('locale.switch', ['locale' => $locale])"
                    :disabled="$isCurrent"
                    :color="$isCurrent ? 'primary' : 'gray'"
                    :icon="$isCurrent ? Heroicon::Check : null"
                    :icon-color="$isCurrent ? 'primary' : 'gray'"
                    :attributes="
                        new \Illuminate\View\ComponentAttributeBag([
                            'aria-current' => $isCurrent ? 'true' : null,
                            'class' => $isCurrent ? 'bg-primary-50 font-semibold dark:bg-primary-950' : null,
                        ])
                    "
                >
                    {{ $languageCodes[$locale] ?? strtoupper($locale) }} - {{ $localeConfig['name'] }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
