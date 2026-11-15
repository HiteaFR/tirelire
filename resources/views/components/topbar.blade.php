@props(['title' => null, 'monthDate' => null, 'canGoNext' => false, 'months' => []])

@php $privateMode = app(\App\Support\PrivateMode::class)->isEnabled(); @endphp

{{-- Barre sticky commune : titre ou sélecteur de mois à gauche, actions à droite --}}
{{-- Le fond est porté par un pseudo-élément flouté qui s'estompe vers le bas : pas de bande à bord net au scroll --}}
<header class="relative sticky top-0 z-30 -mx-4 mb-4 px-4 pt-3 pb-4 before:pointer-events-none before:absolute before:inset-x-0 before:-top-px before:bottom-0 before:-z-10 before:bg-ground/85 before:backdrop-blur-xl before:[mask-image:linear-gradient(to_bottom,black_65%,transparent)]"
        style="padding-top: calc(0.75rem + env(safe-area-inset-top))">
    <div class="flex h-10 items-center justify-between gap-3">
        @if ($monthDate)
            <x-month-picker :month-date="$monthDate" :can-go-next="$canGoNext" :months="$months" />
        @else
            <h1 class="truncate text-xl font-semibold tracking-tight">{{ $title }}</h1>
        @endif
        <div class="flex shrink-0 items-center gap-2">
            {{ $slot }}
            {{-- Mode privé : masque tous les montants (action du composant de page, trait TogglesPrivateMode) --}}
            <button type="button" wire:click="togglePrivateMode" wire:loading.attr="disabled" wire:target="togglePrivateMode"
                    aria-pressed="{{ $privateMode ? 'true' : 'false' }}"
                    aria-label="{{ $privateMode ? __('Show amounts') : __('Hide amounts') }}"
                    class="grid size-10 shrink-0 place-items-center rounded-full ring-1 transition disabled:opacity-50 {{ $privateMode ? 'bg-brand/15 text-brand ring-brand/30' : 'bg-surface text-ink-2 ring-white/10' }}">
                @if ($privateMode)
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                @else
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                @endif
            </button>
        </div>
    </div>
</header>
