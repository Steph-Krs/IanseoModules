# Journal des versions — PRONO

## 1.2.4 — 2026-11-16

Page des crédits ajoutée (auteurs et contact du module).

## 1.2.3 — 2026-09-08

Regle ianseo appliquee : Qualifications est en relation 1:1 avec Entries, donc INNER JOIN et non LEFT JOIN — corrige sur la liste des inscrits a une epreuve individuelle (meme resultat mesure : 842 archers avant comme apres). La requete des equipiers garde deliberement son LEFT JOIN, et dit pourquoi : elle part de TeamComponent, dont les lignes survivent a un reimport et pointent alors vers des inscriptions disparues — 390 des 782 lignes du tournoi 156 sont dans ce cas, et les passer en INNER JOIN changerait la taille des equipes, donc le nombre de fleches par volee, donc tout le modele de probabilite.
