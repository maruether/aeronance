{{--
    „Was steht an" auf der Startseite.

    Gestaffelt nach dem, was es kostet, es zu übersehen: gesperrte
    Form-1-Ware zuerst (die darf nicht ans Flugzeug), dann Abgelaufenes,
    dann Bestellvorschläge und Gesperrtes, ganz unten die Audit-Mahnung.

    Jede Zeile führt dorthin, wo man sie erledigt -- eine Liste, aus der
    man nicht handeln kann, ist eine Hausaufgabe.
--}}
@php
    $zeile = 'flex items-center justify-between gap-4 py-2 hover:bg-gray-50 dark:hover:bg-white/5 -mx-2 px-2 rounded';
    $lotUrl = fn ($lot) => \App\Modules\Warehouse\Filament\Resources\StockLots\StockLotResource::getUrl('view', ['record' => $lot]);

    // Abgelaufenes fuehrt dorthin, wo man es erledigt -- aber nur fuer die,
    // die vernichten duerfen: Ein Link auf eine Seite, die mit 403 antwortet,
    // ist ein Versprechen an die falsche Person.
    $kannVernichten = auth()->user()?->can(\App\Modules\Warehouse\Permissions::STOCK_SCRAP) ?? false;
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('warehouse.attention.title')">
        <x-slot name="description">{{ __('warehouse.attention.subheading') }}</x-slot>

        {{-- Gesperrt: Form-1-pflichtig, kein Nachweis. --}}
        @if ($withoutCertificate->isNotEmpty())
            <div class="mb-4">
                <p class="mb-1 text-sm font-semibold text-danger-600 dark:text-danger-400">
                    {{ __('warehouse.attention.without_certificate') }}
                </p>
                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('warehouse.attention.without_certificate_hint') }}
                </p>
                <div class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
                    @foreach ($withoutCertificate as $lot)
                        <a href="{{ $lotUrl($lot) }}" class="{{ $zeile }}">
                            <div class="min-w-0">
                                <div class="font-medium">{{ $lot->partType?->name }}</div>
                                <div class="text-gray-500">{{ $lot->label() }}</div>
                            </div>
                            <div class="text-danger-600">{{ __('warehouse.attention.no_reference') }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($expired->isNotEmpty())
            <div class="mb-4">
                <p class="mb-1 text-sm font-semibold text-danger-600 dark:text-danger-400">
                    {{ __('warehouse.attention.expired') }}
                </p>
                <div class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
                    @foreach ($expired as $lot)
                        <a @if ($kannVernichten) href="{{ \App\Modules\Warehouse\Filament\Pages\DisposalPage::getUrl(['lot' => $lot->id]) }}" @endif class="{{ $zeile }}">
                            <div class="min-w-0">
                                <div class="font-medium">{{ $lot->partType?->name }}</div>
                                <div class="text-gray-500">{{ $lot->label() }}</div>
                            </div>
                            <div class="text-gray-500">{{ $lot->expires_at?->format('d.m.Y') }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($below->isNotEmpty())
            <div class="mb-4">
                <p class="mb-1 text-sm font-semibold">{{ __('warehouse.attention.below_minimum') }}</p>
                <div class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
                    @foreach ($below as $teil)
                        <div class="{{ $zeile }}">
                            <div class="min-w-0">
                                <div class="font-medium">{{ $teil->name }}</div>
                                <div class="text-gray-500">{{ $teil->supplier?->name ?? __('warehouse.attention.no_supplier') }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($blocked->isNotEmpty())
            <div class="mb-4">
                <p class="mb-1 text-sm font-semibold text-warning-600 dark:text-warning-400">
                    {{ __('warehouse.attention.blocked') }}
                </p>
                <div class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
                    @foreach ($blocked as $lot)
                        <a href="{{ $lotUrl($lot) }}" class="{{ $zeile }}">
                            <div class="min-w-0">
                                <div class="font-medium">{{ $lot->partType?->name }}</div>
                                <div class="text-gray-500">{{ $lot->label() }}</div>
                            </div>
                            <div class="text-gray-500">{{ $lot->state->label() }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($noDocs->isNotEmpty())
            <div>
                <p class="mb-1 text-sm font-semibold">{{ __('warehouse.attention.missing_documents') }}</p>
                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('warehouse.attention.missing_documents_hint') }}
                </p>
                <div class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
                    @foreach ($noDocs as $lot)
                        <a href="{{ $lotUrl($lot) }}" class="{{ $zeile }}">
                            <div class="min-w-0">
                                <div class="font-medium">{{ $lot->partType?->name }}</div>
                                <div class="text-gray-500">{{ $lot->label() }}</div>
                            </div>
                            <div class="text-gray-500">{{ $lot->document_reference }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
