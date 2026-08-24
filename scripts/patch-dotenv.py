#!/usr/bin/env python3
"""Patch or read KEY=value lines in a dotenv file. JSON object on stdin to set; --get KEY to read."""
from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path


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


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("env_file")
    parser.add_argument("--get")
    args = parser.parse_args()
    path = Path(args.env_file)
    if args.get:
        value = get_key(path, args.get)
        if value is not None:
            print(value)
        return 0
    updates = json.load(sys.stdin)
    apply_updates(path, {str(k): str(v) for k, v in updates.items()})
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
