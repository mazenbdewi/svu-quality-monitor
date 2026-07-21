@php
    $isRtl = app()->getLocale() === 'ar';
    $direction = $isRtl ? 'rtl' : 'ltr';
    $points = trans('monitoring.about.points');
@endphp

<x-filament-panels::page>
    <div dir="{{ $direction }}" class="space-y-6 {{ $isRtl ? 'text-right' : 'text-left' }}">
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-semibold text-primary-600 dark:text-primary-400">
                {{ __('monitoring.about.subtitle') }}
            </p>
            <h2 class="mt-2 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ __('monitoring.about.heading') }}
            </h2>
            <p class="mt-4 text-base leading-8 text-gray-700 dark:text-gray-300">
                {{ __('monitoring.about.body') }}
            </p>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                {{ __('monitoring.about.principles_title') }}
            </h2>
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                @foreach ($points as $point)
                    <article class="rounded-lg bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ $point['title'] }}
                        </h3>
                        <p class="mt-2 text-sm leading-7 text-gray-700 dark:text-gray-300">
                            {{ $point['body'] }}
                        </p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl bg-info-50 p-6 text-info-950 shadow-sm ring-1 ring-info-500/20 dark:bg-info-500/10 dark:text-info-100">
            <h2 class="text-xl font-bold">
                {{ __('monitoring.minitab_validation.external_validation_title') }}
            </h2>
            <p class="mt-3 text-sm leading-7">
                {{ __('monitoring.minitab_validation.external_validation_description') }}
            </p>
        </section>
    </div>
</x-filament-panels::page>
