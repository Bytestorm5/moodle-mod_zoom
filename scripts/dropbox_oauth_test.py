#!/usr/bin/env python3
import argparse
import base64
import json
import os
import sys
import time
from typing import Tuple, Optional

import requests
import secrets
import webbrowser
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlencode, urlparse, parse_qs

TOKEN_URL = "https://api.dropboxapi.com/oauth2/token"
UPLOAD_URL = "https://content.dropboxapi.com/2/files/upload"
CREATE_LINK_URL = "https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings"
AUTHORIZE_URL = "https://www.dropbox.com/oauth2/authorize"


def mask(s: str, show: int = 4) -> str:
    if not s:
        return ""
    if len(s) <= show * 2:
        return s[0] + "*" * max(0, len(s) - 2) + s[-1]
    return s[:show] + "..." + s[-show:]


def refresh_access_token(app_key: str, app_secret: str, refresh_token: str) -> Tuple[str, int]:
    auth = base64.b64encode(f"{app_key}:{app_secret}".encode("utf-8")).decode("ascii")
    headers = {
        "Authorization": f"Basic {auth}",
        "Content-Type": "application/x-www-form-urlencoded",
    }
    data = {
        "grant_type": "refresh_token",
        "refresh_token": refresh_token,
    }
    resp = requests.post(TOKEN_URL, headers=headers, data=data, timeout=60)
    print(f"[token] HTTP {resp.status_code}")
    if resp.status_code >= 400:
        print(f"[token] Body: {resp.text}")
        resp.raise_for_status()
    payload = resp.json()
    access_token = payload.get("access_token", "")
    expires_in = int(payload.get("expires_in", 3600))
    scope = payload.get("scope", "")
    token_type = payload.get("token_type", "")
    print(f"[token] token_type={token_type}, scope={scope}, expires_in={expires_in}s")
    if not access_token:
        raise RuntimeError("No access_token in response")
    # Return token and an absolute expiry timestamp (now + expires_in - small buffer)
    return access_token, int(time.time()) + max(300, expires_in - 60)


def build_authorize_url(app_key: str, redirect_uri: str, scope: Optional[str], state: str) -> str:
    params = {
        "client_id": app_key,
        "response_type": "code",
        "redirect_uri": redirect_uri,
        # Critical for returning a refresh_token:
        "token_access_type": "offline",
        "state": state,
    }
    if scope:
        # Space separated list per Dropbox spec.
        params["scope"] = scope
    return f"{AUTHORIZE_URL}?{urlencode(params)}"


def exchange_code_for_tokens(app_key: str, app_secret: str, code: str, redirect_uri: str) -> Tuple[str, int, str]:
    """Return (access_token, expiry_ts, refresh_token)."""
    auth = base64.b64encode(f"{app_key}:{app_secret}".encode("utf-8")).decode("ascii")
    headers = {
        "Authorization": f"Basic {auth}",
        "Content-Type": "application/x-www-form-urlencoded",
    }
    data = {
        "grant_type": "authorization_code",
        "code": code,
        "redirect_uri": redirect_uri,
    }
    resp = requests.post(TOKEN_URL, headers=headers, data=data, timeout=60)
    print(f"[code->token] HTTP {resp.status_code}")
    if resp.status_code >= 400:
        print(f"[code->token] Body: {resp.text}")
        resp.raise_for_status()
    payload = resp.json()
    access_token = payload.get("access_token", "")
    refresh_token = payload.get("refresh_token", "")
    expires_in = int(payload.get("expires_in", 3600))
    if not access_token or not refresh_token:
        raise RuntimeError("Missing access_token or refresh_token in token response")
    expiry = int(time.time()) + max(300, expires_in - 60)
    print("[code->token] Received short-lived access token and refresh token")
    return access_token, expiry, refresh_token


class _AuthHandler(BaseHTTPRequestHandler):
    code: Optional[str] = None
    state_expected: str = ""

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path != "/callback":
            self.send_response(404)
            self.end_headers()
            self.wfile.write(b"Not Found")
            return
        qs = parse_qs(parsed.query)
        code = qs.get("code", [None])[0]
        state = qs.get("state", [None])[0]
        if not code or state != self.state_expected:
            self.send_response(400)
            self.end_headers()
            self.wfile.write(b"Invalid request")
            return
        _AuthHandler.code = code
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b"Authorization complete. You may close this window.")


