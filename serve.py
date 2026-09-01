#!/usr/bin/env python3
"""Local stand-in for the cPanel PHP/MySQL API. Same HTML/JS and URL paths."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import re
import secrets
import sqlite3
import sys
from datetime import datetime
from http import cookies
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parent
DB_PATH = ROOT / "data" / "panel.sqlite"
MAX_NAME = 100
MAX_BODY = 1000
COOLDOWN_SECONDS = 10
VOTE_COOLDOWN_SECONDS = 2
SESSION_COOKIE = "panel_moderator"
VOTER_COOKIE = "panel_voter"
TOKEN_RE = re.compile(r"^[a-f0-9]{64}$")
SESSIONS: set[str] = set()


def moderator_password() -> str:
    return os.environ.get("MODERATOR_PASSWORD", "moderator")


def now_sql() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def connect() -> sqlite3.Connection:
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


def table_columns(conn: sqlite3.Connection, table: str) -> set[str]:
    return {row["name"] for row in conn.execute("PRAGMA table_info(%s)" % table)}


def init_db() -> None:
    with connect() as conn:
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS questions (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name TEXT,
              body TEXT NOT NULL,
              status TEXT NOT NULL DEFAULT 'pending'
                CHECK (status IN ('pending', 'asked', 'dismissed')),
              visible INTEGER NOT NULL DEFAULT 0,
              is_current INTEGER NOT NULL DEFAULT 0,
              ip_hash TEXT NOT NULL,
              created_at TEXT NOT NULL
            )
            """
        )
        cols = table_columns(conn, "questions")
        if "visible" not in cols:
            conn.execute("ALTER TABLE questions ADD COLUMN visible INTEGER NOT NULL DEFAULT 0")
        if "is_current" not in cols:
            conn.execute("ALTER TABLE questions ADD COLUMN is_current INTEGER NOT NULL DEFAULT 0")
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS votes (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              question_id INTEGER NOT NULL,
              voter_token TEXT NOT NULL,
              value INTEGER NOT NULL DEFAULT 1,
              created_at TEXT NOT NULL,
              UNIQUE (question_id, voter_token),
              FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
            )
            """
        )
        if "value" not in table_columns(conn, "votes"):
            conn.execute("ALTER TABLE votes ADD COLUMN value INTEGER NOT NULL DEFAULT 1")
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS vote_limits (
              voter_token TEXT PRIMARY KEY,
              last_vote_at TEXT NOT NULL
            )
            """
        )


def ip_hash(address: str) -> str:
    return hashlib.sha256(address.encode("utf-8")).hexdigest()


