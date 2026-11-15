<?php

namespace App\Services;

/**
 * Réduit un libellé bancaire brut à une clé « marchand » stable, pour
 * regrouper les opérations d'un même commerçant malgré les numéros de
 * carte, dates et références qui changent à chaque passage.
 */
class MerchantNormalizer
{
    public function normalize(string $label): string
    {
        $m = mb_strtoupper($label);

        // Préfixes de moyen de paiement (la date à 4 chiffres colle au préfixe)
        $m = preg_replace('/^(PAIEMENT (PSC|CB|MOB)|RETRAIT DAB|\d+ PAIEMENTS?) \d{4} /', '', $m);
        $m = preg_replace('/^PRLV SEPA /', '', $m);
        $m = preg_replace('/^VIR (INST |SEPA |PERM )?/', '', $m);
        $m = preg_replace('/^CHEQUE \d+.*/', 'CHEQUE', $m);
        // Libellés produits par les parseurs Trade Republic / N26
        $m = preg_replace('/^(VIREMENT (REÇU DE|ÉMIS VERS)|PRÉLÈVEMENT|FRAIS N26 :) /u', '', $m);
        $m = preg_replace('/ – .*$/u', '', $m);

        // Montant en devise étrangère : "12 00 USD EXEMPLE SAAS"
        $m = preg_replace('/^\d+ \d{2} [A-Z]{3} /', '', $m);

        // Suffixes techniques
        $m = preg_replace('/ ?CARTE \d{4}.*$/', '', $m);
        $m = preg_replace('/ ?(UIR|UIL|UIP)[0-9A-Z]{6,}.*$/', '', $m);
        $m = preg_replace('/ ?PAYWEB\d+.*$/', '', $m);

        // Références numériques longues, mandats, numéros de facture
        $m = preg_replace('/\b(MANDAT|FACTURE)\b.*$/', '', $m);
        $m = preg_replace('/\b[0-9]{6,}\b/', '', $m);
        // Réf alphanumériques type dossier/virement : tout mot contenant une
        // suite de 5 chiffres ou plus (LIDL1234 reste, AB1C23456D789012 saute)
        $m = preg_replace('/\b(?=[A-Z0-9\/-]*\d{5})[A-Z0-9\/-]+\b/', '', $m);

        $m = trim(preg_replace('/\s+/', ' ', $m));

        // Agrégateurs de paiement : le libellé ne dit rien du service réel,
        // c'est le montant qui distingue les abonnements (Netflix, Spotify…).
        if (str_starts_with($m, 'PAYPAL')) {
            return 'PAYPAL';
        }

        return mb_substr($m, 0, 80);
    }
}
