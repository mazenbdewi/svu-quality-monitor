<style>
    .research-cards-grid {
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 1rem !important;
        width: 100% !important;
        align-items: stretch !important;
    }

    .research-cards-grid > * {
        min-width: 0 !important;
        width: 100% !important;
    }

    @media (max-width: 1024px) {
        .research-cards-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        }
    }

    @media (max-width: 640px) {
        .research-cards-grid {
            grid-template-columns: minmax(0, 1fr) !important;
        }
    }
</style>

<x-filament-widgets::widget dir="rtl">
    <section aria-labelledby="research-findings-heading">
        <h2 id="research-findings-heading" class="mb-4 text-lg font-semibold text-gray-950 dark:text-white">
            {{ __('monitoring.interpretation.sections.key_findings') }}
        </h2>

        <div class="research-cards-grid">
            @foreach ($cards as $card)
                <article class="fi-wi-stats-overview-stat" style="padding: 1.25rem; text-align: center;">
                    <div style="display: flex; flex-direction: column; align-items: center; gap: 0; text-align: center;">
                        <div style="width: 100%; font-size: 0.9375rem; font-weight: 500; line-height: 1.5; color: var(--gray-500);">
                            {{ $card['title'] }}
                        </div>

                        <div dir="ltr" style="width: 100%; margin-top: 0.75rem; font-size: 1.5rem; font-weight: 600; line-height: 1.35; text-align: center; unicode-bidi: isolate;">
                            {{ $card['value'] }}
                        </div>

                        <div style="width: 100%; margin-top: 0.375rem; font-size: 0.9375rem; font-weight: 500; line-height: 1.5; text-align: center;">
                            {{ $card['indicator'] }}
                        </div>

                        <div style="width: 100%; margin-top: 0.875rem; text-align: center;">
                            <x-filament::badge :color="$card['color']">{{ $card['status'] }}</x-filament::badge>
                        </div>

                        <div class="text-gray-500 dark:text-gray-400" style="width: 100%; margin-top: 0.625rem; font-size: 0.875rem; line-height: 1.45; text-align: center;">
                            {{ $card['message'] }}
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
</x-filament-widgets::widget>
