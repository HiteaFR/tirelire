<?php

namespace App\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Cookie;

/**
 * Mode privé : tous les montants affichés sont remplacés par des astérisques,
 * pour consulter l'app dans un lieu public sans exposer ses finances.
 *
 * La préférence est propre à l'appareil et survit à la session : elle est
 * mémorisée dans un cookie d'un an. L'instance est liée à la requête pour que
 * le rendu qui suit un basculement voie tout de suite le nouvel état.
 */
#[Scoped]
class PrivateMode
{
    public const COOKIE = 'private_mode';

    private const ONE_YEAR_IN_MINUTES = 60 * 24 * 365;

    private ?bool $enabled = null;

    public function isEnabled(): bool
    {
        return $this->enabled ??= request()->cookie(self::COOKIE) === '1';
    }

    public function toggle(): bool
    {
        $this->enabled = ! $this->isEnabled();
        Cookie::queue(self::COOKIE, $this->enabled ? '1' : '0', self::ONE_YEAR_IN_MINUTES);

        return $this->enabled;
    }
}