def run_local_auth_server(host: str, port: int, state: str, timeout: int = 300) -> Optional[str]:
    _AuthHandler.state_expected = state
    server = HTTPServer((host, port), _AuthHandler)
    server.timeout = 1
    print(f"[auth] Waiting for redirect at http://{host}:{port}/callback (timeout {timeout}s)...")
    start = time.time()
    code: Optional[str] = None
    try:
        while time.time() - start < timeout and _AuthHandler.code is None:
            server.handle_request()
        code = _AuthHandler.code
    finally:
        server.server_close()
    return code


def upload_simple(access_token: str, dropbox_path: str, content: bytes) -> dict:
    if not dropbox_path.startswith("/"):
        dropbox_path = "/" + dropbox_path
    headers = {
        "Authorization": f"Bearer {access_token}",
        "Content-Type": "application/octet-stream",
        "Dropbox-API-Arg": json.dumps({
            "path": dropbox_path,
            "mode": "add",
            "autorename": True,
            "mute": False,
            "strict_conflict": False
        })
    }
    resp = requests.post(UPLOAD_URL, headers=headers, data=content, timeout=120)
    print(f"[upload] HTTP {resp.status_code} path={dropbox_path}")
    if resp.status_code >= 400:
        print(f"[upload] Body: {resp.text}")
        resp.raise_for_status()
    meta = resp.json()
    print(f"[upload] OK name={meta.get('name')} id={meta.get('id')}")
    return meta


def create_shared_link(access_token: str, dropbox_path: str) -> str:
    if not dropbox_path.startswith("/"):
        dropbox_path = "/" + dropbox_path
    headers = {
        "Authorization": f"Bearer {access_token}",
        "Content-Type": "application/json",
    }
    payload = {
        "path": dropbox_path,
        "settings": {}
    }
    resp = requests.post(CREATE_LINK_URL, headers=headers, json=payload, timeout=60)
    print(f"[link] HTTP {resp.status_code} path={dropbox_path}")
    if resp.status_code == 409:
        # Maybe shared_link_already_exists: try listing
        print(f"[link] Body: {resp.text}")
        list_url = "https://api.dropboxapi.com/2/sharing/list_shared_links"
        resp2 = requests.post(
            list_url,
            headers=headers,
            json={"path": dropbox_path, "direct_only": True},
            timeout=60
        )
        print(f"[link:list] HTTP {resp2.status_code}")
        if resp2.status_code >= 400:
            print(f"[link:list] Body: {resp2.text}")
            resp2.raise_for_status()
        links = resp2.json().get("links", [])
        if links:
            url = links[0].get("url", "")
            print(f"[link] Found existing link: {url}")
            return url
        raise RuntimeError("No existing shared links found")
    if resp.status_code >= 400:
        print(f"[link] Body: {resp.text}")
        resp.raise_for_status()
    url = resp.json().get("url", "")
    print(f"[link] OK url={url}")
    return url


