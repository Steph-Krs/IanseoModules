# Correspondance types de compétition — Extranet FFTA ↔ ianseo

Fichier **à compléter**, embarqué dans le module (pas à la racine du projet) pour que
sa mise à jour sur GitHub se propage à tous les serveurs via la mise à jour standard du module
(`admin/update.php`) — un serveur qui met à jour SYNCHRO_FFTA reçoit automatiquement la dernière
version de ce tableau, sans copie manuelle.

Il sert au module pour créer une compétition ianseo à partir d'une épreuve de l'extranet :
l'extranet donne une discipline et un type de championnat, ianseo attend un type (`ToType`) et une
sous-règle (`ToTypeSubRule`). Le lien entre les deux n'est pas déductible automatiquement — d'où ce
tableau.

Tant qu'une ligne n'est pas remplie, le module **ne devinera pas** : il créera la compétition sans
type présélectionné et laissera l'utilisateur choisir.

---

## 1. Ce que donne l'extranet

### Discipline (`search[Discipline]` / colonne « Caractéristiques » de l'épreuve)

| Code | Libellé extranet |
|---|---|
| `T` | Tir à l'Arc Extérieur |
| `1` | Tir en Salle |
| `S` | Tir à 18m |
| `F` | Tir Fita |
| `E` | Tir Fédéral |
| `C` | Tir en Campagne |
| `3` | Tir 3D |
| `N` | Tir Nature |
| `B` | Tir Beursault |
| `L` | Loisirs |
| `D` | Divers |
| `J` | Jeunes |
| `R` | Rencontres Clubs Loisirs |
| `P` | Tournoi Poussin |
| `A` | Run archery |
| `H` | Para-tir à l'arc en extérieur |
| `I` | Para-tir à l'arc à 18m |
| `LC` | Loisirs Confirmé |
| `LD` | Loisirs Débutant |
| `LDC` | Loisirs Débutant et confirmé |

L'épreuve porte en plus un **libellé de format**, affiché après la discipline. Exemples relevés :
« 1 X 24 CIBLES », « 12 CONNUES + 12 INCONNUES », « (12 CONNUES + 12 INCONNUES) X2 »,
« Tir à l'Arc Extérieur », « Epreuve 1440 », « Beursault sélectif », « Autre ».
C'est souvent lui qui tranche le type ianseo (nombre de distances, de départs…).

### Type de championnat (`search[typeChamp]`)

| Code | Libellé |
|---|---|
| `I` | individuel |
| `I-D` / `I-R` / `I-A` | individuel Départemental / Régional / National |
| `I-N` / `I-C` | individuel Championnat de France / Coupe de France |
| `I-O` | individuel Championnat de France par équipes de clubs |
| `I-I` / `I-E` / `I-M` | individuel International / d'Europe / du Monde |
| `I-Z` | individuel Club |
| `I-WFT` | individuel Circuit National Individuel |
| `E-P` / `E-C` / `E-D` / `E-G` / `E-B` / `E-2` | par équipe DNAP / D1 / DD / DR / DRE / D2 |
| `E-L` / `E-N` / `E-F` / `E-O` | par équipe National / de France / CDF / CDFEC |
| `E-Z` / `E-WFT` | par équipe Club / WFT |
| `U` | uniquement équipe |

---

## 2. Ce qu'accepte ianseo (règles françaises)

Source : `Modules/Sets/FR/sets.php` (types autorisés) + table `TourTypes` + `Common/Languages/fr/Tournament.php`.

### Types de compétition (`ToType`)

La colonne **Famille** est la seule donnée machine-lisible du fichier qui ne sert pas à la
création de la compétition elle-même, mais à la configuration assistée des départs (§5) — un seul
champ nom-de-famille, jamais codé en dur en PHP/JS (`sfa_session_families()` dans mapping.php).

| ToType | Libellé ianseo (fr) | Distances | Famille |
|---|---|---|---|
| `3` | TAE 70m/50m | 2 | `TAE` |
| `6` | 2x18 m | 2 | `18m` |
| `7` | Salle 25 m | 2 | `18m` |
| `8` | Salle 18+25 m | 4 | `18m` |
| `9` | Campagne 12+12 | 1 | `Campagne` |
| `11` | 3D | 1 | `3D` |
| `50` | Beursault | 1 | `Beursault` |

