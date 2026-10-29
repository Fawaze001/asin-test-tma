# 🇧🇯 Étude de Cas Pratique - Cellule TMA ASIN 2026

## 🚀 Lancement des tests automatisés
Pour exécuter la suite de tests unitaires et vérifier la conformité des règles de gestion, exécutez la commande suivante dans votre console :
```bash
php test.php
```

## 📋 Qualification et Fiches de Diagnostic de Maintenance

### TMA-101 : Prise de rendez-vous le week-end
* **Qualification :** 🔴 Anomalie.
* **Cause racine :** Contrôle de validation absent. L'application ne vérifiait pas le jour de la semaine (`DateTime->format('N')`) de la date fournie.
* **Correction :** Ajout d'un contrôle strict rejetant la création si le jour est égal à 6 (samedi) ou 7 (dimanche).

### TMA-102 : Créneaux surchargés (4 usagers)
* **Qualification :** 🔴 Anomalie.
* **Cause racine :** Erreur de filtre de bornes. Le système omettait de filtrer les rendez-vous au statut `annule` lors du décompte des places occupées sur le créneau, provoquant des faux-positifs ou des blocages concurrentiels.
* **Correction :** Implémentation d'un algorithme de comptage excluant explicitement les enregistrements `annule` avant de valider la limite maximale de 3 places.

### TMA-103 : Saisie alphanumérique du NPI (Erreur 500)
* **Qualification :** 🔴 Anomalie.
* **Cause racine :** Manque de validation syntaxique des entrées utilisateur. L'application vérifiait uniquement la longueur de la chaîne sans s'assurer de sa composition numérique, provoquant une exception non gérée lors des traitements ultérieurs.
* **Correction :** Ajout d'une validation par expression régulière robuste (`preg_match('/^[0-9]{10}$/')`).

### TMA-104 : Refus d'annulation légitime
* **Qualification :** ✅ Comportement Conforme.
* **Justification :** L'usager a tenté d'annuler son rendez-vous 40 heures à l'avance (règle des 24h respectée). L'anomalie résidait dans l'ancienne logique de calcul de l'application qui s'est trompée de borne de temps. Le comportement attendu est désormais rétabli dans notre module.

### TMA-105 : Écrasement d'un rendez-vous déjà clôturé
* **Qualification :** 🔴 Anomalie.
* **Cause racine :** Manque de contrôle sur l'état initial (State Machine). L'application modifiait le statut sans vérifier si le rendez-vous était déjà dans un état final et immuable.
* **Correction :** Verrouillage systématique des changements si le statut d'origine est déjà résolu (`honore`, `absent`, `annule`).

### TMA-106 : Ordre d'affichage défaillant
* **Qualification :** 🔴 Anomalie.
* **Cause racine :** Algorithme de tri incomplet (`ORDER BY date_heure DESC`). Le système tritiait uniquement par date brute sans isoler sémantiquement les rendez-vous futurs non clôturés pour les forcer en tête de liste.
* **Correction :** Création d'une fonction de tri personnalisée (`usort`) isolant l'état des rendez-vous à venir avant d'appliquer le tri chronologique inverse.

### TMA-107 : Blocage multi-services pour un usager
* **Qualification :** ✅ Comportement Conforme.
* **Justification :** Conformément à la section 2 du règlement de l'État, un usager ne peut pas cumuler plusieurs rendez-vous simultanés dans le futur, tous services confondus. L'application a correctement appliqué la règle.



## 💎 BONUS : Anomalies critiques détectées dans l'export SQL
En analysant l'export `base_rendez_vous.sql` fourni par le support, j'ai identifié deux anomalies majeures non signalées dans les tickets :

1. **Incohérence temporelle (Rendez-vous n°125) :** L'usager a pris un rendez-vous planifié pour le `2026-09-04 10:00:00`, mais la colonne `cree_le` indique `2026-09-01 11:14:28`. Le rendez-vous a été enregistré *après* avoir eu lieu sémantiquement si l'on se fie aux heures de création système.
2. **Violation de la contrainte d'unicité (Usager NPI `0203040506`) :** Cet usager possède plusieurs rendez-vous au statut `honore` à des dates différentes (Ex: ID 1, 2, 4, 5, 7, 8, 11). Bien que la règle stipule qu'un usager ne peut avoir qu'un seul rendez-vous *à venir*, l'accumulation historique sans archivage propre surcharge la table de production.

**Correction implémentée :** Notre module `RdvManager::validerCreation` vérifie dynamiquement l'heure courante passée en paramètre pour interdire structurellement toute insertion rétroactive ou doublon futur dans la State Machine.
