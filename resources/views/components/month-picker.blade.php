@props(['monthDate', 'canGoNext' => false, 'months' => []])

{{--
    Sélecteur de mois uniforme : ‹ › pour naviguer, tap sur le mois pour ouvrir
    la grille des 12 derniers mois. Les actions ciblent le composant Livewire parent
    (trait HasMonthNavigation).
    Le conteneur prend toute la place laissée par les actions de droite ; sous 15rem
    (« Septembre 2026 » ne tient plus), le mois passe en abrégé.
--}}
<div x-data="{ open: false }" class="@container relative min-w-0 flex-1" @keydown.escape.window="open = false">
    <div class="flex h-10 w-fit max-w-full items-center rounded-full bg-surface p-1 ring-1 ring-white/10">
        <button type="button" wire:click="previousMonth" aria-label="{{ __('Previous month') }}"
                class="grid size-8 place-items-center rounded-full text-ink-2 transition active:bg-white/10">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
        </button>
        <button type="button" @click="open = !open" aria-haspopup="true" :aria-expanded="open"
                class="flex min-w-0 items-center gap-1 px-2 text-sm font-semibold capitalize">
            <span class="truncate whitespace-nowrap" wire:loading.class="opacity-50" wire:target="previousMonth,nextMonth,selectMonth">
                <span class="@max-[15rem]:hidden">{{ $monthDate->isoFormat('MMMM YYYY') }}</span>
                <span class="hidden @max-[15rem]:inline">{{ $monthDate->isoFormat('MMM YYYY') }}</span>
            </span>
            <svg class="size-3.5 shrink-0 text-ink-3 transition" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
        </button>
        <button type="button" wire:click="nextMonth" @disabled(! $canGoNext) aria-label="{{ __('Next month') }}"
                class="grid size-8 place-items-center rounded-full transition {{ $canGoNext ? 'text-ink-2 active:bg-white/10' : 'cursor-not-allowed text-ink-3/40' }}">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
        </button>
    </div>

    @if ($months)
        <div x-cloak x-show="open" x-transition.origin.top.left @click.outside="open = false"
             class="absolute left-0 top-12 z-40 w-72 rounded-3xl bg-surface-2 p-2 shadow-2xl shadow-black/60 ring-1 ring-white/10">
            <div class="grid grid-cols-3 gap-1">
                @foreach ($months as $m)
                    @php $selected = $m->format('Y-m') === $monthDate->format('Y-m'); @endphp
                    <button type="button" wire:click="selectMonth('{{ $m->format('Y-m') }}')" @click="open = false"
                            wire:key="mp-{{ $m->format('Y-m') }}"
                            class="rounded-xl px-2 py-2.5 text-xs font-medium capitalize transition {{ $selected ? 'bg-brand text-ground' : 'text-ink-2 hover:bg-white/5' }}">
                        {{ $m->isoFormat('MMM YY') }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
