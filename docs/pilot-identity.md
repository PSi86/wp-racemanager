# A stable pilot identity for RotorHazard

This is a to-do, not a description of what the plugin does today. It records what WordPress has to
add so that RotorHazard can recognise a returning pilot, and why the current data does not let it.

## Why it matters

RotorHazard downloads a race's registrations through `GET /rm/v1/get-pilots` and turns each into a
pilot on the timer. When the same race is downloaded again — a pilot registered late, a detail was
corrected — the timer has to decide, per registration, whether it is a pilot it already has or a
new one.

The RotorHazard connector now matches on the WordPress account: it stores the registration's
`user_id` on the pilot (attribute `rm_wp_user_id`) and, on the next download, updates the pilot
with that `user_id` instead of adding a second one. Matching by callsign alone failed whenever a
pilot changed their callsign between downloads — the timer then held two copies. This is the
RotorHazard connector's **D2** (see its `docs/roadmap.md` and `docs/wordpress-contract.md`); the
timer half is done, this is the WordPress half.

## Why `user_id` is not reliable yet

`user_id` is filled in `includes/admin-registrations.php`, both for a Contact Form 7 submission
(`rm_save_submission`) and for a registration an organiser adds by hand:

```php
$user_id = rm_get_user_id_by_email( $pilot_mail_1 );
```

`rm_get_user_id_by_email()` returns the ID of the WordPress user **whose account email matches the
address typed into the form**, and `0` when none does. The `rm_registrations` table stores it as
`user_id INT NOT NULL DEFAULT 0`.

The registration form does not require the pilot to log in or to have an account. So in practice:

- a pilot who registers with the email of an existing WordPress account gets that account's
  `user_id`;
- everyone else — most entrants at a public event — gets `user_id = 0`.

A `user_id` of `0` is not an identity. Every guest registration shares it, so the connector cannot
match on it and falls back to the callsign, which is exactly the case that fails. The table's other
key, `id`, is the registration row's primary key: always present and unique, but a **new** value
each time someone registers, so it identifies a form submission, not a pilot across submissions.

## What WordPress could do (to decide)

Any of these makes `get-pilots` carry a stable per-pilot identifier. They are listed roughly from
smallest change to largest; none is chosen yet.

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

## What it means for the contract

Option 2 or 3 need **no** change to `get-pilots` or to the connector: `user_id` simply stops being
`0`. Option 1 adds a new field to the `get-pilots` response and changes which field the connector
matches on — a coordinated change to both repositories and to the RotorHazard connector's
`docs/wordpress-contract.md`, to be released together and noted in both pull requests.

Until one of these lands, the connector keeps working: it matches account-holders by `user_id` and
everyone else by callsign, and a pilot who changes their callsign between downloads is still
duplicated for the guests. Nothing here is urgent, and nothing here should be built without picking
an option first.