> Le Para n'a pas de famille à part : c'est une sous-règle de TAE (`SetFRTAE-Para`) et de 18m
> (`SetFrSelectifPara`, ToType 6/7/8) — il partage donc automatiquement les paramètres de départ
> de sa famille (§5), sans détection spécifique nécessaire.

### Sous-règles disponibles par type (`ToTypeSubRule`)

| ToType | Sous-règle | Libellé ianseo (fr) |
|---|---|---|
| **3** (TAE) | `SetFRTAE-Valides` | Selectif TAE |
| 3 | `SetFRTAE-Para` | Selectif TAE + Para |
| 3 | `SetFRChampionshipJun` | Championnat de France Jeune |
| 3 | `SetFRChampJunTeams` | Championnat de France Jeune Equipe |
| 3 | `SetFRCoupeFrance` | Championnat de France Adulte |
| 3 | `SetFRTAE` | Championnat de France Elite |
| 3 | `SetFRChampsTNJ` | Tournoi National Jeunes |
| 3 | `SetFRFinDRD2` | Finales des DR |
| 3 | `SetFRFinalsD2` | Finales France D2 |
| 3 | `SetFRD12023` | D1 (millésime 2023) |
| 3 | `SetFRD12026` | D1 (millésime 2026) |
| **6** (2x18 m) | `SetFrSelectif` | Sélectif |
| 6 | `SetFrSelectifPara` | Sélectif + Para |
| 6 | `SetFRChampionshipJun` | Championnat de France Jeune |
| 6 | `SetFRChampionshipSen` | Championnat Elite/Adulte |
| **7** (Salle 25 m) | `SetFrSelectif` | Sélectif |
| 7 | `SetFrSelectifPara` | Sélectif + Para |
| **8** (Salle 18+25 m) | `SetFrSelectif` | Sélectif |
| 8 | `SetFrSelectifPara` | Sélectif + Para |
| **9** (Campagne) | `SetFRDominical` | (dominical) |
| 9 | `SetFRChampionship` | (championnat) |
| **11** (3D) | `SetFRDominical` | (dominical) |
| 11 | `SetFRChampionship` | (championnat) |
| **50** (Beursault) | `SetFrBouquet` | Bouquet Provincial |
| 50 | `SetFrBeursault` | Beursault |

> Remarque : ianseo n'a **pas encore** de type « Tir Nature », « Run archery », « Loisirs » ni « 1440 »
> (à venir côté FFTA). En attendant, ces disciplines extranet restent **non créables** : ligne sans
> `ToType` dans le tableau §3 → le module l'affiche comme « type indisponible dans ianseo » et bloque
> la création plutôt que d'inventer.

---

## 3. Tableau de correspondance — À REMPLIR

Une ligne par combinaison extranet qui doit donner une compétition ianseo.
Laisser `ToType` / `ToTypeSubRule` vides si la combinaison ne doit **pas** être créée automatiquement.

| Discipline extranet | Libellé de format (extranet) | Type de championnat | → ToType | → ToTypeSubRule | Remarques |
|---|---|---|---|---|---|
| `T` Tir à l'Arc Extérieur | Tir à l'Arc Extérieur | `I` individuel | 3 | `SetFRTAE-Valides` | Selectif TAE |
| `T` Tir à l'Arc Extérieur | Tir à l'Arc Extérieur | `I-N` Championnat de France | 3 | `SetFRCoupeFrance` | Championnat de France Adulte (par défaut mais chaque championnat a sa propre règle et exalto ne le différencie pas) |
| `T` Tir à l'Arc Extérieur | Tir à l'Arc Extérieur | `E-C` par équipe D1 | 3 | `SetFRD12026` | D1 (millésime 2026) |
| `H` Para-tir extérieur | Para-tir à l'arc en extérieur | `I` | 3 | `SetFRTAE-Para` | Selectif TAE + Para |
| `S` Tir à 18m | Tir à 18m | `I` | 6 | `SetFrSelectif` | Sélectif |
| `I` Para-tir à 18m | Para-tir à l'arc à 18m | `I` | 6 | `SetFrSelectifPara` | Sélectif + Para |
| `C` Tir en Campagne | 12 CONNUES + 12 INCONNUES | `I` | 9 | `SetFRDominical` | (dominical) |
| `C` Tir en Campagne | (12 CONNUES + 12 INCONNUES) X2 | `I` | 9 | *pas encore créé* |  |
| `3` Tir 3D | 1 X 24 CIBLES | `I` | 11 | `SetFRDominical` | (dominical) |
| `3` Tir 3D | 1 X 24 CIBLES - Duels | `I-R` | 11 | `SetFRChampionship` | (championnat) |
| `B` Tir Beursault | Beursault sélectif | `I` | 50 | `SetFrBeursault` | Beursault |
| `B` Tir Beursault | Bouquet Provincial | `I` | 50 | `SetFrBouquet` | Bouquet Provincial |
| *(ajouter des lignes)* |  |  |  |  |  |

