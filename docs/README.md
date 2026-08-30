# Documentation

Written while catching up with WordPress 6.9–7.1 after a year without updates.

| Document | What it is for |
|---|---|
| [development-setup.md](development-setup.md) | Setting up a local WordPress to develop and test against, with DDEV and VS Code. **Not set up yet — postponed until back at the desktop, not abandoned.** |
| [deployment.md](deployment.md) | Building the plugin artifact and getting it onto the production host, which has no WP-CLI. |
| [wordpress-update-audit.md](wordpress-update-audit.md) | The findings list with a status per item. **This is the to-do list.** |
| [deployment-test-protocol.md](deployment-test-protocol.md) | What to check manually after deploying. Automated tests cover the rest — see [`tests/README.md`](../tests/README.md). |
| [data-flow.md](data-flow.md) | How race data gets from the timer to a phone at the trackside, and what that costs today. |
| [live-webapp-improvements.md](live-webapp-improvements.md) | Proposals for the live app, mostly about the data path. Ordered, with effort and effect per item. |
| [live-urls-and-vapid.md](live-urls-and-vapid.md) | Why the live URLs and the VAPID handling look the way they do. Read before changing either. |

These started as a diagnosis of a single symptom — "picking a race in the live area is
unreliable" — and grew into the catch-up. The cause turned out to be one line: WordPress 6.9
added a fifth parameter to `wp_register_script_module()`, and the plugin passed `true` there.

For the day-to-day view of the codebase, see [`CLAUDE.md`](../CLAUDE.md) in the repository root.
