<?php

class RdvManager
{
    // Valider une prise de rendez-vous (TMA-101, TMA-102, TMA-103, TMA-107)
    public static function validerCreation(array $donnees, array $historiqueRdv, string $heureCourante): array
    {
        $npi = $donnees['npi'] ?? '';
        $service = $donnees['service'] ?? '';
        $dateHeureStr = $donnees['dateHeure'] ?? '';

        // 1. Validation du NPI (TMA-103)
        if (!preg_match('/^[0-9]{10}$/', $npi)) {
            return ['status' => 400, 'erreur' => 'Le NPI doit comporter exactement 10 chiffres.'];
        }

        // 2. Validation du Service
        if (!in_array($service, ['PASSEPORT', 'CNI', 'PERMIS'])) {
            return ['status' => 400, 'erreur' => 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.'];
        }

        // 3. Validation du format Date/Heure
        $dateHeure = DateTime::createFromFormat('Y-m-d\TH:i', $dateHeureStr);
        if (!$dateHeure) {
            return ['status' => 400, 'erreur' => 'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).'];
        }

        $now = new DateTime($heureCourante);
        if ($dateHeure < $now) {
            return ['status' => 409, 'erreur' => 'Impossible de prendre un rendez-vous dans le passé.'];
        }

        // 4. Validation Week-end (TMA-101)
        $jourSemaine = (int)$dateHeure->format('N'); // 6 = Samedi, 7 = Dimanche
        if ($jourSemaine >= 6) {
            return ['status' => 409, 'erreur' => 'Le centre est fermé le week-end.'];
        }

        // 5. Validation Grille Horaire
        $heureMinute = $dateHeure->format('H:i');
        $minutes = (int)$dateHeure->format('i');
        if ($heureMinute < '08:00' || $heureMinute > '15:30' || ($minutes !== 0 && $minutes !== 30)) {
            return ['status' => 409, 'erreur' => 'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.'];
        }

        // 6. Validation : Un seul rendez-vous à venir (TMA-107)
        foreach ($historiqueRdv as $rdv) {
            if ($rdv['npi'] === $npi) {
                $rdvDate = new DateTime($rdv['date_heure']);
                if ($rdvDate >= $now && !in_array($rdv['statut'], ['honore', 'absent', 'annule'])) {
                    return ['status' => 409, 'erreur' => 'Vous avez déjà un rendez-vous à venir.'];
                }
            }
        }

        // 7. Validation : Créneau complet - Max 3 rdvs actifs (TMA-102)
        $count = 0;
        foreach ($historiqueRdv as $rdv) {
            if ($rdv['date_heure'] === $dateHeureStr && $rdv['statut'] !== 'annule') {
                $count++;
            }
        }
        if ($count >= 3) {
            return ['status' => 409, 'erreur' => 'Ce créneau est complet.'];
        }

        return ['status' => 201, 'message' => 'Rendez-vous validé avec succès.'];
    }

    // Valider une annulation par l'usager (TMA-104)
    public static function validerAnnulation(array $rdv, string $heureCourante): array
    {
        if ($rdv['statut'] !== 'confirme') {
            return ['status' => 409, 'erreur' => 'Ce rendez-vous ne peut plus être annulé.'];
        }

        $rdvDate = new DateTime($rdv['date_heure']);
        $now = new DateTime($heureCourante);
        
        $diffHours = ($rdvDate->getTimestamp() - $now->getTimestamp()) / 3600;
        if ($diffHours < 24) {
            return ['status' => 409, 'erreur' => 'Annulation impossible moins de 24 h avant le rendez-vous.'];
        }

        return ['status' => 200, 'message' => 'Annulation acceptée.'];
    }

    // Mettre à jour le statut par un agent (TMA-105)
    public static function validerChangementStatut(array $rdv, string $nouveauStatut): array
    {
        if (in_array($rdv['statut'], ['honore', 'absent', 'annule'])) {
            return ['status' => 409, 'erreur' => 'Ce rendez-vous est déjà clôturé.'];
        }

        if (!in_array($nouveauStatut, ['honore', 'absent'])) {
            return ['status' => 400, 'erreur' => 'Statut invalide : honore ou absent.'];
        }

        return ['status' => 200, 'message' => 'Statut mis à jour.'];
    }

    // Trier et paginer les rendez-vous d'un usager (TMA-106)
    public static function extraireListeUsager(array $rdvs, string $heureCourante, int $page = 1): array
    {
        $now = new DateTime($heureCourante);

        usort($rdvs, function ($a, $b) use ($now) {
            $dateA = new DateTime($a['date_heure']);
            $dateB = new DateTime($b['date_heure']);

            $isAAVenir = ($dateA >= $now && !in_array($a['statut'], ['honore', 'absent', 'annule']));
            $isBAVenir = ($dateB >= $now && !in_array($b['statut'], ['honore', 'absent', 'annule']));

            if ($isAAVenir && !$isBAVenir) return -1;
            if (!$isAAVenir && $isBAVenir) return 1;

            return $dateB->getTimestamp() <=> $dateA->getTimestamp();
        });

        $parPage = 10;
        $total = count($rdvs);
        $offset = ($page - 1) * $parPage;
        $pagines = array_slice($rdvs, $offset, $parPage);

        return [
            'page' => $page,
            'parPage' => $parPage,
            'total' => $total,
            'rendezVous' => $pagines
        ];
    }
}
