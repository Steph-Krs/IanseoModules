# Journal des versions — REPARTITION_EPREUVES

## 0.2.3 — 2026-11-16

Page des crédits ajoutée (auteurs et contact du module).

## 0.2.2 — 2026-08-04

Correctif critique d'installation (issue « les tables ne s'installent pas », Error 1146 sur REP_Config, seule REP_Classements présente en base). Sur une base n'ayant jamais eu ce module, la création du schéma s'interrompait en plein milieu : une migration ajoutait une colonne à REP_Config avant même que la table soit créée. Invisible lors d'une mise à jour (la table existait déjà), bloquant sur toute installation neuve. Deux correctifs : la migration est replacée après la création de la table, et l'ajout de colonne ne peut plus être fatal (il ignore une table absente, et se rattrape à l'ouverture de page suivante). Vérifié sur une base vierge : les 10 tables et toutes leurs colonnes sont créées à la première ouverture d'une page du module. Versions précédentes (0.2.0) : ajout des 3 scores du classement (S1/S2/S3) et de la préinscription au Championnat de France dans les classements téléchargés, plus un bouton de synchronisation générale par discipline/saison.
