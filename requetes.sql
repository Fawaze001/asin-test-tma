-- 1. Les rendez-vous confirmés à venir pris par erreur un samedi ou un dimanche
SELECT * FROM rendez_vous 
WHERE statut = 'confirme' 
AND strftime('%w', date_heure) IN ('0', '6');

-- 2. Les créneaux surchargés qui comptent strictement plus de 3 rendez-vous non annulés
SELECT date_heure, COUNT(*) as total_actifs 
FROM rendez_vous 
WHERE statut <> 'annule' 
GROUP BY date_heure 
HAVING COUNT(*) > 3;

-- 3. Les rendez-vous passés anormalement du statut « annulé » à un autre statut
SELECT h.* FROM historique_statuts h
INNER JOIN rendez_vous r ON h.rendez_vous_id = r.id
WHERE h.ancien_statut = 'annule' AND h.nouveau_statut <> 'annule';
