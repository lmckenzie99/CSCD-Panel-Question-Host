# CSCD Panel Question Host

Live Q&A for a panel: students submit questions; a moderator reads them privately to the panelists.

There is no public question list. Students only see the submit form.

## Pages

| URL | Who |
|---|---|
| `/` or `index.html` | Students — submit a question (name optional) |
| `/moderator.html` | Moderator — password-protected queue |

## Run locally (no cPanel)

You do not need MySQL or PHP for this. From the repo root:

```bash
python3 serve.py
```

Then open the student URL and `moderator.html` printed in the terminal.

- Default moderator password: `moderator`
- Questions are stored in `data/panel.sqlite` (gitignored)
- On the same Wi-Fi, phones can use the LAN URL the script prints

To pick a password:

```bash
MODERATOR_PASSWORD='your-password' python3 serve.py
```

Stop with Ctrl+C.

## Later: cPanel (PHP + MySQL)

When the cPanel database is ready, deploy the same HTML/JS and the `api/` PHP files. `serve.py` is only for local use.

### 1. MySQL

In cPanel, create a database and a user, and grant the user all privileges on that database. Import [`sql/schema.sql`](sql/schema.sql) with phpMyAdmin (Import) or the MySQL command line.

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

## Moderator flow

Sign in on `moderator.html`. Pending questions appear oldest first and refresh about every two seconds. **Asked** and **Dismiss** move a question out of the live queue into **Already handled**.

A public question wall, voting, and a panelist-facing display are not in this version.
