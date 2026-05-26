# Otherworld 2026 — Community Schedule

Unofficial, community-managed schedule for Otherworld 2026. Not affiliated with Kindle Arts Society or Otherworld.
---

## What this repo is

A small PHP + static-HTML site that renders the event schedule for Otherworld 2026 and lets camp owners self-edit their entries. The canonical event data lives in [`events.json`](events.json). Three independent sources can write to it:

1. **A shared Google Sheet** (most camps), reconciled into `events.json` on a schedule.
2. **The dashboard** ([`dashboard.php`](dashboard.php)), where camp owners claim their camp with a passphrase and edit events directly on the site.
3. **Manual edits** to `events.json` in the repo.

The site reads `events.json` at runtime — there is no build step.

---


## Data flow

```
   ┌─────────────────────┐                ┌──────────────────────┐
   │  Google Sheet       │ ── hourly ──▶  │ reconcile-sheet.php  │
   │  (camps fill in)    │   cron         │ (CSV → events.json)  │
   └─────────────────────┘                └──────────┬───────────┘
                                                     │
   ┌─────────────────────┐                           ▼
   │  dashboard.php      │ ── on save ──▶  ┌────────────────────┐
   │  (claimed camps)    │                 │   events.json      │  ◀── source of truth
   └─────────────────────┘                 └──────────┬─────────┘
                                                      │ fetched at page load
                                                      ▼
                                            ┌────────────────────┐
                                            │   index.html       │
                                            └────────────────────┘
```

Reconcile preserves claimed camps' events — claimed owners are the source of truth for their own data. Unclaimed camps' events are overwritten from the sheet every hour.

---

## Source of truth: `events.json`

Shape:

```json
{
  "metadata": {
    "source": "www2026.pdf",
    "extractedAt": "2026-05-26T03:36:44.798Z",
    "entryCount": 271,
    "eventCount": 936,
    "lastReconciledAt": "2026-05-26T18:00:00+00:00"
  },
  "entries": [
    {
      "name": "Abrabrabra",
      "type": "camp",                       // camp | sound_stage | art_installation | mutant_vehicle
      "claimed": false,                     // injected by dashboard.php on every save
      "neighbourhood": "...",               // optional, from sheet
      "events": [
        {
          "owner": "Abrabrabra",
          "ownerType": "camp",
          "title": "Come play mini golf",
          "description": "...",
          "day": "Thursday",
          "startTime": "18:00",
          "endTime": "03:00",
          "durationHours": 9,
          "crossesMidnight": true,
          "tags": ["Game / Activity"],
          "rawTimeText": "Thursday 18:00 - 03:00",
          "normalizationFlags": []
        }
      ]
    }
  ]
}
```

The client renders `entries[].events[]` and uses `entry.claimed` to show the `✓ Verified` pill.


## Auto-sync

[`scripts/autosync.sh`](scripts/autosync.sh) runs on the prod droplet via cron and keeps the repo synced with `origin/master`:

1. `flock` guard — skip if another run is in flight.
2. `git pull --rebase --autostash origin master`.
3. `git add -A`.
4. If nothing to commit → exit.
5. Otherwise commit as `Otherworld autosync <autosync@otherworld.local>` with a message like `Auto sync: events.json, claims.php + 2 more (2026-05-26 14:30)`.
6. `git push origin master`.

All output goes to `admin/logs/autosync.log` (gitignored). The script is locked to one concurrent run.

### Cron entries on the droplet

```cron
PATH=/usr/local/bin:/usr/bin:/bin

# Auto-sync repo with origin/master every 10 minutes
*/10 * * * * /var/www/html/scripts/autosync.sh

# Pull camp/event updates from the shared Google Sheet every hour
0 * * * * cd /var/www/html && php admin/reconcile-sheet.php --apply >> admin/logs/reconcile.log 2>&1
```

The cron user needs git push access — set up via a GitHub deploy key (write-enabled) and an `~/.ssh/config` entry mapping `github.com` to that key.

---

## Reconcile (`admin/reconcile-sheet.php`)

Fetches the shared Google Sheet as CSV and reconciles into `events.json`. Two modes:

```bash
php admin/reconcile-sheet.php           # dry run — prints JSON preview, writes nothing
php admin/reconcile-sheet.php --apply   # actually writes events.json + snapshot
```

Reconciliation rules:

- **Unclaimed camps in sheet** — their event list in `events.json` is **replaced** with what the sheet currently says.
- **Unclaimed camps absent from sheet** — their events list is cleared (kept as an entry, but empty).
- **Claimed camps** — left **completely alone**. Owner edits via `dashboard.php` are authoritative.
- **Sheet camps with no matching entry** — added as new `type=camp` entries.

Camp-name matching uses a canonicaliser (`canonical()`) that strips accents, lowercases, removes leading `The ` / trailing `, the` / trailing ` Camp`, and reduces to `[a-z0-9]`. Fuzzy match (Levenshtein) catches typos within ~10% length, capped at 3 edits.

Tag columns from the sheet (`Workshop / Class`, `Music / Dance`, `19+`, etc.) are lifted verbatim into each event's `tags` array.

Logs to `admin/logs/reconcile/`.

---

## Versioning / snapshots

Every write to `events.json` from `dashboard.php` (or `reconcile-sheet.php --apply`) calls `/usr/local/bin/otherworld-snapshot events.json` first. That script copies the pre-edit content into `/var/www/otherworld-versions/events/events-YYYY-MM-DDTHH-MM-SSZ.json`, deduped against the immediate predecessor.

These snapshots live **outside** the repo and aren't pushed to GitHub. They're the rollback mechanism if a reconcile or dashboard save corrupts something.

---

---

## Pages

### Schedule (`index.html`)

Three views, all driven by `events.json`:

- **By Day** — timeline grouped by day (Thursday → Monday).
- **By Camp** — alphabetical list, each camp with its events.
- **Map** — pin overlay on `map.png`, driven by `map-data.js`.

Top-of-page filters: type (camp / sound stage / art installation / mutant vehicle) and free-text search across titles, camp names, and descriptions.

### Dashboard (`dashboard.php`)

The claim + edit UI. Three flows:

1. **Claim** — pick your camp from a dropdown, set a passphrase. Stored as an Argon2id hash in `claims.php`. Rate-limited at one successful claim per IP per 30 minutes.
2. **Unlock** — re-enter your passphrase to start editing a previously claimed camp.
3. **Edit** — add, edit, or remove events. Each save triggers an atomic write to `events.json`, a snapshot via `otherworld-snapshot`, and a regenerate of `data.js` (legacy artifact, see below).

Saves also bake the `claimed` flag into every entry in `events.json` so the front end can render the Verified pill.

### Changelog page (`changelog.php`)

Lists events in `events.json` that have no matching `(camp, title, day)` row in the Google Sheet. Rendered live — the page fetches the sheet CSV on every load, so it reflects the latest state.

Most rows will come from **claimed** camps whose owners edit on the site rather than the sheet; those are legitimate. **Unclaimed** rows are a hint that something drifted (sheet was edited but reconcile hasn't run yet, or the canonical-name match failed).

The `(camp, title, day)` match uses the same canonicalisation as `admin/reconcile-sheet.php::canonical()` — duplicated into `changelog.php`. Keep them in sync if you tweak the rules.

### CSV export (`events-csv.php`)

Browser hit → downloads a UTF-8 CSV with one row per event occurrence. Columns: `owner, owner_type, day, start_time, end_time, duration_hours, crosses_midnight, title, description, raw_time_text, normalization_flags`. Includes a BOM so Excel reads it correctly.

---

## Map data

The map view uses a different (older) pattern than the schedule view — it still loads via `<script src="./map-data.js">` rather than `fetch`. Source files:

- `map.png` — the rendered map image (large).
- `map-locations.json` — pin positions.
- `map-labels.json` — text clusters.
- `map-data.js` — compiled output, what the client loads. Regenerated by `parse-map.js` or by the `admin/save-pins.php` / `admin/reset-pins.php` endpoints.

If you ever edit `map-locations.json` or `map-labels.json` by hand, run `node parse-map.js` afterward to refresh `map-data.js`. (The parser also reads `map.pdf`, so manual edits will be clobbered if you also have an updated PDF in place.)
