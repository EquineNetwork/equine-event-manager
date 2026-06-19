# Laptop Setup — mirror the desktop dev workflow exactly

Goal: on the laptop, Claude Code edits the git repo while a **Local** site (symlinked to
that repo) serves the plugin live — so previews, opcache resets, and `wp` commands work
exactly like the desktop. This is the same wiring the desktop uses:

```
Git repo (~/Projects/equine-event-manager)              ← edit + commit + push here
        ▲  symlink
Local site wp-content/plugins/equine-event-manager      ← Local serves this (live preview)
```

## Reference values (from the desktop, 2026-06-18)

| Thing | Value |
|---|---|
| Repo path | `~/Projects/equine-event-manager` |
| Git remote | `https://github.com/EquineNetwork/equine-event-manager.git` |
| Working branch | `main` (also `v4-stall-mapping`; they're in sync) |
| Local site name | `en-event-manager` |
| Site URL | `http://en-event-manager.local` |
| Local site public dir | `~/Local Sites/en-event-manager/app/public/` |
| Local PHP version | `php-8.2.29+0` (use the same in Local so the binary path matches) |
| Local PHP binary | `/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php` |
| Test reservations | NTR **6519** (safe). Do NOT test on **5990** — its RV map is corrupted. |

## One-time setup on the laptop

1. **Install** Local (localwp.com) and Claude Code Desktop (already done).

2. **Clone the repo** to the same path as the desktop:
   ```
   git clone https://github.com/EquineNetwork/equine-event-manager.git ~/Projects/equine-event-manager
   ```

3. **Bring the Local site over** (so you have the same DB: reservations, orders, stall maps):
   - On the **desktop**: open Local → right-click the `en-event-manager` site → **Export** → save the `.zip`.
   - Move the zip to the laptop (AirDrop / iCloud / USB).
   - On the **laptop**: Local → **Import** the zip. Keep the site name `en-event-manager` so the
     URL stays `http://en-event-manager.local`.
   - When Local asks for a PHP version, pick **8.2.29** (matches the binary path above). If that
     exact build isn't offered, pick the closest 8.2.x and update the PHP-binary path in any
     command that references it (the version folder name is the only thing that changes).

4. **Re-point the plugin to the repo as a symlink** (Import creates a real folder; replace it):
   ```
   rm -rf "$HOME/Local Sites/en-event-manager/app/public/wp-content/plugins/equine-event-manager"
   ln -s "$HOME/Projects/equine-event-manager" "$HOME/Local Sites/en-event-manager/app/public/wp-content/plugins/equine-event-manager"
   ```
   (Run each line as its own command — no `&&` — per the command-hygiene rule in CLAUDE.md.)

5. **Start the site** in Local and confirm `http://en-event-manager.local/wp-admin` loads with the
   plugin active under **Event Manager**.

## Daily flow on the laptop (identical to desktop)

- Pull latest before starting: `git pull` (on `main`).
- Edit code in the repo; Claude verifies via the preview/opcache pattern in CLAUDE.md
  ("Command hygiene" + "Verification commands" sections).
- Commit, then `git push origin main`.

## If you DON'T want the live site (code-only road work)

Steps 1–2 alone are enough to edit + commit + push. You just won't get browser previews; verify
visually next time you're on a machine that has the Local site. Everything still lands on GitHub.

## Notes / gotchas

- The opcache-reset trick writes `_eem_oc.php` into the site's `public/` then `curl`s it; it only
  works when Local is running and the symlink is in place.
- If the laptop's Local PHP version differs from `8.2.29`, every command that hardcodes the PHP
  binary path needs the version-folder segment updated to match — that's the only path that drifts.
- The database (reservations/orders/maps) lives in Local's MySQL, **not** in git. The Export/Import
  in step 3 is what carries it across machines; a plain `git clone` does not.
