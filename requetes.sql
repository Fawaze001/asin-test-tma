-- requetes.sql : mesure d'impact sur l'export base_rendez_vous.sql
-- Export réalisé le vendredi 20 novembre 2026 à 18 h 00 (heure du Bénin).
-- Écrit pour SQLite (sqlite3 rdv.db < base_rendez_vous.sql). Les équivalents
-- PostgreSQL / MySQL des fonctions de date sont donnés en commentaire.

-- ============================================================================
-- 1. Rendez-vous CONFIRMÉS, À VENIR, pris un samedi ou un dimanche
--    -> usagers à prévenir (le centre est fermé).
--    « À venir » = postérieur à l'heure de l'export (20/11/2026 18:00).
--    SQLite     : strftime('%w', date_heure) IN ('0','6')   (0 = dimanche)
--    PostgreSQL : EXTRACT(DOW FROM date_heure) IN (0, 6)
--    MySQL      : DAYOFWEEK(date_heure) IN (1, 7)
-- ============================================================================
SELECT id, npi, service, date_heure
FROM rendez_vous
WHERE statut = 'confirme'
  AND date_heure > '2026-11-20 18:00:00'
  AND strftime('%w', date_heure) IN ('0', '6')
ORDER BY date_heure, id;
-- Résultat : 5 rendez-vous (680, 730, 834, 857, 874).

-- 1 bis. Même critère mais déjà passés à l'heure de l'export : plus à prévenir,
--        mais jamais clôturés par un agent (ex. n°742 du ticket TMA-101).
SELECT id, npi, service, date_heure, statut
FROM rendez_vous
WHERE statut = 'confirme'
  AND date_heure <= '2026-11-20 18:00:00'
  AND strftime('%w', date_heure) IN ('0', '6')
ORDER BY date_heure, id;
-- Résultat : 3 rendez-vous (475, 715, 742).

-- ============================================================================
-- 2. Créneaux qui comptent plus de 3 rendez-vous NON annulés
-- ============================================================================
SELECT date_heure,
       COUNT(*)            AS nb_actifs,
       GROUP_CONCAT(id)    AS rendez_vous      -- PostgreSQL : STRING_AGG(id::text, ',')
FROM rendez_vous
WHERE statut <> 'annule'
GROUP BY date_heure
HAVING COUNT(*) > 3
ORDER BY date_heure;
-- Résultat : 3 créneaux
--   2026-10-22 10:30 -> 502, 511, 517, 519 (passé, tous honorés)
--   2026-11-10 09:00 -> 632, 644, 681, 697 (passé, ticket TMA-102)
--   2026-11-24 14:00 -> 728, 750, 763, 783 (À VENIR, tous confirmés : à traiter maintenant)

-- ============================================================================
-- 3. Rendez-vous passés du statut « annulé » à un autre statut (anomalie TMA-105)
-- ============================================================================
SELECT h.id AS id_historique, h.rendez_vous_id, r.npi, r.date_heure AS date_rdv,
       h.ancien_statut, h.nouveau_statut, h.modifie_le, h.modifie_par
FROM historique_statuts h
JOIN rendez_vous r ON r.id = h.rendez_vous_id
WHERE h.ancien_statut = 'annule'
  AND h.nouveau_statut <> 'annule'
ORDER BY h.modifie_le;
-- Résultat : 4 rendez-vous (208, 231, 262, 292), modifiés par AG-01, AG-02, AG-03, AG-04.

-- ============================================================================
-- BONUS : anomalies non signalées dans les tickets
-- ============================================================================

-- B1. Rendez-vous hors grille horaire (08:00 -> 15:30, toutes les 30 min)
SELECT id, npi, date_heure, statut
FROM rendez_vous
WHERE SUBSTR(date_heure, 12, 5) < '08:00'
   OR SUBSTR(date_heure, 12, 5) > '15:30'
   OR SUBSTR(date_heure, 15, 2) NOT IN ('00', '30')
ORDER BY date_heure;
-- Résultat : 3 (490 le 27/10 16:00, 698 le 13/11 16:30, 842 le 08/12 16:00 confirmé, à venir).

-- B2. NPI qui ne comporte pas exactement 10 chiffres
SELECT id, npi, LENGTH(npi) AS longueur, date_heure, statut
FROM rendez_vous
WHERE LENGTH(npi) <> 10
   OR npi GLOB '*[^0-9]*'
ORDER BY id;
-- Résultat : 2 (459 : 11 chiffres ; 642 : 12 chiffres).

-- B3. Rendez-vous « confirmés » dont la date est passée (jamais clôturés par un agent)
SELECT id, npi, service, date_heure
FROM rendez_vous
WHERE statut = 'confirme'
  AND date_heure < '2026-11-20 18:00:00'
ORDER BY date_heure;
-- Résultat : 3 (475, 715, 742) : tous des week-ends.

-- B4. Règle « un seul rendez-vous à venir » (TMA-107) violée dans les données :
--     le rendez-vous b a été créé alors que le rendez-vous a, du même usager,
--     n'avait pas encore eu lieu (a et b non annulés aujourd'hui).
SELECT a.npi, a.id AS rdv_deja_a_venir, a.date_heure AS date_a,
       b.id AS rdv_ajoute, b.cree_le AS cree_le_b, b.date_heure AS date_b
FROM rendez_vous a
JOIN rendez_vous b ON b.npi = a.npi
                  AND b.id <> a.id
                  AND b.cree_le > a.cree_le
                  AND a.date_heure > b.cree_le
WHERE a.statut <> 'annule' AND b.statut <> 'annule'
ORDER BY a.npi, b.cree_le;
-- Résultat : voir README (nombre de paires et d'usagers concernés).