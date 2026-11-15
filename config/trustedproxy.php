<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies de confiance
    |--------------------------------------------------------------------------
    |
    | Adresses IP ou plages CIDR des reverse proxies dont l'application croit
    | les en-têtes X-Forwarded-* (schéma https, IP du client), séparées par des
    | virgules, ou « * » pour tout proxy. Vide : aucun proxy de confiance.
    | Le middleware TrustProxies du framework lit cette valeur.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
