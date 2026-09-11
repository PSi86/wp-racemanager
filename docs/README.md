# Documentation

Written while catching up with WordPress 6.9–7.1 after a year without updates.

| Document | What it is for |
|---|---|
| [development-setup.md](development-setup.md) | Setting up a local WordPress to develop and test against, with DDEV and VS Code. **In use** — the site runs at `https://racemanager.ddev.site`. |
| [deployment.md](deployment.md) | Building the plugin artifact and getting it onto the production host, which has no WP-CLI. |
| [wordpress-update-audit.md](wordpress-update-audit.md) | The findings list from the WordPress catch-up. All 24 are resolved; it stays worth reading for *why* things are the way they are. |
| [deployment-test-protocol.md](deployment-test-protocol.md) | What to check manually after deploying. Automated tests cover the rest — see [`tests/README.md`](../tests/README.md). |
| [data-flow.md](data-flow.md) | How race data gets from the timer to a phone at the trackside, and what that costs today. |
| [live-webapp-improvements.md](live-webapp-improvements.md) | Proposals for the live app, mostly about the data path. Ordered, with effort and effect per item. **This is the to-do list**, together with `pilot-identity.md` below. |
| [live-urls-and-vapid.md](live-urls-and-vapid.md) | Why the live URLs and the VAPID handling look the way they do. Read before changing either. |
| [pilot-identity.md](pilot-identity.md) | A to-do: give each registration a stable pilot identifier so RotorHazard can recognise a returning pilot. Why `user_id` is not enough yet, and the options. |

These started as a diagnosis of a single symptom — "picking a race in the live area is
unreliable" — and grew into the catch-up. The cause turned out to be one line: WordPress 6.9
added a fifth parameter to `wp_register_script_module()`, and the plugin passed `true` there.

For the day-to-day view of the codebase, see [`CLAUDE.md`](../CLAUDE.md) in the repository root.
