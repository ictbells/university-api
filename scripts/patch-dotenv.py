#!/usr/bin/env python3
"""Patch or read KEY=value lines in a dotenv file. JSON object on stdin to set; --get KEY to read."""
from __future__ import annotations

import argparse
import base64
import json
import os
import sys
from pathlib import Path

AES256_KEY_BYTES = 32


def quote(value: str) -> str:
    escaped = (
        str(value)
        .replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("$", "\\$")
        .replace("\n", "\\n")
        .replace("\r", "")
    )
    return f'"{escaped}"'


def unquote(value: str) -> str:
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
        inner = value[1:-1]
        return inner.replace("\\$", "$").replace('\\"', '"').replace("\\\\", "\\")
    return value


def get_key(path: Path, key: str) -> str | None:
    if not path.is_file():
        return None
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, _, v = line.partition("=")
        if k.strip() == key:
            return unquote(v)
    return None


def apply_updates(path: Path, updates: dict[str, str]) -> None:
    existing = path.read_text(encoding="utf-8") if path.is_file() else ""
    seen: set[str] = set()
    out: list[str] = []
    for raw in existing.splitlines():
        stripped = raw.strip()
        if stripped and not stripped.startswith("#") and "=" in raw:
            k = raw.split("=", 1)[0].strip()
            if k in updates:
                out.append(f"{k}={quote(updates[k])}")
                seen.add(k)
                continue
        out.append(raw)
    for k, v in updates.items():
        if k not in seen:
            out.append(f"{k}={quote(v)}")
    text = "\n".join(out)
    if text and not text.endswith("\n"):
        text += "\n"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding="utf-8")
    os.chmod(path, 0o640)


def _b64decode(payload: str) -> bytes | None:
    payload = payload.strip().replace("\n", "").replace("\r", "")
    pad = (4 - len(payload) % 4) % 4
    try:
        return base64.b64decode(payload + ("=" * pad), validate=False)
    except Exception:
        return None


def unwrap_secret(raw: str) -> str:
    raw = raw.strip().strip("\ufeff")
    if raw.startswith("{") and raw.endswith("}"):
        try:
            obj = json.loads(raw)
        except json.JSONDecodeError:
            return raw
        if isinstance(obj, dict):
            for key in ("APP_KEY", "app_key", "key", "secret"):
                found = obj.get(key)
                if found:
                    return str(found).strip()
    return raw


def decoded_app_key(value: str) -> bytes | None:
    """Bytes Laravel Encrypter would use (base64: payload or raw)."""
    value = unwrap_secret(unquote(value).strip())
    if not value:
        return None
    if value.startswith("base64:"):
        return _b64decode(value[7:])
    return value.encode("latin-1", errors="replace")


def normalize_app_key(value: str) -> str:
    """Fix common APP_KEY mistakes without printing the secret."""
    value = unwrap_secret(unquote(value).strip())
    if not value:
        return value

    if value.startswith("base64:"):
        payload = value[7:].strip()
        decoded = _b64decode(payload)
        if decoded is not None and len(decoded) == AES256_KEY_BYTES:
            return f"base64:{payload}"
        # Docs say "32 character string"; prefixing that with base64: decodes to 24 bytes.
        if len(payload.encode("latin-1", errors="replace")) == AES256_KEY_BYTES:
            return payload
        return value

    decoded = _b64decode(value)
    if decoded is not None and len(decoded) == AES256_KEY_BYTES and len(value) >= 40:
        return f"base64:{value}"
    return value


def check_app_key(path: Path) -> int:
    raw = get_key(path, "APP_KEY")
    if raw is None or not str(raw).strip():
        print("APP_KEY is missing or empty in .env.", file=sys.stderr)
        print("AES-256-CBC needs 32 bytes. On EC2, set AWS_APP_KEY_SECRET and re-run deploy.", file=sys.stderr)
        return 1
    normalized = normalize_app_key(raw)
    decoded = decoded_app_key(normalized)
    size = -1 if decoded is None else len(decoded)
    if size != AES256_KEY_BYTES:
        print(
            f"APP_KEY decodes to {size} bytes; AES-256-CBC requires {AES256_KEY_BYTES}.",
            file=sys.stderr,
        )
        print(
            "Typical causes: base64: prefix on a 32-char raw key, payload without the prefix, "
            "or a truncated / unquoted value. Do not paste the key into chat logs.",
            file=sys.stderr,
        )
        return 1
    print(f"APP_KEY ok ({AES256_KEY_BYTES} bytes for AES-256-CBC).")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("env_file")
    parser.add_argument("--get")
    parser.add_argument("--check-app-key", action="store_true")
    parser.add_argument("--normalize-app-key", action="store_true")
    args = parser.parse_args()
    path = Path(args.env_file)
    if args.get:
        value = get_key(path, args.get)
        if value is not None:
            print(value)
        return 0
    if args.check_app_key:
        if args.normalize_app_key:
            current = get_key(path, "APP_KEY")
            if current is not None:
                apply_updates(path, {"APP_KEY": normalize_app_key(current)})
        return check_app_key(path)
    if args.normalize_app_key:
        current = get_key(path, "APP_KEY")
        if current is None:
            print("APP_KEY is missing; nothing to normalize.", file=sys.stderr)
            return 1
        apply_updates(path, {"APP_KEY": normalize_app_key(current)})
        return 0
    updates = json.load(sys.stdin)
    parsed = {str(k): str(v) for k, v in updates.items()}
    if "APP_KEY" in parsed:
        parsed["APP_KEY"] = normalize_app_key(parsed["APP_KEY"])
    apply_updates(path, parsed)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
