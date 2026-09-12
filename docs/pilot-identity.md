# A stable pilot identity for RotorHazard

**Both halves are done.** WordPress (1.5.0): every registration carries a `pilot_key`, one per
email address, the same every time. The connector (its PR #22, 2026-09-11) matches a returning
pilot by the key first and keeps it on the pilot; see
[What the connector has to do](#what-the-connector-has-to-do). **And the key comes back** (1.7.0):
the connector sends it with every upload, and push subscriptions follow it — see
[The key in the upload](#the-key-in-the-upload).

## Why it matters

RotorHazard downloads a race's registrations through `GET /rm/v1/get-pilots` and turns each into a
pilot on the timer. When the same race is downloaded again — a pilot registered late, a detail was
corrected — the timer has to decide, per registration, whether it is a pilot it already has or a
new one.

The RotorHazard connector matches on the WordPress account: it stores the registration's `user_id`
on the pilot (attribute `rm_wp_user_id`) and, on the next download, updates the pilot with that
`user_id` instead of adding a second one. Matching by callsign alone failed whenever a pilot changed
their callsign between downloads — the timer then held two copies. This is the RotorHazard
connector's **D2** (see its `docs/roadmap.md` and `docs/wordpress-contract.md`).

## Why `user_id` is not enough

`user_id` is filled in `includes/admin-registrations.php`, both for a Contact Form 7 submission
(`rm_save_submission`) and for a registration an organiser adds by hand:

```php
$user_id = rm_get_user_id_by_email( $pilot_mail_1 );
```

`rm_get_user_id_by_email()` returns the ID of the WordPress user **whose account email matches the
address typed into the form**, and `0` when none does. The registration form does not require the
pilot to log in or to have an account, so in practice a pilot who registers with the email of an
existing account gets that account's `user_id`, and everyone else — most entrants at a public event
— gets `0`.

A `user_id` of `0` is not an identity. Every guest registration shares it, so the connector cannot
match on it and falls back to the callsign, which is exactly the case that fails. The table's other
key, `id`, is the registration row's primary key: unique, but a **new** value each time someone
registers, so it identifies a form submission, not a pilot across submissions.

## Decided on 2026-09-11

- Registering stays open to anyone: no account is required.
- No account is created for a registration either.
- Every email address gets one unique, reproducible identifier, and that is what is used.

That is option 1 of the three below, with one difference that makes it simpler: the key belongs to
the address, not to a race's registrations, and it is computed rather than stored. The same address
gives the same key for every race and on every download, with nothing to look up, no column and no
migration.

## The pilot key

[`rm_pilot_key()`](../includes/pilot-key.php) is a name-based UUID, version 5 (RFC 9562), of the
address trimmed and lower-cased — `Pilot@Example.com ` and `pilot@example.com` are one pilot — in a
namespace of this site's own. The namespace is a random UUID, generated once from `random_bytes()`,
kept in the `rm_pilot_namespace` option and shown read-only under **Settings → RaceManager**.
`RM_PILOT_NAMESPACE` in `wp-config.php` takes precedence, as the VAPID constants do; an invalid
constant hands out no keys at all rather than falling back to the option, whose keys would be
different ones.

**Why a namespace of its own** rather than a plain hash of the address: the key travels to the timer
and from there possibly into results that get published, and a plain hash of an email address can
be reversed by anyone with a list of addresses to try. With the namespace, nobody but this site can
compute it.

**What that costs — the one thing to look after: the namespace has to be kept.** It lives in the
database and goes with every backup of it. A site rebuilt from scratch without it gives every pilot
a new key, and the timer takes each for a new pilot. Copying it into `wp-config.php` as
`RM_PILOT_NAMESPACE` makes the keys independent of the database.

**Where it appears**: every row of `get-pilots`, as `pilot_key`; and the registrations list in the
admin with its CSV export, as the last column. One function, `rm_registration_rows()`, builds all
three, so the key the timer receives is the key the organiser sees next to the registration.
`get-pilots` then cuts each row to what the timer needs — name, callsign, `user_id`, `pilot_key`,
the record's ID and date — and since 1.5.1 carries no address, phone number or consent flag (D1
in the connector's roadmap): no version of the connector read them.

**Limits**, both inherent in keying by address: a typo in the address is a different address and so
a different pilot; and a pilot who registers with another address is another pilot, until someone
merges the two on the timer.

**Measured** on the local site on 2026-09-11, through WordPress's REST routing as an administrator:
three registrations for one race, two of them the same address typed differently under two
callsigns, all three with `user_id` 0. The two shared a key, the third had its own, and a second
download gave the same three keys.

## What the connector has to do

On this side the change is additive: `get-pilots` gains `pilot_key` and nothing the connector reads
is removed or renamed, so a connector that does not read the field yet is unaffected. To use it,
the connector:

1. stores `pilot_key` on the pilot, as an attribute like `rm_wp_user_id` today;
2. on the next download, matches a registration to the pilot with the same `pilot_key` first;
3. falls back as it does today — `rm_wp_user_id`, then the callsign — for a registration without a
   key (no address) and for pilots created before the key existed, and gives such a pilot the key
   once it is matched, so the fallback is needed once per pilot and not again;
4. treats an empty `pilot_key` as no key, never as a key everybody shares — the trap `user_id` 0
   sets.

Its `docs/wordpress-contract.md` then records the field. Order: this plugin first, since it only
adds a field; the connector after.

## The key in the upload

Decided on 2026-09-12: the key is no sensitive value and goes into the upload. It stands for an
address only to this site, which alone can compute it (see [The pilot key](#the-pilot-key)); in the
race's data file, which the live pages load and anyone can fetch, it is one more column of the
pilot list.

The connector sends each pilot's key as `pilot_key` in `pilot_data`, an empty string for a pilot
without one — added by hand on the timer, or imported before keys existed. RotorHazard's pilot IDs
change when the timer re-creates its pilots (*Clear pilots before download*), and push
subscriptions used to be kept by that ID, so a subscription then followed whoever had the number.
From 1.7.0 on a subscription keeps the key of the pilot it was made for and follows it; the live
pages' pilot selection does the same. Uploads without keys, from an older connector, are matched by
ID as before. How the matching goes is in [`data-flow.md`](data-flow.md).

## The options that were weighed

As they were written before the decision, listed roughly from smallest change to largest.

1. **Reuse a registration key by email, without requiring an account.** On save, if this race
   already has a registration with the same (normalised) email, reuse its stored pilot key;
   otherwise mint a new one — a UUID kept in its own column, not tied to `wp_users`. `get-pilots`
   returns it. The connector then matches on that field instead of `user_id`. Works for guests,
   but keys pilots by email, so a typo in the email splits a pilot in two.
2. **Attach or create a WordPress user at registration.** Look the email up as today; when it
   matches no account, create a lightweight user (or attach to one via a magic-link/confirm flow)
   so `user_id` is always a real, stable account id. Heavier, touches the user table and the
   registration UX, but reuses the identifier that already crosses the wire.
3. **Require login to register.** Every registration then has a real `user_id`. The most robust,
   the biggest change to how entrants sign up.

Options 2 and 3 would have needed no change to `get-pilots` or to the connector — `user_id` would
simply have stopped being `0`. Both were ruled out: registering must not need an account, and none
is to be created for it.