def question_payload(row: sqlite3.Row) -> dict:
    payload = {
        "id": int(row["id"]),
        "name": row["name"],
        "body": row["body"],
        "status": row["status"],
        "created_at": row["created_at"],
    }
    keys = row.keys()
    if "visible" in keys:
        payload["visible"] = int(row["visible"] or 0) == 1
    if "is_current" in keys:
        payload["is_current"] = int(row["is_current"] or 0) == 1
    if "vote_count" in keys:
        payload["vote_count"] = int(row["vote_count"] or 0)
    if "my_vote" in keys:
        payload["my_vote"] = int(row["my_vote"] or 0)
    return payload


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT), **kwargs)

    def log_message(self, format: str, *args) -> None:
        sys.stderr.write("%s - %s\n" % (self.address_string(), format % args))

    def end_headers(self) -> None:
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def json_body(self) -> dict:
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b""
        if not raw:
            return {}
        try:
            data = json.loads(raw.decode("utf-8"))
        except (ValueError, UnicodeDecodeError):
            return {}
        return data if isinstance(data, dict) else {}

    def send_json(self, payload: dict, status: int = 200, extra_headers: list | None = None) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        if extra_headers:
            for name, value in extra_headers:
                self.send_header(name, value)
        self.end_headers()
        self.wfile.write(body)

    def cookie_jar(self) -> cookies.SimpleCookie:
        jar = cookies.SimpleCookie()
        raw = self.headers.get("Cookie")
        if raw:
            try:
                jar.load(raw)
            except cookies.CookieError:
                pass
        return jar

    def session_token(self) -> str | None:
        morsel = self.cookie_jar().get(SESSION_COOKIE)
        if not morsel:
            return None
        token = morsel.value
        return token if token in SESSIONS else None

    def require_moderator(self) -> bool:
        if self.session_token():
            return True
        self.send_json({"error": "Unauthorized."}, 401)
        return False

    def voter_token(self) -> tuple[str, bool]:
        morsel = self.cookie_jar().get(VOTER_COOKIE)
        if morsel and TOKEN_RE.match(morsel.value):
            return morsel.value, False
        header = self.headers.get("X-Voter-Token") or ""
        if TOKEN_RE.match(header):
            return header, True
        return secrets.token_hex(32), True

    def voter_headers(self, token: str, set_cookie: bool) -> list | None:
        if not set_cookie:
            return None
        return [
            (
                "Set-Cookie",
                "%s=%s; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax" % (VOTER_COOKIE, token),
            )
        ]

    def do_GET(self) -> None:
        if self.handle_api("GET"):
            return
        if self.blocked_static():
            self.send_json({"error": "Not found."}, 404)
            return
        super().do_GET()

    def do_POST(self) -> None:
        if self.handle_api("POST"):
            return
        self.send_json({"error": "Method not allowed."}, 405)

    def blocked_static(self) -> bool:
        path = urlparse(self.path).path
        return path.startswith("/data/") or path.startswith("/api/") or path.startswith("/.git/")

    def handle_api(self, method: str) -> bool:
        path = urlparse(self.path).path
        routes = {
            "/api/submit.php": ("POST", self.api_submit),
            "/api/login.php": ("POST", self.api_login),
            "/api/logout.php": ("POST", self.api_logout),
            "/api/questions.php": ("GET", self.api_questions),
            "/api/status.php": ("POST", self.api_status),
            "/api/wall.php": ("GET", self.api_wall),
            "/api/vote.php": ("POST", self.api_vote),
            "/api/current.php": ("GET", self.api_current),
        }
        if path not in routes:
            if path.startswith("/api/"):
                self.send_json({"error": "Not found."}, 404)
                return True
            return False

        expected, handler = routes[path]
        if method != expected:
            self.send_json({"error": "Method not allowed."}, 405)
            return True
        handler()
        return True

    def api_submit(self) -> None:
        data = self.json_body()
        name = str(data.get("name") or "").strip()
        body = str(data.get("body") or "").strip()
        if not name:
            name = None
        elif len(name) > MAX_NAME:
            self.send_json({"error": "Name is too long."}, 400)
            return
        if not body:
            self.send_json({"error": "Please enter a question."}, 400)
            return
        if len(body) > MAX_BODY:
            self.send_json({"error": "Question is too long."}, 400)
            return

        hashed = ip_hash(self.client_address[0])
        with connect() as conn:
            row = conn.execute(
                "SELECT created_at FROM questions WHERE ip_hash = ? ORDER BY id DESC LIMIT 1",
                (hashed,),
            ).fetchone()
            if row:
                try:
                    last = datetime.strptime(row["created_at"], "%Y-%m-%d %H:%M:%S")
                    elapsed = (datetime.now() - last).total_seconds()
                except ValueError:
                    elapsed = COOLDOWN_SECONDS
                if 0 <= elapsed < COOLDOWN_SECONDS:
                    self.send_json(
                        {
                            "error": "Please wait a few seconds before sending another question.",
                            "retry_after": int(COOLDOWN_SECONDS - elapsed),
                        },
                        429,
                    )
                    return
            cur = conn.execute(
                "INSERT INTO questions (name, body, status, visible, is_current, ip_hash, created_at) VALUES (?, ?, 'pending', 0, 0, ?, ?)",
                (name, body, hashed, now_sql()),
            )
            conn.commit()
            new_id = int(cur.lastrowid)
        self.send_json({"ok": True, "id": new_id}, 201)

    def api_login(self) -> None:
        data = self.json_body()
        password = str(data.get("password") or "")
        expected = moderator_password()
        got = hashlib.sha256(password.encode("utf-8")).digest()
        want = hashlib.sha256(expected.encode("utf-8")).digest()
        if not password or not hmac.compare_digest(got, want):
            self.send_json({"error": "Incorrect password."}, 401)
            return
        token = secrets.token_urlsafe(32)
        SESSIONS.add(token)
        cookie = "%s=%s; Path=/; HttpOnly; SameSite=Lax" % (SESSION_COOKIE, token)
        self.send_json({"ok": True}, 200, extra_headers=[("Set-Cookie", cookie)])

    def api_logout(self) -> None:
        token = self.session_token()
        if token:
            SESSIONS.discard(token)
        expired = "%s=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0" % SESSION_COOKIE
        self.send_json({"ok": True}, 200, extra_headers=[("Set-Cookie", expired)])

    def api_questions(self) -> None:
        if not self.require_moderator():
            return
        pending = []
        handled = []
        with connect() as conn:
            rows = conn.execute(
                """
                SELECT q.id, q.name, q.body, q.status, q.created_at, q.visible, q.is_current,
                       (SELECT COALESCE(SUM(v.value), 0) FROM votes v WHERE v.question_id = q.id) AS vote_count
                FROM questions q
                ORDER BY CASE q.status WHEN 'pending' THEN 0 ELSE 1 END ASC,
                         CASE q.status WHEN 'pending' THEN q.created_at END ASC,
                         q.created_at DESC
                """
            ).fetchall()
        for row in rows:
            item = question_payload(row)
            if row["status"] == "pending":
                pending.append(item)
            else:
                handled.append(item)
        self.send_json({"pending": pending, "handled": handled})

    def api_status(self) -> None:
        if not self.require_moderator():
            return
        data = self.json_body()
        try:
            question_id = int(data.get("id") or 0)
        except (TypeError, ValueError):
            question_id = 0
        if question_id < 1:
            self.send_json({"error": "Question id is required."}, 400)
            return

        if "visible" in data:
            visible = 1 if data.get("visible") else 0
            with connect() as conn:
                if visible:
                    cur = conn.execute(
                        "UPDATE questions SET visible = 1 WHERE id = ? AND status = 'pending'",
                        (question_id,),
                    )
                else:
                    cur = conn.execute(
                        "UPDATE questions SET visible = 0, is_current = 0 WHERE id = ? AND status = 'pending'",
                        (question_id,),
                    )
                conn.commit()
                updated = cur.rowcount
            if updated < 1:
                self.send_json({"error": "Question not found or already handled."}, 404)
                return
            self.send_json({"ok": True})
            return

        if "current" in data:
            with connect() as conn:
                if data.get("current"):
                    conn.execute("UPDATE questions SET is_current = 0")
                    cur = conn.execute(
                        "UPDATE questions SET is_current = 1, visible = 1 WHERE id = ? AND status = 'pending'",
                        (question_id,),
                    )
                    conn.commit()
                    if cur.rowcount < 1:
                        self.send_json({"error": "Question not found or already handled."}, 404)
                        return
                else:
                    conn.execute("UPDATE questions SET is_current = 0 WHERE id = ?", (question_id,))
                    conn.commit()
            self.send_json({"ok": True})
            return

        status = str(data.get("status") or "")
        if status == "pending":
            with connect() as conn:
                cur = conn.execute(
                    "UPDATE questions SET status = 'pending', visible = 0, is_current = 0 WHERE id = ? AND status IN ('asked', 'dismissed')",
                    (question_id,),
                )
                conn.commit()
                updated = cur.rowcount
            if updated < 1:
                self.send_json({"error": "Question not found or is already pending."}, 404)
                return
            self.send_json({"ok": True})
            return
        if status not in ("asked", "dismissed"):
            self.send_json({"error": "Provide status, visible, or current."}, 400)
            return
        with connect() as conn:
            cur = conn.execute(
                "UPDATE questions SET status = ?, visible = 0, is_current = 0 WHERE id = ? AND status = 'pending'",
                (status, question_id),
            )
            conn.commit()
            updated = cur.rowcount
        if updated < 1:
            self.send_json({"error": "Question not found or already handled."}, 404)
            return
        self.send_json({"ok": True})

    def api_wall(self) -> None:
        token, set_cookie = self.voter_token()
        with connect() as conn:
            rows = conn.execute(
                """
                SELECT q.id, q.name, q.body, q.status, q.created_at, q.visible, q.is_current,
                       (SELECT COALESCE(SUM(v.value), 0) FROM votes v WHERE v.question_id = q.id) AS vote_count,
                       (SELECT COALESCE(SUM(v2.value), 0) FROM votes v2
                         WHERE v2.question_id = q.id AND v2.voter_token = ?) AS my_vote
                FROM questions q
                WHERE q.visible = 1 AND q.status = 'pending'
                ORDER BY vote_count DESC, q.created_at ASC
                """,
                (token,),
            ).fetchall()
        questions = [question_payload(row) for row in rows]
        self.send_json(
            {"questions": questions},
            extra_headers=self.voter_headers(token, set_cookie),
        )

    def api_vote(self) -> None:
        data = self.json_body()
        try:
            question_id = int(data.get("id") or 0)
        except (TypeError, ValueError):
            question_id = 0
        if question_id < 1:
            self.send_json({"error": "Question id is required."}, 400)
            return

        try:
            value = int(data.get("value", 1))
        except (TypeError, ValueError):
            value = 0
        if value not in (1, -1):
            self.send_json({"error": "Vote value must be 1 or -1."}, 400)
            return

        token, set_cookie = self.voter_token()
        with connect() as conn:
            row = conn.execute(
                "SELECT id FROM questions WHERE id = ? AND visible = 1 AND status = 'pending'",
                (question_id,),
            ).fetchone()
            if not row:
                self.send_json({"error": "Question is not on the wall."}, 404)
                return

            limit = conn.execute(
                "SELECT last_vote_at FROM vote_limits WHERE voter_token = ?",
                (token,),
            ).fetchone()
            if limit:
                try:
                    last = datetime.strptime(limit["last_vote_at"], "%Y-%m-%d %H:%M:%S")
                    elapsed = (datetime.now() - last).total_seconds()
                except ValueError:
                    elapsed = VOTE_COOLDOWN_SECONDS
                if 0 <= elapsed < VOTE_COOLDOWN_SECONDS:
                    self.send_json(
                        {
                            "error": "Please wait a moment before voting again.",
                            "retry_after": int(VOTE_COOLDOWN_SECONDS - elapsed),
                        },
                        429,
                    )
                    return

            conn.execute(
                """
                INSERT INTO vote_limits (voter_token, last_vote_at) VALUES (?, ?)
                ON CONFLICT(voter_token) DO UPDATE SET last_vote_at = excluded.last_vote_at
                """,
                (token, now_sql()),
            )
            existing = conn.execute(
                "SELECT id, value FROM votes WHERE question_id = ? AND voter_token = ?",
                (question_id, token),
            ).fetchone()
            my_vote = 0
            if existing is None:
                conn.execute(
                    "INSERT INTO votes (question_id, voter_token, value, created_at) VALUES (?, ?, ?, ?)",
                    (question_id, token, value, now_sql()),
                )
                my_vote = value
            elif int(existing["value"]) == value:
                conn.execute("DELETE FROM votes WHERE id = ?", (existing["id"],))
            else:
                conn.execute(
                    "UPDATE votes SET value = ? WHERE id = ?",
                    (value, existing["id"]),
                )
                my_vote = value
            count = conn.execute(
                "SELECT COALESCE(SUM(value), 0) AS n FROM votes WHERE question_id = ?",
                (question_id,),
            ).fetchone()
            conn.commit()
            n = int(count["n"]) if count else 0
        self.send_json(
            {"ok": True, "my_vote": my_vote, "vote_count": n},
            extra_headers=self.voter_headers(token, set_cookie),
        )

    def api_current(self) -> None:
        with connect() as conn:
            row = conn.execute(
                """
                SELECT q.id, q.name, q.body, q.status, q.created_at, q.visible, q.is_current,
                       (SELECT COALESCE(SUM(v.value), 0) FROM votes v WHERE v.question_id = q.id) AS vote_count
                FROM questions q
                WHERE q.is_current = 1 AND q.status = 'pending'
                LIMIT 1
                """
            ).fetchone()
        self.send_json({"question": question_payload(row) if row else None})