def main():
    parser = argparse.ArgumentParser(description="Test Dropbox OAuth refresh and simple upload.")
    parser.add_argument("--app-key", default=os.getenv("DROPBOX_APP_KEY"), help="Dropbox App Key")
    parser.add_argument("--app-secret", default=os.getenv("DROPBOX_APP_SECRET"), help="Dropbox App Secret")
    parser.add_argument("--refresh-token", default=os.getenv("DROPBOX_REFRESH_TOKEN"), help="Dropbox Refresh Token")
    parser.add_argument("--static-token", default=os.getenv("DROPBOX_ACCESS_TOKEN"),
                        help="Optional: static access token (skips refresh)")
    parser.add_argument("--path", default=os.getenv("DROPBOX_TEST_PATH", "/moodle-zoom-test/hello.txt"),
                        help="Dropbox path to upload (default: /moodle-zoom-test/hello.txt)")
    parser.add_argument("--file", default=None, help="Local file to upload; if omitted, uploads 'hello world'")
    parser.add_argument("--link", action="store_true", help="Create a shared link after upload")
    parser.add_argument("--authorize", action="store_true", help="Run authorization code flow to obtain a refresh token if missing")
    parser.add_argument("--redirect-host", default=os.getenv("DROPBOX_REDIRECT_HOST", "127.0.0.1"), help="Redirect host (default 127.0.0.1)")
    parser.add_argument("--redirect-port", type=int, default=int(os.getenv("DROPBOX_REDIRECT_PORT", "53682")), help="Redirect port (default 53682)")
    parser.add_argument("--scope", default=os.getenv("DROPBOX_SCOPE", ""), help="Optional scopes (space-separated)")
    args = parser.parse_args()

    print(f"[args] app_key={mask(args.app_key)} app_secret={mask(args.app_secret)} "
          f"refresh_token={mask(args.refresh_token)} static_token={mask(args.static_token)}")
    token: Optional[str] = None
    expiry_ts: int = 0

    try:
        if args.static_token:
            print("[flow] Using static access token (no refresh).")
            token = args.static_token
        else:
            refresh_token_val = args.refresh_token
            if not (args.app_key and args.app_secret):
                print("ERROR: --app-key and --app-secret are required for refresh/authorize flows")
                sys.exit(2)
            if not refresh_token_val:
                if not args.authorize:
                    print("ERROR: No refresh token. Re-run with --authorize to obtain one, or pass --static-token.")
                    sys.exit(2)
                # Run authorization code flow to obtain a refresh token.
                state = secrets.token_urlsafe(24)
                redirect_uri = f"http://{args.redirect_host}:{args.redirect_port}/callback"
                url = build_authorize_url(args.app_key, redirect_uri, args.scope if args.scope else None, state)
                print("[auth] Opening browser for Dropbox authorization...")
                print(f"[auth] URL: {url}")
                try:
                    webbrowser.open(url)
                except Exception:
                    pass
                code = run_local_auth_server(args.redirect_host, args.redirect_port, state)
                if not code:
                    print("ERROR: Authorization did not complete (no code received).")
                    sys.exit(2)
                # Exchange code for tokens.
                access_token_new, expiry_ts, refresh_token_new = exchange_code_for_tokens(
                    args.app_key, args.app_secret, code, redirect_uri
                )
                print(f"[auth] REFRESH TOKEN (save this securely): {refresh_token_new}")
                # Use the newly acquired refresh token.
                refresh_token_val = refresh_token_new
            # Now refresh access token for use.
            print("[flow] Refreshing access token using app key/secret + refresh token...")
            token, expiry_ts = refresh_access_token(args.app_key, args.app_secret, refresh_token_val)
            print(f"[flow] Access token acquired; expires_at={expiry_ts} ({expiry_ts - int(time.time())}s left)")
    except Exception as e:
        print(f"[error] Token acquisition failed: {e}")
        sys.exit(1)

    data = b"hello from moodle-mod_zoom dropbox oauth test\n"
    if args.file:
        try:
            with open(args.file, "rb") as f:
                data = f.read()
        except Exception as e:
            print(f"[error] Failed to read file '{args.file}': {e}")
            sys.exit(3)

    # Upload and handle 401 -> refresh retry (only if we used refresh flow).
    try:
        upload_simple(token, args.path, data)
    except requests.HTTPError as e:
        body = e.response.text if e.response is not None else ""
        if e.response is not None and e.response.status_code == 401 and "expired_access_token" in body \
           and not args.static_token and args.app_key and args.app_secret and args.refresh_token:
            print("[flow] Token expired during upload; refreshing and retrying once...")
            token, expiry_ts = refresh_access_token(args.app_key, args.app_secret, args.refresh_token)
            upload_simple(token, args.path, data)
        else:
            print(f"[error] Upload failed: HTTP {getattr(e.response, 'status_code', 'NA')} Body: {body}")
            raise

    if args.link:
        try:
            url = create_shared_link(token, args.path)
            # Force direct download (dl=1)
            if "dl=" not in url:
                url = url + ("?dl=1" if "?" not in url else "&dl=1")
            print(f"[result] Shared link: {url}")
        except requests.HTTPError as e:
            body = e.response.text if e.response is not None else ""
            if e.response is not None and e.response.status_code == 401 and "expired_access_token" in body \
               and not args.static_token and args.app_key and args.app_secret and args.refresh_token:
                print("[flow] Token expired creating link; refreshing and retrying once...")
                token, expiry_ts = refresh_access_token(args.app_key, args.app_secret, args.refresh_token)
                url = create_shared_link(token, args.path)
                if "dl=" not in url:
                    url = url + ("?dl=1" if "?" not in url else "&dl=1")
                print(f"[result] Shared link: {url}")
            else:
                print(f"[error] Create link failed: HTTP {getattr(e.response, 'status_code', 'NA')} Body: {body}")
                raise

    print("[done] Success.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
