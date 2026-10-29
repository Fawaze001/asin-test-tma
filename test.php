<?php
require_once 'RdvManager.php';

function assertTest($condition, $message) {
    echo $condition ? "✅ REUSSITE : $message\n" : "❌ ECHEC : $message\n";
}

echo "=== DÉBUT DES TESTS AUTOMATISÉS TMA ===\n";

// TMA-101 : Rejet d'un rendez-vous le week-end
$res = RdvManager::validerCreation(['npi' => '0614253647', 'service' => 'PASSEPORT', 'dateHeure' => '2026-11-14T10:00'], [], '2026-11-01 08:00:00');
assertTest($res['status'] === 409 && str_contains($res['erreur'], 'week-end'), "TMA-101 : Rejet du samedi");

// TMA-102 : Rejet si le créneau comporte déjà 3 rendez-vous actifs
$creneauPlein = [
    ['date_heure' => '2026-11-10T09:00', 'statut' => 'confirme', 'npi' => '1111111111'],
    ['date_heure' => '2026-11-10T09:00', 'statut' => 'confirme', 'npi' => '2222222222'],
    ['date_heure' => '2026-11-10T09:00', 'statut' => 'confirme', 'npi' => '3333333333'],
];
$res = RdvManager::validerCreation(['npi' => '4444444444', 'service' => 'CNI', 'dateHeure' => '2026-11-10T09:00'], $creneauPlein, '2026-11-01 08:00:00');
assertTest($res['status'] === 409 && str_contains($res['erreur'], 'complet'), "TMA-102 : Blocage du 4ème RDV actif");


// TMA-103 : Gestion propre d'une lettre dans le NPI
$res = RdvManager::validerCreation(['npi' => '06142A3647', 'service' => 'CNI', 'dateHeure' => '2026-11-23T09:00'], [], '2026-11-01 08:00:00');
assertTest($res['status'] === 400 && str_contains($res['erreur'], '10 chiffres'), "TMA-103 : Rejet NPI alphanumérique");

// TMA-104 : Cas conforme - Annulation acceptée car effectuée 40h à l'avance
$rdv = ['date_heure' => '2026-11-19 10:00:00', 'statut' => 'confirme'];
$res = RdvManager::validerAnnulation($rdv, '2026-11-17 18:00:00');
assertTest($res['status'] === 200, "TMA-104 : Comportement Conforme (Annulation valide)");

// TMA-105 : Interdiction de modifier un rendez-vous déjà annulé
$rdvAnnule = ['statut' => 'annule'];
$res = RdvManager::validerChangementStatut($rdvAnnule, 'honore');
assertTest($res['status'] === 409 && str_contains($res['erreur'], 'clôturé'), "TMA-105 : Blocage réécriture statut clôturé");

// TMA-107 : Conforme - Blocage si l'usager a déjà un rendez-vous à venir
$rdvFutur = [['npi' => '0102030405', 'date_heure' => '2026-11-26 10:00:00', 'statut' => 'confirme']];
$res = RdvManager::validerCreation(['npi' => '0102030405', 'service' => 'PERMIS', 'dateHeure' => '2026-11-27T09:00'], $rdvFutur, '2026-11-16 08:00:00');
assertTest($res['status'] === 409 && str_contains($res['erreur'], 'à venir'), "TMA-107 : Comportement Conforme (RDV unique)");

echo "=== FIN DE LA SUITE DE TESTS ===\n";
