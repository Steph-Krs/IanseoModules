# Target face cohabitation rules (M7)

French version: [REGLES_COHABITATION_FR.md](REGLES_COHABITATION_FR.md) (the original).

Specification provided (source: FFTA). Basis of the cohabitation implementation in
`bk_assign_session()` (lib/targets.php). The cases marked **to be refined** are not settled —
invent nothing, keep the safe fallback (no forced cohabitation).

## General principle (all disciplines)
- Archers of the **same categories are placed together** to group them (an organisational
  convenience, not an explicit rule of the regulations).
- Target face cohabitations happen **on the transition targets** from one category to the
  next. Elsewhere, a target carries a single type of target face.

## Outdoor target archery (TAE) — whatever the rhythm
On a given target, one of these cases:
- **1** target face of **60**, of **80 (zones 1-10)** or of **122**;
- **2 or 3** target faces of **reduced 80** (zones 5-10 or 6-10).

## 18 m indoor — depending on the rhythm (number of archers per target)
Classic trispot or compound = **no distinction**. Monospot = "single face".

### AB rhythm (2 archers / target)
- 2 trispots of 40 or 60 (recurve/compound, no distinction);
- 2 monospots of 40 or 60;
- 1 monospot of 40 or 60 **+** 1 trispot of 40 or 60;
- 1 target face of 80.

### ABC rhythm (3 archers / target)
- 3 trispots of 40;
- **to be refined**: the case of 3 archers other than trispots is not defined.

### AB-CD rhythm (4 archers / target)
- 4 trispots of 40 (recurve/compound), wherever they are;
- 4 monospots of 40, wherever they are;
- 2 trispots of 40 at A and C **+** 2 monospots of 40 at B and D (or the other way round);
- 1 trispot of 60 for A and C **+** 2 monospots or 2 trispots for B and D (or the other way round);
- 2 monospots of 60, wherever they are;
- 1 target face of 80.

## Courses (Field, 3D, Nature) — groups
The "target" is a **group** (patrol). Two archers shooting from the same **peg** are grouped; in
a group of 4 there can be 2 different pegs. There is no real peg cohabitation rule —
**organisational convenience, to be refined**. The firm constraints come from the
regulations (composition of the group):

### Field
- Group of **4 at most, never fewer than 3**. If possible, the same number of shooters per group.
- If there are **3**, the possible positions are **A, B, C only** (not D).

### Nature
- Group of **3 minimum to 5 maximum**.
- At most **2 archers of the same club** in a group of 4, or **3** in a group of 5.
- Avoid a group of 3 juniors + 1 adult; prefer 1 junior + 3 adults or 2 juniors + 2 adults.

### 3D
- Group of **4 minimum to 6 maximum**.
- At most **2 archers of the same club** in a group of 4, or **4** in a group of 6.
- Same junior/adult balance as Nature.

## Beursault
- **No** cohabitation rule.

## Implementation notes
- Classification of a target face from `TargetFaces.TfName` (FFTA set): diameter (40/60/80/122)
  + type (monospot "Blason Unique", trispot "Trispot", full "Blason Complet/Classique",
  reduced "réduit"/"5-10"/"6-10", peg "Piquet <colour>"). Recurve vs compound:
  ignored (no distinction). Fallback + possible override via `config.local.json`.
- The 18 m rhythm = number of letters per target (`Session.SesAth4Target`).
- Cohabitation validates a **set of occupants** of a target: put it in the eligibility check
  of `bk_assign_session()` (same hook as the club quota).
- **Budget + cost model** (implemented): each target has a budget (18 m: 4; TAE: 3), each
  target face a cost (18 m: 40→1, 60→2, 80→4; TAE: reduced→1, full→3). Valid if Σcosts ≤ budget and
  number of archers ≤ rhythm. Reproduces all the combinations above.
- **Shareable target face** (essential): in TAE, a **full** target face is shot by several archers
  of the same category on a single target → it counts only **once** in the budget. At 18 m and
  for the TAE reduced ones, each archer has their own target face (one cost per archer).