---

## 3 bis. Affinage par nom (regex) — pour les cas qu'Exalto ne distingue pas

Certaines cases du tableau §3 recouvrent **plusieurs** championnats ianseo qu'Exalto range sous un
seul `typeChamp` (ex. « Championnat de France » TAE = Adulte, Elite, Jeune, Jeune Équipe ou TNJ selon
le cas). Le nom de l'épreuve, lui, porte souvent l'information. On affine donc avec des règles regex.

**Fonctionnement (contrat du module) :**
- Le nom est **normalisé** avant test : majuscules, accents retirés, espaces compactés
  (« Championnat Régional 3D » → `CHAMPIONNAT REGIONAL 3D`).
- Les règles sont testées **dans l'ordre, à `ToType` fixé** (celui déjà déterminé par le §3).
  **La première regex qui matche gagne** ; si aucune ne matche, on garde la sous-règle par défaut du §3.
- Une regex **ne change jamais le `ToType`** — uniquement la `ToTypeSubRule`. Un nom trompeur ne peut
  donc pas transformer un 3D en TAE, au pire il choisit la mauvaise *variante* du bon type.
- Ces règles sont **facultatives** : une case §3 non ambiguë (Beursault, Sélectif salle…) n'en a pas besoin.

**⚠️ Les regex ci-dessous sont des _propositions_ à valider :** je ne connais pas vos conventions
réelles de nommage. Corrigez-les d'après ce que les organisateurs saisissent vraiment.

### Type 3 (TAE) — défaut §3 = celui de la ligne ; à préciser quand le nom le permet

| Ordre | Regex sur le nom normalisé | → ToTypeSubRule | Championnat visé |
|---|---|---|---|
| 1 | `EQUIPE.*JEUNE\|JEUNE.*EQUIPE` | `SetFRChampJunTeams` | CF Jeune par équipe |
| 2 | `TOURNOI NATIONAL JEUNE\|\bTNJ\b` | `SetFRChampsTNJ` | Tournoi National Jeunes |
| 3 | `\bJEUNE?\b` | `SetFRChampionshipJun` | CF Jeune |
| 4 | `\bELITE\b` | `SetFRTAE` | CF Elite |
| 5 | `FINALE.*\bD2\b\|\bD2\b.*FINALE` | `SetFRFinalsD2` | Finales France D2 |
| 6 | `FINALE.*\bDR\b\|\bDR\b` | `SetFRFinDRD2` | Finales des DR |
| — (aucun match) | | `SetFRCoupeFrance` | CF Adulte (défaut) |

### Type 6 (2×18 m) — n'affine que les championnats (le sélectif reste `SetFrSelectif`)

| Ordre | Regex sur le nom normalisé | → ToTypeSubRule | Vise |
|---|---|---|---|
| 1 | `\bJEUNE?\b` | `SetFRChampionshipJun` | CF Jeune salle |
| — (aucun match, mais nom = championnat) | | `SetFRChampionshipSen` | Elite/Adulte salle |

> Types 7, 8, 9, 11, 50 : une seule sous-règle utile par cas côté §3 → pas d'affinage nécessaire
> pour l'instant. Ajoutez une sous-section si un besoin apparaît.

---

## 4. Autres valeurs à confirmer

Points sur lesquels l'extranet ne dit rien et que le module devra soit laisser vides, soit déduire :

- **Nombre de départs** (`ToNumSession`) : l'extranet ne l'indique pas. Défaut = 1 > demander à l'utilisateur
- **Distances / blasons** : dépendent du type ; ianseo les pose depuis le `Setup_*_FR.php` du type choisi.
  Le module ne doit donc rien forcer, seulement choisir le bon type > oui
