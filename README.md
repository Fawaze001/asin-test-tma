# Étude de cas TMA — Prise de rendez-vous au guichet

Le code source de l'application n'étant pas fourni, chaque diagnostic est une **hypothèse déduite des règles de gestion, des traces du support et de l'export SQL**. Le module `RdvManager.php` réécrit les règles de la section 2 de l'énoncé, sans base de données ni framework.

## Contenu du dépôt

| Fichier | Rôle |
| --- | --- |
| `RdvManager.php` | Module de règles de gestion (création, annulation, changement de statut, liste paginée) |
| `test.php` | Tests automatisés, un groupe par ticket, qui rejouent les traces du support |
| `requetes.sql` | Requêtes de mesure d'impact et requêtes bonus |

## Lancer les tests

Prérequis : PHP 8.0 ou plus.

```bash
php test.php
```

Le script affiche ✅ / ❌ pour chaque cas et renvoie un code de sortie non nul en cas d'échec (35 tests, tous réussis à la livraison).

Pour les requêtes d'impact (SQLite) :

```bash
sqlite3 rdv.db < base_rendez_vous.sql
sqlite3 -header -column rdv.db < requetes.sql
```

## Principes du module

- L'heure courante est toujours un paramètre : rien ne dépend de l'horloge du serveur.
- Toutes les dates sont lues en `Africa/Porto-Novo` (GMT+1).
- Les dates de la base (`2026-11-10 09:00:00`) et de la saisie (`2026-11-10T09:00`) sont converties en `DateTimeImmutable` avant toute comparaison. Aucune comparaison de chaînes.
- Les messages et codes HTTP sont ceux du tableau de la section 2.

## Qualification et diagnostic des tickets

### TMA-101 — Rendez-vous un samedi : 🔴 Anomalie
- **Règle** : « Le centre reçoit du lundi au vendredi ».
- **Trace** : le 14/11/2026 est un samedi et l'API a répondu 201.
- **Cause probable** : contrôle absent. L'application valide le format et la grille horaire, mais jamais le jour de la semaine.
- **Correction** : refus 409 « Le centre est fermé le week-end. » si le jour ISO est 6 ou 7.
- **Test** : `TMA-101 samedi 14/11/2026 refusé`, plus le dimanche, plus un vendredi accepté.

### TMA-102 — Créneau avec 4 usagers : 🔴 Anomalie
- **Règle** : « Un créneau accueille au plus 3 usagers. Un rendez-vous annulé libère sa place. »
- **Trace** : les n° 632, 644 et 681 occupaient déjà le 10/11 à 09:00 (tous non annulés), et le n° 697 a reçu un 201.
- **Cause probable** : erreur de borne. L'application refuse seulement quand le créneau compte *plus de* 3 rendez-vous (`> 3`), alors qu'il faut refuser dès 3 (`>= 3`). Les 4 rendez-vous ont été créés les 28/10, 30/10, 02/11 et 04/11 : ce n'est pas un accès concurrent. Le filtre sur `annule` n'est pas en cause, aucun des quatre n'est annulé.
- **Correction** : on compte les rendez-vous non annulés du créneau et on refuse dès que le compte atteint 3. La comparaison se fait sur l'instant, car l'historique (`Y-m-d H:i:s`) et la saisie (`Y-m-d\TH:i`) n'ont pas le même format.
- **Test** : la trace du 697 rejouée avec un historique au format de la base ; un rendez-vous annulé libère sa place ; 2 occupants donnent un 201.

### TMA-103 — NPI avec une lettre, erreur 500 : 🔴 Anomalie
- **Règle** : « NPI composé d'exactement 10 chiffres », et une saisie invalide est refusée avec un message, jamais par une erreur interne.
- **Trace** : `06142A3647` fait 10 caractères.
- **Cause probable** : le contrôle porte sur la longueur seulement. La valeur passe, puis une conversion numérique ou une requête échoue plus loin, d'où l'exception non gérée.
- **Correction** : expression régulière `^[0-9]{10}\z` (`\z` et non `$`, qui accepterait un retour à la ligne final), avec renvoi de 400.
- **Test** : lettre, 9 chiffres, 11 chiffres, retour à la ligne final.

### TMA-104 — Annulation refusée à 40 h : 🔴 Anomalie (et non un cas conforme)
- **Règle** : « L'usager peut annuler son rendez-vous jusqu'à 24 heures avant l'heure prévue. »
- **Trace** : le 17/11 à 18:00, pour un rendez-vous le 19/11 à 10:00, il reste 40 h. L'annulation devait être acceptée, or l'application a répondu 409.
- **Cause probable** : erreur de calcul de l'écart. L'application lit la composante « heures » de l'intervalle au lieu du total : ici 1 jour et 16 h donnent « 16 », inférieur à 24, d'où le refus. C'est un piège classique (`DateInterval->h` en PHP, `timedelta.seconds` en Python).
- **Correction** : écart total en secondes, annulation refusée seulement s'il est strictement inférieur à 24 h.
- **Test** : 40 h acceptée, exactement 24 h acceptée, 23 h 59 refusée, rendez-vous non confirmé refusé.

