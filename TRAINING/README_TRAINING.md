# Training

Module for [I@nseo](https://www.ianseo.net/), the archery competition management software.

**Trainings in a few clicks**, for clubs and training centres that often put the same archers in
real competition conditions. A person who knows ianseo prepares a few **reference formats**, even
complex ones; then a coach with no particular ianseo skill creates a training by choosing a format,
a date and the archers, prints the scorecards, enters the scores and goes from one phase to the
next from a single page.

A training is a **real ianseo competition**: results, brackets and printouts are those of the core,
so the archers get the feeling of a real competition.

## Features

- 📋 **Reference formats**: the open competition is saved as a format — divisions and classes,
  events, target faces, distances, images, competition settings and programme, without its
  archers, teams, scores nor its ianseo.net codes. The competition it comes from can then be
  deleted. A format saved before an ianseo update is brought up to date when it is used.
- 🗓️ **Creation**: a format, a date and a starting time. Every time of the programme (sessions,
  practice, matches, following days) moves with the starting time and **keeps the gaps of the
  format**. The competition code is the date and a number: `YYMMDDXX` (`26120501`).
- 🏹 **Regular archers**: a list shared by the installation, filled by hand, from the licence file
  already loaded in ianseo, or from the archers of an open competition; **groups** tick several
  archers at once. The division is recurve unless another is recorded, and the class is the one the
  date of birth allows: computed again for the date of each training, with the French rule that a
  competition from September to December counts in the next season (the class bounds of the
  training follow the season of its date, whatever the season of the format's source). Each
  archer's category can still be changed by hand, among those the format allows.
- 👥 **Teams and mixed teams composed by the coach**: "Team 1, Team 2…" with the archers chosen
  and a name of their own, as many as needed; the mixed teams are composed separately, so an
  archer can be in one team and in a different mixed team. They can be changed at any time —
  before, during or after the qualification round.
- 🎯 **Targets**: automatic placement in the order of the AUTH module — distance, face (largest
  first), bow (barebow, recurve, compound), age, sex — with the physical rules only: one distance per
  target, indoors one family of faces per half of the buttress (A C / B D) and an 80 cm face alone,
  outdoors one family per target (reduced faces together, a full face shared). Identical archers are
  spread evenly over the targets, and on each target over the letters. No club rule: it is a
  training. Manual moves on the core's visual target plan. The number of archers per target is
  switched with one button (A, AB, ABC, AB-CD), and the number of targets adjusted to what the
  archers need with another; targets are never added on their own — when they run short, the
  dashboard says how many the session would need.
- 📱 **ISK-Lite by default**: every training is set up for scoring with the free ISK app; the
  scorecards carry its QR codes and the scores reach ianseo on their own. The server address given
  to the phones is set once, on the formats page — it never appears to the coaches, who only get
  the core page that locks the archers' changes per session.
- 🥇 **Ranking and duels**: one list per event with duels, in the qualification order, ties next to
  each other; the coach drags the names into the order of the bracket and fills it. The brackets
  are filled with the core's functions (ranking classes, ruleset grids, byes).
- 👻 **Fictitious competitors**: opponents with no archer behind them, each at a level: a final
  qualification score plus or minus a delta, an average per arrow plus or minus a delta for the
  duels by sets, a match score plus or minus a delta for the duels by points. A level beyond what
  the face and the arrows allow is refused. They take part in the rankings, the teams and the
  brackets, without a target nor a scorecard.
- ⏱️ **Live page**: while it is open, the simulated competitors shoot. Their qualification arrows
  appear at the pace of the archer of the training who has shot the most, so that the ranking moves
  during the training; in the duels they show as many arrows as their real opponent has entered,
  and play out their own matches at the pace of the real matches of the same phase. Arrows are
  written through the core's own functions, which total the matches and move the winners on. The
  dashboard reminds the coach to keep the page open. Each screen chooses the events it shows.
- ⏭️ **Next round without a click**: in every training, the winner of each decided match goes on
  to the next round (ianseo leaves it to its entry screens, ISK-Lite never does it), and the byes of
  later rounds are given. A **shoot-offs page** in the coach's menu lists the matches waiting for a
  shoot-off: enter the arrows and, when they are equal, tick the archer closest to the centre.
- 🌍 **Training from a World Archery competition**, the second way of "Create a training": find a
  competition whose results World Archery publishes (period, country, discipline, level, name),
  pick the day and tick the archers of the training: the training is created with
  the events and match formats of the real competition and every athlete and team with their real
  results; the archers of the training slot into the real field. Qualification: the real score,
  golds and X of each athlete, shown at the pace of the training. Duels, reality first: two
  athletes who really met shoot that match; otherwise an athlete shoots their own arrows of that
  phase, or, beyond the round they reached, those of the one who eliminated them (and so on up the
  bracket). Target outdoor, indoor, field and 3D, individual and team events.
- 📈 **Follow-up of the archers**: every shoot of an archer on the server, trainings and real
  competitions, grouped by situation (kind, format, distances, face) with the average, the average
  per arrow, the best and the last score and the duels won and lost; a chart of the average per arrow
  over time; CSV exports (one archer, one training, every training). The results of the trainings
  are kept by the module, so they stay once the competition is deleted from ianseo.
- ⚔️ **Duels only**: a training can simulate its qualification round — every archer gets one point,
  and the order of the brackets is entirely the coach's choice.
- 🧭 **Coach's dashboard**: archers, targets, scorecards, scores, duels, results, in the order they
  are needed, each a button or a link to the core screen that does it.
- 🔁 **Import / export**: formats, regular archers and groups in one file, to set up another
  installation — another training centre — the same way. On import, an archer already there (same
  licence, otherwise same name and date of birth) is updated, groups are matched by name, and a
  format saved by a more recent ianseo is refused.
- 🧹 **Simplified menu**: on a training made from a format with this option, the ianseo menu keeps
  only the competition and the training screens. The choice belongs to each **browser**: the person
  who prepares the formats switches their own browser to the full menu, the coaches keep the
  simplified one.

Not handled by the module (core screens): events with eliminations, pools or round robins.

## Access

- Formats, regular archers, creating a training: the right to create a competition (on a local
  installation, everybody).
- Dashboard, archers of a training, teams, ranking and duels: the right to enter qualification
  scores in the open competition.
- Update and uninstall: administrator.

## Database

Nine tables of its own, created when a page of the module is first opened: `TrainingTemplates`
(formats), `TrainingArchers`, `TrainingGroups`, `TrainingGroupMembers` (regular archers and their
groups), `TrainingRuns` (the competitions created by the module), `TrainingResults` (results of the
trainings, kept for the follow-up), `TrainingGhosts` (simulated competitors and their levels),
`TrainingSimDuels` (arrows drawn for them in the duels), `TrainingWaData` (results of the World
Archery competition behind a training). Creating a training from World Archery reads its public
API (`api.worldarchery.org`): the server needs an internet connection for that, not afterwards. The competitions themselves are
ordinary ianseo competitions, written by the core's own import. The server address for ISK-Lite
is one row of the core's `Parameters` table (`TrnIskUrl`).

## Installation, update, uninstall

See the [main README](../README.md). In short: copy the `TRAINING/` and `_shared/` folders into
`Modules/Custom/` (or run `install.sh` / `install.ps1`). Updates and uninstalling from ianseo:
**Modules → Training → Module update** menu. Uninstalling leaves the trainings already created in
ianseo, as ordinary competitions.

<!-- BEGIN DATABASE WRITES (generated — do not edit by hand) -->
## Database writes

### ianseo core tables

| Statement | Table | Location | Notes |
|---|---|---|---|
| `DELETE FROM` | `Finals` | `lib/duels.php:147` | review scope by hand |
| `UPDATE` | `Finals` | `lib/duels.php:162` | review scope by hand |
| `UPDATE` | `Events` | `lib/duels.php:169` | — |
| `DELETE FROM` | `TeamFinals` | `lib/duels.php:203` | review scope by hand |
| `DELETE FROM` | `TeamFinComponent` | `lib/duels.php:204` | review scope by hand |
| `UPDATE` | `TeamFinals` | `lib/duels.php:219` | review scope by hand |
| `INSERT INTO` | `TeamFinComponent` | `lib/duels.php:226` | — |
| `UPDATE` | `Events` | `lib/duels.php:234` | — |
| `INSERT INTO` | `Countries` | `lib/entries.php:98` | — |
| `INSERT INTO` | `Entries` | `lib/entries.php:138` | — |
| `INSERT INTO` | `Qualifications` | `lib/entries.php:157` | — |
| `UPDATE` | `Entries` | `lib/entries.php:179` | review scope by hand |
| `UPDATE` | `Qualifications` | `lib/entries.php:302` | — |
| `UPDATE` | `Session` | `lib/entries.php:385` | review scope by hand |
| `UPDATE` | `Entries` | `lib/entries.php:387` | review scope by hand |
| `UPDATE` | `Session` | `lib/entries.php:404` | review scope by hand |
| `UPDATE` | `Entries` | `lib/entries.php:406` | review scope by hand |
| `UPDATE` | `Qualifications` | `lib/entries.php:442` | — |
| `UPDATE` | `Qualifications` | `lib/entries.php:456` | — |
| `UPDATE` | `Qualifications` | `lib/ghosts.php:411` | — |
| `UPDATE` | `Entries` | `lib/teams.php:73` | — |
| `UPDATE` | `Countries` | `lib/teams.php:92` | review scope by hand |
| `INSERT INTO` | `Countries` | `lib/teams.php:98` | — |
| `UPDATE` | `Entries` | `lib/teams.php:104` | review scope by hand |
| `DELETE FROM` | `Countries` | `lib/teams.php:109` | review scope by hand |
| `UPDATE` | `Events` | `lib/teams.php:113` | — |
| `INSERT INTO` | `Tournament` | `lib/wa.php:398` | — |
| `UPDATE` | `Events` | `lib/wa.php:441` | review scope by hand |
| `UPDATE` | `Events` | `lib/wa.php:446` | — |
| `DELETE FROM` | `EventClass` | `lib/wa.php:454` | — |
| `DELETE FROM` | `Events` | `lib/wa.php:455` | — |

### Tables owned by this module

| Statement | Table | Location | Notes |
|---|---|---|---|
| `UPDATE` | `TrainingArchers` | `lib/archers.php:70` | — |
| `INSERT INTO` | `TrainingArchers` | `lib/archers.php:73` | — |
| `DELETE FROM` | `TrainingGroupMembers` | `lib/archers.php:84` | — |
| `DELETE FROM` | `TrainingArchers` | `lib/archers.php:85` | — |
| `DELETE FROM` | `TrainingGroupMembers` | `lib/archers.php:96` | — |
| `INSERT IGNORE INTO` | `TrainingGroupMembers` | `lib/archers.php:99` | — |
| `UPDATE` | `TrainingGroups` | `lib/archers.php:126` | — |
| `INSERT INTO` | `TrainingGroups` | `lib/archers.php:128` | — |
| `DELETE FROM` | `TrainingGroupMembers` | `lib/archers.php:139` | — |
| `DELETE FROM` | `TrainingGroups` | `lib/archers.php:140` | — |
| `DELETE FROM` | `TrainingGhosts` | `lib/entries.php:194` | — |
| `DELETE FROM` | `TrainingSimDuels` | `lib/entries.php:195` | — |
| `INSERT INTO` | `TrainingGhosts` | `lib/ghosts.php:163` | — |
| `UPDATE` | `TrainingGhosts` | `lib/ghosts.php:179` | — |
| `DELETE FROM` | `TrainingSimDuels` | `lib/ghosts.php:181` | — |
| `UPDATE` | `TrainingGhosts` | `lib/ghosts.php:393` | — |
| `REPLACE INTO` | `TrainingSimDuels` | `lib/live.php:429` | — |
| `DELETE FROM` | `TrainingResults` | `lib/results.php:124` | — |
| `INSERT INTO` | `TrainingResults` | `lib/results.php:131` | — |
| `UPDATE` | `TrainingTemplates` | `lib/template.php:394` | — |
| `INSERT INTO` | `TrainingTemplates` | `lib/template.php:397` | — |
| `UPDATE` | `TrainingTemplates` | `lib/template.php:417` | — |
| `INSERT INTO` | `TrainingRuns` | `lib/template.php:483` | — |
| `UPDATE` | `TrainingTemplates` | `lib/transfer.php:87` | — |
| `INSERT INTO` | `TrainingTemplates` | `lib/transfer.php:90` | — |
| `INSERT INTO` | `TrainingGroups` | `lib/transfer.php:102` | — |
| `INSERT INTO` | `TrainingGhosts` | `lib/wa.php:480` | — |
| `INSERT INTO` | `TrainingRuns` | `lib/wa.php:503` | — |
| `REPLACE INTO` | `TrainingWaData` | `lib/wa.php:505` | — |
| `REPLACE INTO` | `TrainingWaData` | `lib/wa.php:508` | — |
| `UPDATE` | `TrainingRuns` | `live.php:33` | — |
| `UPDATE` | `TrainingTemplates` | `templates.php:41` | — |
| `DELETE FROM` | `TrainingTemplates` | `templates.php:49` | — |

<!-- END DATABASE WRITES -->
