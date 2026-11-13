# Target placement rules

French version: [REGLES_PLACEMENT_FR.md](REGLES_PLACEMENT_FR.md) (the original).

Rules of the automatic placement of online registrations (**Target assignment** page,
`bk_assign_session()` in `lib/targets.php`, planner `lib/placement.php`). Agreed with the organisers
on 12 November 2026. They replace grouping by category: **archers are placed by face**, and
different categories share a target when their archers shoot at the same distance on compatible
faces.

The placement writes `QuTarget`, `QuLetter` and `QuTargetNo` as the core screens do: the result shows
as is in the ianseo target lists, the drag-and-drop plan and the score sheets.

## The indoor buttress

A buttress has two **halves**: **left = A and C**, **right = B and D** (rhythm 3: left A and C,
right B; rhythm 2: A and B). A half carries **one family** of faces:

| Family | Faces | On the buttress |
|---|---|---|
| **80** | 80 cm face | the whole buttress, shared by the 4 archers; alone on its target |
| **60** | 60 cm single face | one half, shared by the 2 archers of the half |
| **60 triple** | 60 cm triple face (youth) | like a 60, but never in the same half as a 60 single face (same target allowed) |
| **40** | 40 cm single face | two per half, one per archer |
| **triple** | 40 cm triple face, recurve or compound alike | two per half, one per archer |
| unknown | face not recognised | alone on its target (safe fallback) |

The family comes from the **ianseo face type** (`TargetFaces.TfT<n>`, `TfW<n>` = diameter), not
from its name. Between the two halves of a target, every mix is allowed except **40 with triple**,
avoided (last resort, reported).

**Indoor 25 + 18**: the family is taken **at each distance** ("60cm/40cm" = 60 at 25 m, then 40 at
18 m); a target must be possible at each distance.

**Outdoors**: the rules of [REGLES_COHABITATION.md](REGLES_COHABITATION.md) are unchanged.

## Hard (never broken)

One distance per target, series by series; the field settings; buttress capacity and rhythm;
archers already placed never move (they impose their family on their half, and the free places of
their target are used only when needed); registrations awaiting validation are not placed.

## Soft, by priority

1. everyone placed; 1 bis. at least 6 targets filled (2 archers or more) per session, a rule of
the regulations (competition setting, 6 by default, also checked by the rules check), never down
to a target of a single archer; 2. club quota per target; 2 bis. checks of the greffe (regulations B.1.2): no 2 archers of one club alone on a target, no archer alone, no 4 archers on a target with a wheelchair archer (ianseo's wheelchair field), also reported by the rules check; 3. no 40 with a triple face; 4. fewest targets;
5. target order: shortest distance first, contiguous targets, families 80, 60, 40, triple;
6. balance within a family (6 on 2 targets = 3 + 3); 7. "same target as" wishes; 8. letter wishes.

## Registration gauge

Measured with the placement itself: as many archers of the profile as there are free places are
planned around the archers already placed; the ones the plan takes are the gauge.

## Known limits

A few candidate plans are built and the best one is kept: not an exhaustive search. Outdoors, a
target carries a single face.

## Tests

Every rule has its case in the standalone bench **`booking/tests/placement.php`** (no database,
command line): `php Modules/Custom/AUTH/booking/tests/placement.php` (add `-v` for every case).
Besides the expected result of each case, every plan is checked against the invariants that must
hold whatever the data.
