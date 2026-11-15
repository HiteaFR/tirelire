@props(['category' => null, 'size' => 'md'])

{{-- Pastille de catégorie : emoji sur fond teinté de la couleur de la catégorie --}}
@php
    $color = $category?->color ?? '#71717a';
    $dims = $size === 'sm' ? 'size-8 text-base' : 'size-10 text-lg';
@endphp
<span {{ $attributes->class(['grid shrink-0 place-items-center rounded-full', $dims]) }}
      style="background: color-mix(in oklab, {{ $color }} 18%, transparent)">{{ $category?->icon ?? '❓' }}</span>
