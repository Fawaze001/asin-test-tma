<?php
declare(strict_types=1);

require_once __DIR__ . '/RdvManager.php';

$echecs = 0;
$total = 0;

function verifier(string $nom, array $attendu, array $obtenu): void
{
    global $echecs, $total;
    $total++;
    $ok = $obtenu['status'] === $attendu['status']
        && (!isset($attendu['erreur']) || ($obtenu['erreur'] ?? null) === $attendu['erreur']);
    if ($ok) {
        echo "✅ $nom\n";
    } else {
        $echecs++;
        echo "❌ $nom\n   attendu : " . json_encode($attendu, JSON_UNESCAPED_UNICODE)
            . "\n   obtenu  : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

function verifierVrai(string $nom, bool $condition, string $detail = ''): void
{
    global $echecs, $total;
    $total++;
    if ($condition) {
        echo "✅ $nom\n";
    } else {
        $echecs++;
        echo "❌ $nom $detail\n";
    }
}

// Messages exacts de l'énoncé (section 2)
const M_NPI      = 'Le NPI doit comporter exactement 10 chiffres.';
const M_SERVICE  = 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.';
const M_FORMAT   = 'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).';
const M_PASSE    = 'Impossible de prendre un rendez-vous dans le passé.';
const M_WEEKEND  = 'Le centre est fermé le week-end.';
const M_GRILLE   = 'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.';
const M_DEJA     = 'Vous avez déjà un rendez-vous à venir.';
const M_COMPLET  = 'Ce créneau est complet.';
const M_NONCONF  = 'Ce rendez-vous ne peut plus être annulé.';
const M_TARDIVE  = 'Annulation impossible moins de 24 h avant le rendez-vous.';
const M_STATUT   = 'Statut invalide : honore ou absent.';
const M_CLOTURE  = 'Ce rendez-vous est déjà clôturé.';

echo "=== TESTS AUTOMATISÉS TMA (traces du support rejouées) ===\n\n";

// ---------------------------------------------------------------------------
// TMA-101 — samedi 14/11/2026 : doit être refusé (l'appli renvoyait 201)
// ---------------------------------------------------------------------------
verifier('TMA-101 samedi 14/11/2026 refusé',
    ['status' => 409, 'erreur' => M_WEEKEND],
    RdvManager::validerCreation(['npi' => '0614253647', 'service' => 'PASSEPORT', 'dateHeure' => '2026-11-14T10:00'], [], '2026-11-09 23:59:11'));
verifier('TMA-101 dimanche 15/11/2026 refusé',
    ['status' => 409, 'erreur' => M_WEEKEND],
    RdvManager::validerCreation(['npi' => '0614253647', 'service' => 'PASSEPORT', 'dateHeure' => '2026-11-15T10:00'], [], '2026-11-09 23:59:11'));
verifier('TMA-101 vendredi 13/11/2026 accepté (contrôle)',
    ['status' => 201],
    RdvManager::validerCreation(['npi' => '0614253647', 'service' => 'PASSEPORT', 'dateHeure' => '2026-11-13T10:00'], [], '2026-11-09 23:59:11'));

// ---------------------------------------------------------------------------
// TMA-102 — créneau du 10/11/2026 à 09:00 : 632, 644, 681 déjà là, 697 refusé.
// L'historique est au FORMAT DE LA BASE (« Y-m-d H:i:s »), la saisie au format « T ».
// ---------------------------------------------------------------------------
$creneau = [
    ['id' => 632, 'npi' => '0638958971', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'confirme'],
    ['id' => 644, 'npi' => '0671968463', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'confirme'],
    ['id' => 681, 'npi' => '0609394161', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'confirme'],
];
$saisie697 = ['npi' => '0620214467', 'service' => 'PERMIS', 'dateHeure' => '2026-11-10T09:00'];
verifier('TMA-102 le 4e usager (n°697) est refusé : créneau complet',
    ['status' => 409, 'erreur' => M_COMPLET],
    RdvManager::validerCreation($saisie697, $creneau, '2026-11-04 23:43:37'));

$creneauAvecAnnule = $creneau;
$creneauAvecAnnule[1]['statut'] = 'annule';
verifier('TMA-102 un rendez-vous annulé libère sa place (2 actifs + 1 annulé => accepté)',
    ['status' => 201],
    RdvManager::validerCreation($saisie697, $creneauAvecAnnule, '2026-11-04 23:43:37'));

verifier('TMA-102 le 3e usager est accepté (2 actifs => 201)',
    ['status' => 201],
    RdvManager::validerCreation($saisie697, array_slice($creneau, 0, 2), '2026-11-04 23:43:37'));

// ---------------------------------------------------------------------------
// TMA-103 — NPI « 06142A3647 » : 400 propre, jamais d'exception
// ---------------------------------------------------------------------------
verifier('TMA-103 NPI avec une lettre => 400',
    ['status' => 400, 'erreur' => M_NPI],
    RdvManager::validerCreation(['npi' => '06142A3647', 'service' => 'CNI', 'dateHeure' => '2026-11-23T09:00'], [], '2026-11-01 08:00:00'));
verifier('TMA-103 NPI de 11 chiffres (cf. rdv n°459 de l\'export) => 400',
    ['status' => 400, 'erreur' => M_NPI],
    RdvManager::validerCreation(['npi' => '01234567890', 'service' => 'CNI', 'dateHeure' => '2026-11-23T09:00'], [], '2026-11-01 08:00:00'));
verifier('TMA-103 NPI de 9 chiffres => 400',
    ['status' => 400, 'erreur' => M_NPI],
    RdvManager::validerCreation(['npi' => '061425364', 'service' => 'CNI', 'dateHeure' => '2026-11-23T09:00'], [], '2026-11-01 08:00:00'));
verifier('TMA-103 NPI avec retour à la ligne final => 400',
    ['status' => 400, 'erreur' => M_NPI],
    RdvManager::validerCreation(['npi' => "0614253647\n", 'service' => 'CNI', 'dateHeure' => '2026-11-23T09:00'], [], '2026-11-01 08:00:00'));

// ---------------------------------------------------------------------------
// TMA-104 — rdv n°709 du 19/11 10:00, annulation le 17/11 à 18:00 (40 h) : DOIT être acceptée
// ---------------------------------------------------------------------------
$rdv709 = ['id' => 709, 'npi' => '0339205992', 'date_heure' => '2026-11-19 10:00:00', 'statut' => 'confirme'];
verifier('TMA-104 annulation à 40 h => acceptée (l\'appli refusait à tort)',
    ['status' => 200],
    RdvManager::validerAnnulation($rdv709, '2026-11-17 18:00:00'));
verifier('TMA-104 annulation à exactement 24 h => acceptée',
    ['status' => 200],
    RdvManager::validerAnnulation($rdv709, '2026-11-18 10:00:00'));
verifier('TMA-104 annulation à 23 h 59 => refusée',
    ['status' => 409, 'erreur' => M_TARDIVE],
    RdvManager::validerAnnulation($rdv709, '2026-11-18 10:01:00'));
verifier('TMA-104 annulation d\'un rendez-vous non confirmé => refusée',
    ['status' => 409, 'erreur' => M_NONCONF],
    RdvManager::validerAnnulation(['date_heure' => '2026-11-19 10:00:00', 'statut' => 'honore'], '2026-11-17 18:00:00'));

// ---------------------------------------------------------------------------
// TMA-105 — rdv n°292 annulé : le passer à « honore » doit être refusé
// ---------------------------------------------------------------------------
verifier('TMA-105 annule => honore refusé (déjà clôturé)',
    ['status' => 409, 'erreur' => M_CLOTURE],
    RdvManager::validerChangementStatut(['id' => 292, 'statut' => 'annule'], 'honore'));
verifier('TMA-105 honore => absent refusé (déjà clôturé)',
    ['status' => 409, 'erreur' => M_CLOTURE],
    RdvManager::validerChangementStatut(['id' => 1, 'statut' => 'honore'], 'absent'));
verifier('TMA-105 confirme => honore accepté (contrôle)',
    ['status' => 200],
    RdvManager::validerChangementStatut(['id' => 800, 'statut' => 'confirme'], 'honore'));
verifier('TMA-105 statut demandé inconnu => 400',
    ['status' => 400, 'erreur' => M_STATUT],
    RdvManager::validerChangementStatut(['id' => 800, 'statut' => 'confirme'], 'annule'));

// ---------------------------------------------------------------------------
// TMA-106 — usager 0203040506 : 13 rendez-vous, prochain (n°826 du 02/12) en tête de la page 1
// Données réelles de l'export, au format de la base.
// ---------------------------------------------------------------------------
$usager = [
    ['id' => 1,   'npi' => '0203040506', 'date_heure' => '2026-01-05 08:30:00', 'statut' => 'honore'],
    ['id' => 2,   'npi' => '0203040506', 'date_heure' => '2026-02-12 10:00:00', 'statut' => 'honore'],
    ['id' => 3,   'npi' => '0203040506', 'date_heure' => '2026-03-03 14:30:00', 'statut' => 'absent'],
    ['id' => 4,   'npi' => '0203040506', 'date_heure' => '2026-04-21 09:00:00', 'statut' => 'honore'],
    ['id' => 5,   'npi' => '0203040506', 'date_heure' => '2026-05-08 11:30:00', 'statut' => 'honore'],
    ['id' => 6,   'npi' => '0203040506', 'date_heure' => '2026-05-19 15:00:00', 'statut' => 'absent'],
    ['id' => 7,   'npi' => '0203040506', 'date_heure' => '2026-06-02 08:00:00', 'statut' => 'honore'],
    ['id' => 8,   'npi' => '0203040506', 'date_heure' => '2026-06-16 13:30:00', 'statut' => 'honore'],
    ['id' => 9,   'npi' => '0203040506', 'date_heure' => '2026-07-07 10:30:00', 'statut' => 'honore'],
    ['id' => 10,  'npi' => '0203040506', 'date_heure' => '2026-07-28 09:30:00', 'statut' => 'absent'],
    ['id' => 11,  'npi' => '0203040506', 'date_heure' => '2026-08-11 14:00:00', 'statut' => 'honore'],
    ['id' => 259, 'npi' => '0203040506', 'date_heure' => '2026-09-25 11:00:00', 'statut' => 'honore'],
    ['id' => 826, 'npi' => '0203040506', 'date_heure' => '2026-12-02 08:30:00', 'statut' => 'confirme'],
];
shuffle($usager); // l'ordre d'entrée ne doit pas compter
$p1 = RdvManager::extraireListeUsager($usager, '2026-11-20 18:00:00', 1);
$p2 = RdvManager::extraireListeUsager($usager, '2026-11-20 18:00:00', 2);
$idsP1 = array_column($p1['rendezVous'], 'id');
$idsP2 = array_column($p2['rendezVous'], 'id');

verifierVrai('TMA-106 total = 13, 10 par page', $p1['total'] === 13 && $p1['parPage'] === 10 && count($idsP1) === 10);
verifierVrai('TMA-106 le rdv du 02/12 (n°826) est le premier de la page 1', ($idsP1[0] ?? null) === 826, json_encode($idsP1));
verifierVrai('TMA-106 puis du plus récent au plus ancien',
    $idsP1 === [826, 259, 11, 10, 9, 8, 7, 6, 5, 4] && $idsP2 === [3, 2, 1],
    json_encode([$idsP1, $idsP2]));

// ---------------------------------------------------------------------------
// TMA-107 — CONFORME : 0102030405 a le rdv n°800 (26/11) ; un PERMIS le 27/11 est refusé
// ---------------------------------------------------------------------------
$h107 = [['id' => 800, 'npi' => '0102030405', 'service' => 'PASSEPORT', 'date_heure' => '2026-11-26 10:00:00', 'statut' => 'confirme']];
$saisie107 = ['npi' => '0102030405', 'service' => 'PERMIS', 'dateHeure' => '2026-11-27T09:00'];
verifier('TMA-107 (conforme) un 2e rdv à venir, autre service, est refusé',
    ['status' => 409, 'erreur' => M_DEJA],
    RdvManager::validerCreation($saisie107, $h107, '2026-11-16 08:00:00'));

$h107annule = $h107;
$h107annule[0]['statut'] = 'annule';
verifier('TMA-107 si le rdv précédent est annulé, un nouveau est accepté (contrôle)',
    ['status' => 201],
    RdvManager::validerCreation($saisie107, $h107annule, '2026-11-16 08:00:00'));
verifier('TMA-107 un autre usager n\'est pas bloqué (contrôle)',
    ['status' => 201],
    RdvManager::validerCreation(['npi' => '0999999999'] + $saisie107, $h107, '2026-11-16 08:00:00'));

// ---------------------------------------------------------------------------
// BONUS — anomalies de l'export non signalées (créneaux hors grille, dates impossibles)
// ---------------------------------------------------------------------------
foreach (['16:00', '16:30', '07:30', '08:15', '12:45'] as $heure) {
    verifier("BONUS créneau $heure refusé (hors grille)",
        ['status' => 409, 'erreur' => M_GRILLE],
        RdvManager::validerCreation(['npi' => '0614253647', 'service' => 'CNI', 'dateHeure' => "2026-12-08T$heure"], [], '2026-11-20 18:00:00'));
}
foreach (['08:00', '15:30'] as $heure) {
    verifier("BONUS créneau $heure accepté (bornes de la grille)",
        ['status' => 201],
        RdvManager::validerCreation(['npi' => '0614253647', 'service' => 'CNI', 'dateHeure' => "2026-12-08T$heure"], [], '2026-11-20 18:00:00'));
}

// ---------------------------------------------------------------------------
// Autres refus prévus par l'énoncé
// ---------------------------------------------------------------------------
verifier('Service inconnu => 400',
    ['status' => 400, 'erreur' => M_SERVICE],
    RdvManager::validerCreation(['npi' => '0614253647', 'service' => 'VISA', 'dateHeure' => '2026-12-08T10:00'], [], '2026-11-20 18:00:00'));
verifier('Date illisible => 400',
    ['status' => 400, 'erreur' => M_FORMAT],
    RdvManager::validerCreation(['npi' => '0614253647', 'service' => 'CNI', 'dateHeure' => '08/12/2026 10:00'], [], '2026-11-20 18:00:00'));
verifier('Date inexistante (30 février) => 400',
    ['status' => 400, 'erreur' => M_FORMAT],
    RdvManager::validerCreation(['npi' => '0614253647', 'service' => 'CNI', 'dateHeure' => '2026-02-30T10:00'], [], '2026-01-05 08:00:00'));
verifier('Date passée => 409',
    ['status' => 409, 'erreur' => M_PASSE],
    RdvManager::validerCreation(['npi' => '0614253647', 'service' => 'CNI', 'dateHeure' => '2026-11-19T10:00'], [], '2026-11-20 18:00:00'));

echo "\n=== " . ($total - $echecs) . "/$total tests réussis ===\n";
exit($echecs === 0 ? 0 : 1);