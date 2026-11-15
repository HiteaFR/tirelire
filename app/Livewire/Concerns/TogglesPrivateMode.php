<?php

namespace App\Livewire\Concerns;

use App\Support\PrivateMode;

/**
 * Bouton « mode privé » de la barre du haut (composant topbar) : le basculement
 * est une action du composant de page, dont le nouveau rendu masque ou révèle
 * les montants sans recharger la page.
 */
trait TogglesPrivateMode
{
    public function togglePrivateMode(): void
    {
        app(PrivateMode::class)->toggle();
    }
}
