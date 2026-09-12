# Bracket fixtures

What the bracket suites (`npm run test:bracket-model`, `test:bracket-standings`, `test:bracket-view`)
build their races from. No real pilot names anywhere: pilots are `P1`, `P2`, …

| File | What it is |
|---|---|
| `plans.json` | RotorHazard's built-in heat plans — regulation brackets FAI 16/32/64 single and double elimination, MultiGP 16, and the ladder and ranked-fill generators for 7 and 12 pilots — exported from RotorHazard 4.4.0's `src/server/bundled_plugins` by the RotorHazard connector's `tools/export_bracket_plans.py`. The file records the RotorHazard commit it came from. To refresh it: `uv run tools/export_bracket_plans.py <RotorHazard checkout> <this file>` in the connector's repository. |
| `races.cjs` | Turns a plan into an event in the upload's shapes the way RotorHazard's generator does (`HeatGeneratorManager.apply()`: heat ids in plan order, an input slot seeded from the Qualifying class, a heat-index slot from the heat at that index), and flies it the way `heat_automation.py` fills heats: the entry at `seed_rank - 1` of the source's board. A lower pilot number is the faster pilot unless a heat's order is given; `chaseTheAce()` adds Chase the Ace rounds and, if wanted, the ranking Class Rank: Brackets would send. |
| `old-ranking.cjs` | `js/rm-m-calcRanking.js` as it was in 1.9.1, the ranking this plugin computed for FAI 32 double elimination before 1.10.0. The standings suite runs it on the real events of the local site as the oracle. |

The real events — `../wp-app/wp-content/uploads/races/{32,33,34}-data.json` on the local site, copied
from production — are read when present and never committed: they carry real names.

Checked on 2026-09-12: an FAI 16 double elimination generated on the 4.4.0 bench and exported as the
upload has the same heat names and the same heat-to-heat seeding (method 1: rank and source heat) as
`races.cjs` builds from `double-fai16`. The bench filled its first round at random (input "All
Pilots", method 0), and its heats carry four more slots for its eight nodes, empty and without a
method (-1); `raceFromPlan( key, { nodes: 8 } )` builds those too.
