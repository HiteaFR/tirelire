@props(['cents', 'sign' => false, 'tone' => 'auto'])

{{--
    Montant coloré selon son signe : vert = positif, rose = négatif.
    tone="neutral" garde la couleur du texte courant ; tone="auto" colore.
--}}
@php
    $class = match (true) {
        $tone !== 'auto' => '',
        $cents < 0 => 'text-rose-400',
        $cents > 0 => 'text-brand',
        default => '',
    };
@endphp
<span {{ $attributes->class(['num', $class]) }}>{{ money($cents, sign: $sign) }}</span>