### TMA-105 — Statut d'un rendez-vous annulé écrasé : 🔴 Anomalie
- **Règle** : « Ces trois statuts sont définitifs. »
- **Trace** : le n° 292 était `annule` et le PATCH `honore` a répondu 200.
- **Cause probable** : aucun contrôle du statut d'origine, donc pas de machine à états.
- **Correction** : 400 si le statut demandé n'est pas `honore` ou `absent` ; 409 « déjà clôturé » si le rendez-vous est `honore`, `absent` ou `annule`.
- **Test** : `annule` → `honore` refusé, `honore` → `absent` refusé, `confirme` → `honore` accepté, statut inconnu refusé.
- **Impact** : 4 rendez-vous touchés dans l'export (voir requête 3).

### TMA-106 — Prochain rendez-vous absent de la page 1 : 🔴 Anomalie
- **Règle** : « Du plus récent au plus ancien, le prochain rendez-vous en tête, par pages de 10. »
- **Trace** : les dates renvoyées sont 28/07, 25/09, 21/04, 19/05, 16/06, 12/02, 11/08, 08/05, 07/07, soit les jours **28, 25, 21, 19, 16, 12, 11, 08, 07 en ordre décroissant**.
- **Cause probable** : le tri porte sur la date *formatée* `JJ/MM/AAAA` (une chaîne) et non sur la date réelle. Le 02/12 (jour « 02 ») arrive donc en fin de liste, sur la page 2.
- **Correction** : tri sur la date réelle, avec le prochain rendez-vous (confirmé, à venir) placé en tête, puis les autres du plus récent au plus ancien, avec départage par `id`. Un numéro de page inférieur à 1 est ramené à 1.
- **Test** : les 13 rendez-vous réels de l'usager 0203040506, mélangés en entrée. Le n° 826 (02/12) est en tête de la page 1, la page 1 en contient 10 et la page 2 en contient 3.

### TMA-107 — Un seul rendez-vous à venir : ✅ Comportement conforme
- **Règle** : « Un usager ne peut avoir qu'un seul rendez-vous à venir, tous services confondus. »
- **Trace** : l'usager 0102030405 a le rendez-vous n° 800 (PASSEPORT, le 26/11). Le refus d'un PERMIS le 27/11 est donc le comportement attendu.
- **Test** : le refus (409 « Vous avez déjà un rendez-vous à venir. ») est rejoué à l'identique, puis deux contrôles : si le rendez-vous précédent est annulé, l'usager est libéré ; un autre usager n'est pas bloqué.

## Résultats des requêtes d'impact (`requetes.sql`, export du 20/11/2026 à 18 h)

| # | Requête | Résultat |
| --- | --- | --- |
| 1 | Confirmés, à venir, pris un samedi ou un dimanche | **5** : n° 680, 730, 834, 857, 874 (usagers à prévenir) |
| 1 bis | Idem mais déjà passés à l'heure de l'export | 3 : n° 475, 715, 742, jamais clôturés |
| 2 | Créneaux avec plus de 3 rendez-vous non annulés | **3** : 22/10 à 10 h 30 (502, 511, 517, 519) ; 10/11 à 09 h (632, 644, 681, 697) ; **24/11 à 14 h (728, 750, 763, 783), encore à venir** |
| 3 | Passés de « annulé » à un autre statut | **4** : n° 208, 231, 262 (annulé → absent), 292 |

## Bonus — anomalies que personne n'avait signalées

| # | Constat | Détail |
| --- | --- | --- |
| B1 | Rendez-vous hors grille (08:00–15:30) | n° 490 (27/10 à 16:00), n° 698 (13/11 à 16:30), n° 842 (08/12 à 16:00, **confirmé et à venir**) |
| B2 | NPI qui n'ont pas 10 chiffres | n° 459 (11 chiffres), n° 642 (12 chiffres) |
| B3 | Rendez-vous « confirmés » à la date dépassée | n° 475, 715, 742 : tous des week-ends, jamais clôturés |
| B4 | Règle « un seul rendez-vous à venir » violée dans les données | 46 paires, 42 usagers, 44 rendez-vous ajoutés alors qu'un autre était déjà à venir (ex. NPI 0819331908 : 3 rendez-vous simultanés) |

**Lecture** : B1 et B2 montrent que la grille horaire et le format du NPI n'étaient pas contrôlés à l'écriture en production. B3 recoupe le ticket TMA-101. B4 est à prendre avec prudence : l'export peut contenir du bruit de génération, mais la règle de la section 2 n'y est pas respectée.

**Correction dans le module** : `validerCreation` rejette désormais les créneaux hors grille (tests « BONUS »), les NPI qui n'ont pas 10 chiffres (TMA-103), et bloque un second rendez-vous à venir (TMA-107). Il ne corrige pas les données déjà en base : un traitement de reprise reste à planifier.

## Limites

- Les causes sont des hypothèses : sans le code de l'application, elles ne peuvent pas être prouvées.
- Les règles ne couvrent ni les jours fériés ni la concurrence d'accès (deux réservations simultanées sur le dernier créneau libre) : cela relève d'une contrainte en base ou d'un verrou, hors du périmètre du module.
- Cas non précisé par l'énoncé : un statut demandé invalide sur un rendez-vous déjà clôturé. Le module renvoie 400 (validation de la saisie avant l'état).