- **Nom de la compétition** (`ToName`) : reprendre le nom de l'épreuve extranet tel quel
- **Code compétition** (`ToCode`) : convention = `F` + 2 derniers chiffres de la saison sportive (septembre N-1 à aout N) + n° d'épreuve (issu de l'extranet et unique à chaque compétition). ex : F2675236
  Sur un serveur multi-comptes (module AUTH), le code détermine la propriété — voir `AUTH/CLAUDE.md`.
  - Saison : mois ≥ septembre → année civile + 1, sinon année civile (ex. 12/07/2026 → saison `26`).
  - ⚠️ `ToCode` est limité à **8 caractères** en base. `F` + 2 + n° d'épreuve tient si le n° fait
    ≤ 5 chiffres (les exemples relevés en font 5). Un n° à 6 chiffres déborderait → le module devra
    le détecter et refuser plutôt que tronquer (une troncature créerait un doublon de code).

---

## 5. Paramètres de départ par discipline

Configuration assistée de la table des départs (un départ = une ligne) à la création. Ces 3
tableaux sont lus par `sfa_rythme_bounds()`, `sfa_pelotons_config()` et `sfa_session_durations()`
(mapping.php) — la famille (colonne du §2) fait le lien avec la discipline choisie. Comme le
tableau §3, ils sont **à compléter** : une famille ou une combinaison absente reste simplement
sans contrainte/auto-remplissage côté formulaire plutôt que de deviner.

### A. Archers par cible/peloton (rythme de tir)

`Min`=`Max` signifie un nombre fixe, sans choix possible (champ affiché grisé côté formulaire).

| Famille | Min | Max | Défaut | Remarque |
|---|---|---|---|---|
| `TAE` | 2 | 4 | 4 | Inclut Para (même ToType) |
| `18m` | 2 | 4 | 4 | Inclut Para (même ToType) |
| `Campagne` | 3 | 4 | 4 | |
| `Nature` | 3 | 5 | 4 | ianseo n'a pas encore ce ToType (§2) — prêt pour le jour où il existera |
| `3D` | 4 | 6 | 4 | |
| `Beursault` | 5 | 5 | 5 | Tir individuel, aucun choix — champ grisé, non modifiable |

### B. Pelotons autorisés (nombre de cibles, `SesTar4Session`)

Ce n'est pas une règle sportive mais une capacité de terrain : `stepper` = simple +/- sans borne
stricte (valeur de départ = `Défaut`) ; `toggle` = case à cocher « pelotons bis autorisés »
(décochée → `Décoché`, cochée → `Coché`), champ résultat affiché grisé, non saisissable au clavier.

| Famille | Mode | Défaut | Décoché | Coché |
|---|---|---|---|---|
| `TAE` | stepper | 24 | | |
| `18m` | stepper | 24 | | |
| `Beursault` | stepper | 24 | | |
| `Campagne` | toggle | | 24 | 48 |
| `3D` | toggle | | 21 | 42 |
| `Nature` | toggle | | 21 | 42 |

### C. Durée du départ (minutes)

Auto-remplit le champ « Durée » quand la combinaison Famille + Archers/cible est connue ; sinon le
champ reste libre (aucune valeur devinée). Table volontairement incomplète pour l'instant.

| Famille | Archers/cible | Durée (min) |
|---|---|---|
| `TAE` | 4 | 240 |
| `TAE` | 3 | 165 |
| `TAE` | 2 | 155 |
| `18m` | 4 | 210 |
| `3D` | 4 | 240 |
| `Campagne` | 4 | 360 |
| *(à compléter)* | | |

### D. Libellé du rythme de tir

Sert au commentaire de planning écrit sur la première distance du départ, quand l'entraînement est
inclus : « Entraînement (N volées) suivi des qualifications en rythme **AB-CD** ».

Famille `*` = valable pour toutes les disciplines ; une ligne portant une famille précise **prime**
sur la ligne `*` de même nombre d'archers. C'est ce qui distingue les deux rythmes à 5 archers.
Si aucune ligne ne correspond, le commentaire est écrit **sans** la mention de rythme (jamais de
libellé inventé).

| Famille | Archers/cible | Libellé |
|---|---|---|
| `*` | 2 | `AB` |
| `*` | 3 | `ABC` |
| `*` | 4 | `AB-CD` |
| `*` | 5 | `AB-CD-E` |
| `*` | 6 | `AB-CD-EF` |
| `Beursault` | 5 | `A-B-C-D-E` |
