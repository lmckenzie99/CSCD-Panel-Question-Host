# CSCD Panel Question Host

Live Q&A for a panel: students submit questions; a moderator decides what the room sees and what is read aloud.

**This `wall-votes` branch** adds a public question list with per-item voting and a panelist display. Submitting still does not publish — the moderator has to share a question onto the wall.

## Pages

| URL | Who |
|---|---|
| `/` or `index.html` | Students — submit, then vote on shared questions in the list below the form |
| `/wall.html` | Audience — the same voted list without the submit form |
| `/display.html` | Projector / panelists — current “now reading” question |
| `/moderator.html` | Moderator — private queue, share to wall, now reading, asked/dismiss |

## Run locally (no cPanel)

You do not need MySQL or PHP for this. From the repo root:

```bash
python3 serve.py
```

Then open the URLs printed in the terminal.

- Default moderator password: `moderator`
- Questions are stored in `data/panel.sqlite` (gitignored)
- On the same Wi-Fi, phones can use the LAN URL the script prints

To pick a password:

```bash
MODERATOR_PASSWORD='your-password' python3 serve.py
```

Stop with Ctrl+C.

## Moderator flow

1. Students submit on `index.html` (stays private).
2. On `moderator.html`, **Show on wall** puts a question on the public list. People vote on that row.
3. **Now reading** sends it to `display.html` (and onto the wall if it was not there yet).
4. **Asked** or **Dismiss** clears it from the wall and the display. **Undo** in Already handled puts it back in the pending queue.

## Later: cPanel (PHP + MySQL)

When the cPanel database is ready, deploy the same HTML/JS and the `api/` PHP files. `serve.py` is only for local use.

### 1. MySQL

In cPanel, create a database and a user, and grant the user all privileges on that database.

- New database: import [`sql/schema.sql`](sql/schema.sql)
- Existing v1 database: import [`sql/migrate_wall.sql`](sql/migrate_wall.sql)

### 2. App config

This file is not in git. On the server:

```bash
cp api/config.example.php api/config.php
```

Edit `api/config.php`:

- `db.host` is usually `localhost`
- `db.name`, `db.user`, and `db.pass` are the cPanel-prefixed values (for example `account_cscdpanel`)
- `moderator_password_hash` is a hash, not the password itself

Generate the hash on a machine with PHP, or in cPanel Terminal:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Paste the full output into `moderator_password_hash`.

### 3. Deploy from GitHub

Pull this repository into the site document root the same way as your DBS class site (cPanel Git Version Control, or a hook that `git pull`s).

After the first pull, create `api/config.php` on the server so later pulls do not overwrite credentials (the file is gitignored).

Students and the moderator should use `https://`.
