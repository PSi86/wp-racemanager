# Live URLs and VAPID keys

Why these two look the way they do. Both were implemented in
[#3](https://github.com/PSi86/wp-racemanager/pull/3) and
[#5](https://github.com/PSi86/wp-racemanager/pull/5); this is the reasoning behind the shape,
kept because several decisions look arbitrary until you know what they avoid.

---

## Part 1 — the race lives in the URL path

### What it replaced

The live area kept the selected race in `$_SESSION` and redirected every `/live/*` request to
the session's race. That is:

- **one value per browser, not per tab** — two open races overwrote each other
- **invisible to any page cache** — the whole live area was uncacheable exactly when load
  peaks, on race day
- **useless for the PWA** — the start URL carried no race, so a cold start always landed back
  in the selection list

The function was called `rm_rewrite_live_urls()` and its docblock promised link rewriting, but
it implemented a redirect. The rewriting is what was actually needed.

### The URL shape

| | |
|---|---|
| `/live/` | race selection |
| `/live/{race-slug}/{view}/` | a race in one of the views |
| `/live/{race-slug}/` | 301 to the default view |
| `/live/{view}/` | still valid, renders "no race selected" |

`{view}` is the slug of any child page of the configured live page, so the view pages stay
ordinary, editable WordPress pages.

### The rewrite rule, and why its second segment is an alternation

```php
add_rewrite_rule(
    preg_quote( $path, '#' ) . '/([^/]+)/(' . $view_pattern . ')/?$',
    'index.php?pagename=' . $path . '/$matches[2]&rm_race=$matches[1]',
    'top'
);
```

`$view_pattern` is an alternation of the actual view slugs — `bracket|stats|nextup|…` — built
from the same cached child-page list that drives the rest of the routing.

**A generic `[^/]+` for the second segment is wrong**, and this was caught only after the first
implementation was already pushed. It also matches:

| URL | resolves to | result |
|---|---|---|
| `/live/page/2/` | `pagename=live/2&rm_race=page` | **404** — the race list's own pagination |
| `/live/page/17/` | same | 404 |
| `/live/{race}/feed/` | `pagename=live/feed` | feeds break |

Paging past the first ten races was broken. With the alternation the rule can only ever match
a real view, which removes the whole class of collision.

Other properties worth keeping:

- Registered at the **top**, so it beats the generic page rules.
- **No leading `^`** — `WP::parse_request()` prepends one itself
  (`preg_match( "#^$match#", … )`), so a second is noise.
- Single-segment URLs like `/live/bracket/` do not match and keep resolving as ordinary pages.
  Verified by test, because that collision would be silent.
- The rule target uses a literal `pagename=live/$matches[2]`, not `pagename=$matches[2]`, so
  core's "verbose page rules" check never engages. Behaviour is therefore the same regardless
  of the site's permalink structure.

Rules are cached in the `rm_live_routing` option and flushed when the live page or one of its
children changes. **After changing the rule itself, re-save Permalinks.**

### Navigation — the part the session was really needed for

The view navigation is an ordinary WordPress navigation block whose links point at
`/live/stats/` and friends, with no race. That is what the session redirect used to cover.

`rm_rewrite_live_links()` rewrites those hrefs while rendering, using `WP_HTML_Tag_Processor`
(core since 6.2) so the markup is parsed rather than pattern-matched.

Verified points that are easy to get wrong:

- `apply_filters( 'render_block', … )` runs in `WP_Block::render()` **after** the render
  callback, so the filter sees navigation output regardless of any caching inside the block,
  and at both the `navigation-link` and `navigation` level. Rewriting is idempotent, so being
  called twice is harmless.
- Classic themes render menus through `wp_nav_menu()`, which never passes through
  `render_block`. That filter is covered too.
- Links to another race, off-site links, anchors and `mailto:` are left alone.

### The selection link carries `rm_race`, never `race_id`

Links back to the race selection get `?rm_race={slug}` so the list can mark which race the
visitor came from.

**It must not be `race_id`.** That parameter triggers the legacy redirect, which would bounce
the visitor straight back out of the selection page — an infinite bounce. Filterable via
`rm_selection_link_carries_race`.

For the same reason, canonicalising a numeric race segment is limited to actual view pages, so
`?rm_race=182` used as a marker on the selection page does not redirect.

### "Continue where I was"

Moved to the client. The PWA start URL is `/live/?resume=1` and `js/rm-live-resume.js` restores
the last race from `localStorage`. Without a stored race, or without JavaScript, the selection
list is shown — the correct fallback either way.

The server stays stateless, so every live URL is cacheable and shareable. Speculative loading
(WordPress 6.8+) turns from a hazard into a benefit: path URLs are prefetched where
query-string URLs were excluded.

### Backwards compatibility

- `?race_id=123` is still accepted and 301s to the canonical URL — bookmarks, the race buttons
  block, and the `msg_url` of push notifications already sent.
- The redirect fires **only inside the live area**. `/register/?race_id=123` is the registration
  form's own parameter; hijacking it would have broken the "Join now!" button.
- A numeric path segment redirects to the slug form.
- The PWA **scope is unchanged**, so installed apps do not need reinstalling.
- Unpublished races resolve only for users who may edit them, so a draft is not exposed by
  guessing its slug.

### Alternatives that were rejected

| Shape | Why not |
|---|---|
| `?race_id=` everywhere, links set server-side | Correct, but query-string URLs are excluded from speculative loading, awkward to share and weak for SEO. Usable as an intermediate step. |
| `/live/{view}/{race-slug}/` | Collision-free without any alternation, but semantically backwards: the view before the subject. Only worth it if arbitrarily deep page trees appear under `/live/`. |
| `/races/{slug}/live/{view}/` | Cleanest from WordPress's point of view — the race is a real post. But it breaks the PWA scope `/live/` and therefore every already-installed app. |

---

## Part 2 — VAPID keys without manual steps

### What it replaced

Setting up Web Push meant calling `keygen.php` in the browser, copying both keys into
`pwa-subscription-handler.php` by hand, and deleting the file again. That left a script in the
plugin root — no `ABSPATH` guard, no capability check — that printed the **private** key to
anyone who requested it, and made key rotation a code change.

### Where keys come from

Two sources, constants first:

1. `RM_VAPID_PUBLIC_KEY` / `RM_VAPID_PRIVATE_KEY` / `RM_VAPID_SUBJECT` in `wp-config.php`
2. The `rm_vapid` option, managed on **Settings → RaceManager**, stored with autoload disabled

**Recommended for production: the constants.** The practical difference is not access control —
anyone who can read the database or run PHP on the site can read either — but that a constant
does not end up in database dumps that get handed around or replayed into staging. Encryption
at rest would add nothing without real key management, since the decryption key would sit in
the same filesystem.

### Generation is deliberately cautious

A key pair is generated on activation, **but only when that is safe**. If subscriptions already
exist, new keys would invalidate the `applicationServerKey` baked into every one of them, so
nothing is generated and an admin notice points at the settings screen, where the existing pair
can be imported once.

Regenerating an existing pair needs an explicit confirmation checkbox and offers to clear the
now-dead subscription rows.

### The sanitize callback merges rather than replaces

The private key is never rendered into the settings form, so nothing in a normal save carries
it. `rm_settings_sanitize_vapid()` therefore merges against the stored value instead of
deriving the result from the request alone — otherwise saving any unrelated setting would wipe
the key. This is the single most consequential line in that file and it is covered by its own
test suite.

### Reading keys at call time

`PWA_Subscription_Handler` reads the credentials when it needs them and refuses to send when
keys or the library are missing, instead of constructing `WebPush` with empty credentials.
Automatic migration from the previously hard-coded property was deliberately left out — it
would have created a second, hidden source of truth. The import fields cover that once.

### Finding the Composer autoloader

`rm_push_library_available()` probes known vendor locations with `file_exists()` rather than
assuming a fixed relative path. The old `require __DIR__ . '/../../../../../vendor/autoload.php'`
fataled whenever the installation layout differed, and `keygen.php` used a *different* number
of levels for the same file.
