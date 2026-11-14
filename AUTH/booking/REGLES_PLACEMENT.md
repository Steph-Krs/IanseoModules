# Rules of the target placement

French original: [REGLES_PLACEMENT_FR.md](REGLES_PLACEMENT_FR.md).

Rules of the automatic placement of the online registrations (page **Target assignment**,
`bk_assign_session()` in `lib/targets.php`, planner `lib/placement.php`). The placement writes
`QuTarget`, `QuLetter` and `QuTargetNo` as the core screens do: the result shows as it is in the
lists by target, the drag-and-drop plan and the scoresheets of ianseo.

## Order of the archers

The archers of a session are sorted on criteria, then spread **evenly** over the targets in that
order. Default order:

| Criterion | Default direction |
|---|---|
| **Distance** | shortest first |
| **Target face** | largest first; at one size, the single face before the reduced or triple one: 122 › 80 › reduced 80 › 60 › 60 triple › 40 › 40 triple |
| **Bow** | barebow, recurve, compound, then the other divisions of the competition in the order of ianseo |
| **Age** | youngest first (a class without an age range, such as Discovery, comes last) |
| **Sex** | women first |

Within one category, the **clubs alternate** (the biggest club first), which spreads the clubs
over the targets.

Page **Field assignment constraints**, section "Order of the placement":

- the **direction** is set session by session: from target 1 (default) or from the last target,
  depending on the field;
- the **advanced settings** (folded, optional) apply to the whole competition: order of the
  criteria, direction of each, order of the bows. Every division of the competition is listed,
  whatever the discipline (labels from ianseo: in 3D and Nature, the FR set calls the barebow
  "CL").

A change applies to the next placement (a new registration, a validation, or "Reassign" on the
Target assignment page).

## The indoor buttress

A buttress has two **halves**: **left = A and C**, **right = B and D** (rhythm 3: left A and C,
right B). A half carries **one family** of faces:

| Family | Faces | On the buttress |
|---|---|---|
| **80** | 80 cm face | the whole buttress, shared by the archers; alone on its target |
| **60** | 60 cm single face | one half, shared by the 2 archers of the half |
| **60 triple** | 60 cm triple face | like a 60, never in the same half as a 60 single face |
| **40** | 40 cm single face | two per half, one per archer |
| **triple** | 40 cm triple face, recurve or compound | two per half, one per archer |
| unknown | face not recognised | alone on its target (safe fallback) |

Example: a 60 in A may stand beside a 40 in B or in D, **never in C**. A 40 beside a triple face is
possible but avoided (one more target when the field has it), and reported.

The family comes from the **ianseo target type** (`TargetFaces.TfT<n>`, `TfW<n>` = diameter), not
from its name. **Indoor 25 + 18**: it is taken at each distance ("60cm/40cm" = 60 at 25 m then 40
at 18 m) and a target must be possible at each distance.

**Outdoors**: a full face (122, 80) is shared by the archers of the target; the reduced 80 (5-X,
ianseo type "compound outdoor") is set **one per archer**, as many as the rhythm (4 at ABCD, 3 at
ABC); never a reduced face with another one ([REGLES_COHABITATION.md](REGLES_COHABITATION.md)).
**Courses and the other disciplines**: no rule on faces.

**Letters**: a target with fewer archers than letters puts them on the **outer** letters, to leave
room between them on the shooting line (2 archers at ABC: A and C, not B; 2 at ABCD: A and D) —
the same rule as REPARTITION_EPREUVES. Indoors, when two families of faces share the target, the
halves of the buttress decide. A letter wish comes first.

## Hard (never broken)

- one distance per target, series by series;
- the **field constraints**: range of distances and **exact faces** each target accepts. A target
  reserved to compound triple faces never takes recurve triple faces; to accept both, tick both. A
  reserved target that accepts a group of archers is given to it first;
- the capacity of the buttress and the rhythm;
- an archer already placed (by hand, by another tool) never moves;
- a registration waiting for validation is not placed.

## Soft, by priority

1. **Everyone gets a target.**
2. **At least 6 targets in use** (2 archers or more) per session, rule of the regulations with the
   3 clubs: while the 6 targets are not reached, the archers are spread; beyond, the targets are
   filled normally. Never a target of a single archer to reach the minimum. Competition setting
   (6 by default, editable only overseas, like the club quota).
3. **Club quota** per target.
4. **Regulations B.1.2**: not 2 archers of one club alone on a target (they would mark each
   other); no archer alone; not 4 archers on the target of a wheelchair archer (wheelchair field of
   ianseo).
5. **No 40 with a triple face** on one target.
6. **The order** above, mixing as little as possible: when a rule forces an archer to move, they
   swap with the nearest archer in the order, the one changing only the least important criterion
   (within one category first).
7. **Balance**: 6 archers on 2 targets make 3 + 3, not 4 + 2.
8. "**On the same target as**" wish, then **letter** wish.

## Rules check

The Target assignment page (and the "Registration status" tab) checks the targets **as they are**,
the organiser's moves in ianseo included: club quota, number of clubs, targets in use, archer alone,
2 archers of one club alone, 4 archers on the target of a wheelchair archer, faces that cannot share
their target, double registrations, archers not placed.

## Registration gauge

The places left for a profile (bow, category, face) are counted target by target, around the
archers already placed, with the rules of the placement (halves of the buttress, faces sharing a
target, field constraints, wheelchair). Empty targets with the same setting are measured once: the
gauge stays immediate on a session of 9,999 targets.

## Method

1. The archers are sorted on the criteria; "on the same target as" wishes bring the askers next to
   the person they asked for.
2. For each distance, the number of targets is set (rhythm, wheelchairs, club quota, then 6 targets
   in use), then the sequence of archers is **cut** into targets at the best cost (dynamic
   programming): gap to the average size, archer alone, two families of faces on a target, change
   of category, club quota, wishes.
3. The targets are laid on the field in the direction of the session, following the constraints.
4. Swaps of one archer, then of two, for the club quota and the pairs of one club.
5. The archers left go where they can; last moves for the B.1.2 rules.

## Tests

Every rule has its case in the bench **`booking/tests/placement.php`** (no database, command line):

```
php Modules/Custom/AUTH/booking/tests/placement.php        # summary, and the failures in detail
php Modules/Custom/AUTH/booking/tests/placement.php -v     # every case, its rule and its result
```

Every plan is also checked against the invariants that must hold whatever the data: archer placed
once, no place taken twice, archers already placed never moved, one distance per target, one family
per half of the buttress at every distance, field constraints respected.
