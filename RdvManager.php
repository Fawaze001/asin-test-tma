<?php
declare(strict_types=1);

/**
 * Règles de gestion « Rendez-vous au guichet » (section 2 de l'énoncé).
 *
 * Module pur : aucune base de données, aucun framework, aucune horloge système.
 * L'heure courante est toujours passée en paramètre (heure du Bénin, GMT+1).
 *
 * Les dates venant de la base ont le format « 2026-11-10 09:00:00 »,
 * celles de la saisie usager le format « 2026-11-10T09:00 » : les deux sont
 * normalisées en DateTimeImmutable avant toute comparaison.
 */
class RdvManager
{
    public const FUSEAU = 'Africa/Porto-Novo';
    public const SERVICES = ['PASSEPORT', 'CNI', 'PERMIS'];
    public const STATUTS_CLOTURES = ['honore', 'absent', 'annule'];
    public const CAPACITE_CRENEAU = 3;
    public const DELAI_ANNULATION_HEURES = 24;
    public const PAR_PAGE = 10;

    // Grille : 08:00 -> 15:30, toutes les 30 minutes (en minutes depuis minuit)
    private const PREMIER_CRENEAU = 8 * 60;
    private const DERNIER_CRENEAU = 15 * 60 + 30;

    // ------------------------------------------------------------------
    // Prise de rendez-vous (TMA-101, 102, 103, 107)
    // ------------------------------------------------------------------
    public static function validerCreation(array $donnees, array $historiqueRdv, string $heureCourante): array
    {
        $npi = $donnees['npi'] ?? '';
        $service = $donnees['service'] ?? '';
        $dateHeureStr = $donnees['dateHeure'] ?? '';

        // NPI : exactement 10 chiffres (\z et non $ : « 0614253647\n » doit être refusé)
        if (!is_string($npi) || !preg_match('/^[0-9]{10}\z/', $npi)) {
            return self::refus(400, 'Le NPI doit comporter exactement 10 chiffres.');
        }

        if (!is_string($service) || !in_array($service, self::SERVICES, true)) {
            return self::refus(400, 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.');
        }

        $dateHeure = self::lireSaisie($dateHeureStr);
        if ($dateHeure === null) {
            return self::refus(400, 'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).');
        }

        $now = self::lireBase($heureCourante);
        if ($dateHeure < $now) {
            return self::refus(409, 'Impossible de prendre un rendez-vous dans le passé.');
        }

        // Samedi (6) ou dimanche (7)
        if ((int) $dateHeure->format('N') >= 6) {
            return self::refus(409, 'Le centre est fermé le week-end.');
        }

        // Grille horaire
        $minutes = (int) $dateHeure->format('H') * 60 + (int) $dateHeure->format('i');
        if ($minutes < self::PREMIER_CRENEAU || $minutes > self::DERNIER_CRENEAU || $minutes % 30 !== 0) {
            return self::refus(409, 'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.');
        }

        // Un seul rendez-vous à venir, tous services confondus
        foreach ($historiqueRdv as $rdv) {
            if (($rdv['npi'] ?? null) === $npi
                && ($rdv['statut'] ?? '') === 'confirme'
                && self::lireBase($rdv['date_heure']) >= $now) {
                return self::refus(409, 'Vous avez déjà un rendez-vous à venir.');
            }
        }

        // Créneau : 3 places maximum, un rendez-vous annulé libère sa place.
        // Comparaison sur l'instant (et non sur la chaîne) : formats base / saisie différents.
        $occupes = 0;
        foreach ($historiqueRdv as $rdv) {
            if (($rdv['statut'] ?? '') !== 'annule'
                && self::lireBase($rdv['date_heure']) == $dateHeure) {
                $occupes++;
            }
        }
        if ($occupes >= self::CAPACITE_CRENEAU) {
            return self::refus(409, 'Ce créneau est complet.');
        }

        return ['status' => 201, 'message' => 'Rendez-vous validé avec succès.'];
    }

    // ------------------------------------------------------------------
    // Annulation par l'usager (TMA-104)
    // ------------------------------------------------------------------
    public static function validerAnnulation(array $rdv, string $heureCourante): array
    {
        if (($rdv['statut'] ?? '') !== 'confirme') {
            return self::refus(409, 'Ce rendez-vous ne peut plus être annulé.');
        }

        // Écart TOTAL en secondes (pas la composante « heures » d'un intervalle),
        // annulation autorisée jusqu'à 24 h pile avant le rendez-vous.
        $ecart = self::lireBase($rdv['date_heure'])->getTimestamp()
            - self::lireBase($heureCourante)->getTimestamp();
        if ($ecart < self::DELAI_ANNULATION_HEURES * 3600) {
            return self::refus(409, 'Annulation impossible moins de 24 h avant le rendez-vous.');
        }

        return ['status' => 200, 'message' => 'Annulation acceptée.'];
    }

    // ------------------------------------------------------------------
    // Changement de statut par un agent (TMA-105)
    // ------------------------------------------------------------------
    public static function validerChangementStatut(array $rdv, string $nouveauStatut): array
    {
        if (!in_array($nouveauStatut, ['honore', 'absent'], true)) {
            return self::refus(400, 'Statut invalide : honore ou absent.');
        }

        // honore, absent et annule sont définitifs
        if (in_array($rdv['statut'] ?? '', self::STATUTS_CLOTURES, true)) {
            return self::refus(409, 'Ce rendez-vous est déjà clôturé.');
        }

        return ['status' => 200, 'message' => 'Statut mis à jour.'];
    }

    // ------------------------------------------------------------------
    // Liste « Mes rendez-vous » (TMA-106)
    // Prochain rendez-vous en tête, puis du plus récent au plus ancien, 10 par page.
    // Le tri porte sur la date réelle, jamais sur la date formatée JJ/MM/AAAA.
    // ------------------------------------------------------------------
    public static function extraireListeUsager(array $rdvs, string $heureCourante, int $page = 1): array
    {
        $now = self::lireBase($heureCourante);
        $page = max(1, $page);

        $parDateDesc = static function (array $a, array $b): int {
            return [self::lireBase($b['date_heure'])->getTimestamp(), $b['id'] ?? 0]
                <=> [self::lireBase($a['date_heure'])->getTimestamp(), $a['id'] ?? 0];
        };

        // Prochain rendez-vous = le plus proche des rendez-vous confirmés à venir
        $prochainIdx = null;
        foreach ($rdvs as $i => $rdv) {
            if (($rdv['statut'] ?? '') === 'confirme' && self::lireBase($rdv['date_heure']) >= $now) {
                if ($prochainIdx === null
                    || self::lireBase($rdv['date_heure']) < self::lireBase($rdvs[$prochainIdx]['date_heure'])) {
                    $prochainIdx = $i;
                }
            }
        }

        $prochain = $prochainIdx === null ? [] : [$rdvs[$prochainIdx]];
        if ($prochainIdx !== null) {
            unset($rdvs[$prochainIdx]);
        }
        $reste = array_values($rdvs);
        usort($reste, $parDateDesc);
        $liste = array_merge($prochain, $reste);

        return [
            'page' => $page,
            'parPage' => self::PAR_PAGE,
            'total' => count($liste),
            'rendezVous' => array_slice($liste, ($page - 1) * self::PAR_PAGE, self::PAR_PAGE),
        ];
    }

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------
    private static function refus(int $status, string $erreur): array
    {
        return ['status' => $status, 'erreur' => $erreur];
    }

    private static function fuseau(): DateTimeZone
    {
        return new DateTimeZone(self::FUSEAU);
    }

    /** Date saisie « AAAA-MM-JJTHH:MM », stricte (refuse 2026-02-30). Retourne null si illisible. */
    private static function lireSaisie($valeur): ?DateTimeImmutable
    {
        if (!is_string($valeur)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $valeur, self::fuseau());
        if ($d === false || $d->format('Y-m-d\TH:i') !== $valeur) {
            return null;
        }
        return $d;
    }

    /** Date issue de la base (ou heure courante) : « 2026-11-10 09:00:00 », ou avec « T ». */
    private static function lireBase(string $valeur): DateTimeImmutable
    {
        return new DateTimeImmutable($valeur, self::fuseau());
    }
}