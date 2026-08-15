<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('directives.field.aircraft') }}</label>
            <select wire:model.live="aircraftId"
                    class="dark:text-white dark:[&>option]:bg-gray-900 dark:[&>option]:text-white fi-select-input rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                @foreach ($this->aircraftOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="onlyOutstanding"
                   class="rounded border-gray-300 dark:border-white/10 dark:bg-white/5">
            {{ __('directives.overview.only_outstanding') }}
        </label>

        {{-- Kein Druckknopf ohne Luftfahrzeug: Es gaebe nichts zu drucken, und
             die Adresse liesse sich gar nicht bilden. --}}
        @if ($this->printUrl() !== null)
            <x-filament::button tag="a" href="{{ $this->printUrl() }}" target="_blank"
                                icon="heroicon-o-printer" color="gray">
                {{ __('directives.overview.print') }}
            </x-filament::button>
        @endif
    </div>

    {{-- The one thing that has to be read before the list below it.

         Loud on purpose, and above the tally: for an orphaned type every number
         underneath is still correct and still misleading, because the list they
         count can no longer grow. A discreet hint next to a green "0 offen"
         would lose that argument every time. --}}
    @if ($this->isOrphaned())
        {{-- Filament's own callout rather than a hand-built box: it is styled by
             the panel stylesheet that ships with the release, so the warning is
             red wherever this runs and does not depend on the asset build. --}}
        <x-filament::callout
            color="danger"
            icon="heroicon-o-exclamation-triangle"
            :heading="__('directives.orphaned.headline')"
            :description="__('directives.orphaned.body', ['type' => $this->aircraftType()?->designation ?? ''])"
        />
    @endif

    @php($tally = $this->tally())

    <x-filament::section>
        <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-xs text-gray-500">{{ __('directives.overview.total') }}</dt>
                <dd class="text-lg font-semibold">{{ $tally['total'] }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">{{ __('directives.overview.unassessed') }}</dt>
                <dd @class(['text-lg font-semibold', 'text-danger-600 dark:text-danger-400' => $tally['unassessed'] > 0])>
                    {{ $tally['unassessed'] }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">{{ __('directives.overview.outstanding') }}</dt>
                <dd class="text-lg font-semibold">{{ $tally['outstanding'] }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">{{ __('directives.overview.blocking') }}</dt>
                <dd @class(['text-lg font-semibold', 'text-danger-600 dark:text-danger-400' => $tally['blocking'] > 0])>
                    {{ $tally['blocking'] }}
                </dd>
            </div>
        </dl>

        {{-- The red flag, said in words rather than left to a number. --}}
        @if ($tally['unassessed'] > 0)
            <p class="mt-3 text-sm text-danger-600 dark:text-danger-400">
                {{ __('directives.help.unassessed_blocks') }}
            </p>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('directives.plural')">
        <x-slot name="description">{{ __('directives.help.four_states') }}</x-slot>

        @php($lines = $this->lines())

        @if ($lines->isEmpty())
            {{-- An empty list is never self-explanatory, so it explains itself.
                 For an orphaned type the banner above already said why; for every
                 other type the two remaining readings -- nothing published, or no
                 source set up yet -- look identical here and must be named. --}}
            <p class="text-sm text-gray-500">
                {{ $this->isOrphaned()
                    ? __('directives.empty.orphaned')
                    : __('directives.empty.ambiguous') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 pr-3">{{ __('directives.field.number') }}</th>
                            <th class="py-2 pr-3">{{ __('directives.field.kind') }}</th>
                            <th class="py-2 pr-3">{{ __('directives.field.title') }}</th>
                            <th class="py-2 pr-3">{{ __('directives.field.comply_before') }}</th>
                            <th class="py-2 pr-3">{{ __('directives.field.state') }}</th>
                            <th class="py-2 pr-3">{{ __('directives.field.reason') }}</th>
                            <th class="py-2 pr-3">{{ __('directives.field.next_due') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($lines as $line)
                            @php($d = $line['directive'])
                            @php($a = $line['application'])
                            @php($state = $this->stateOf($a))
                            <tr>
                                <td class="py-2 pr-3 whitespace-nowrap font-medium">
                                    <a href="{{ \App\Modules\Directives\Filament\Resources\Directives\DirectiveResource::getUrl('view', ['record' => $d]) }}"
                                       class="text-primary-600 hover:underline dark:text-primary-400">{{ $d->number }}</a>
                                </td>
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    <span @class([
                                        'rounded px-1.5 py-0.5 text-xs',
                                        'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400' => $d->isMandatory(),
                                        'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $d->isMandatory(),
                                    ])>{{ $d->kind->label() }}</span>
                                </td>
                                <td class="py-2 pr-3">{{ $d->title }}</td>
                                <td @class(['py-2 pr-3 whitespace-nowrap', 'text-danger-600 dark:text-danger-400' => $d->isOverdue()])>
                                    {{ $d->comply_before?->format('d.m.Y') ?? '—' }}
                                </td>
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    <span @class([
                                        'rounded px-1.5 py-0.5 text-xs',
                                        'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400' => $state === \App\Modules\Directives\Enums\ComplianceState::Complied,
                                        'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400' => $state === \App\Modules\Directives\Enums\ComplianceState::Open,
                                        'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400' => $state === \App\Modules\Directives\Enums\ComplianceState::NotCarriedOut,
                                        'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => $state === \App\Modules\Directives\Enums\ComplianceState::NotApplicable,
                                    ])>{{ $state->label() }}</span>
                                    @if ($a?->assessed_at)
                                        <span class="text-xs text-gray-500">{{ $a->assessed_at->format('d.m.Y') }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3">{{ $a?->reason ?? $a?->method ?? '—' }}</td>
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    {{ $a?->next_due_at?->format('d.m.Y') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($this->mayAssess())
                <p class="mt-3 text-xs text-gray-500">{{ __('directives.overview.assess_hint') }}</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