def lan_urls(port: int) -> list[str]:
    urls = ["http://127.0.0.1:%s/" % port]
    try:
        import socket

        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
            sock.connect(("8.8.8.8", 80))
            host = sock.getsockname()[0]
        if host and host not in ("127.0.0.1", "::1"):
            urls.append("http://%s:%s/" % (host, port))
    except OSError:
        pass
    return urls


def main() -> None:
    parser = argparse.ArgumentParser(description="Run the panel Q&A site locally (no cPanel required).")
    parser.add_argument("--host", default="0.0.0.0")
    parser.add_argument("--port", type=int, default=8080)
    args = parser.parse_args()

    os.chdir(ROOT)
    init_db()
    server = ThreadingHTTPServer((args.host, args.port), Handler)
    password = moderator_password()
    print("CSCD panel Q&A (local)", flush=True)
    print("Database: %s" % DB_PATH, flush=True)
    print("Moderator password: %s" % password, flush=True)
    print("Override with MODERATOR_PASSWORD=... if you want.", flush=True)
    for url in lan_urls(args.port):
        print("Students:  %s" % url, flush=True)
        print("Wall:      %swall.html" % url, flush=True)
        print("Display:   %sdisplay.html" % url, flush=True)
        print("Moderator: %smoderator.html" % url, flush=True)
    print("Ctrl+C to stop.", flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nStopped.")


if __name__ == "__main__":
    main()
