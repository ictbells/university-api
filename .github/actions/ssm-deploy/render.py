#!/usr/bin/env python3
"""Render the SSM remote script and send-command parameters JSON."""

from __future__ import annotations

import json
import os
import pathlib
import sys


def write_remote() -> None:
    dest = pathlib.Path(os.environ["REMOTE_SCRIPT"])
    artifact_url = os.environ["ARTIFACT_URL"]
    env_url = os.environ.get("ENV_URL", "")
    remote_path = os.environ["REMOTE_PATH"]

    env_block = ""
    if env_url:
        env_block = """
OVERLAY=/tmp/bells-sis-env-overlay
if ! curl -fsSL --retry 3 -o "$OVERLAY" "$ENV_URL"; then
  echo "presigned env GET failed; trying instance-role s3 cp"
  aws s3 cp "s3://${BUCKET}/${ENV_S3_KEY}" "$OVERLAY" --region "$REGION"
fi
if [[ -f "$REMOTE_PATH/.env" ]]; then
  python3 "$REMOTE_PATH/scripts/patch-dotenv.py" "$REMOTE_PATH/.env" --merge-from "$OVERLAY" --keep-infra
else
  cp "$OVERLAY" "$REMOTE_PATH/.env"
fi
rm -f "$OVERLAY"
chown www-data:www-data "$REMOTE_PATH/.env" || true
chmod 640 "$REMOTE_PATH/.env"
"""

    dest.write_text(
        f"""#!/bin/bash
set -euo pipefail
REMOTE_PATH={remote_path!r}
ARTIFACT_URL={artifact_url!r}
ENV_URL={env_url!r}
BUCKET={os.environ.get("BUCKET", "")!r}
REGION={os.environ.get("REGION", "us-east-1")!r}
ARTIFACT_KEY={os.environ.get("ARTIFACT_KEY", "")!r}
ENV_S3_KEY={os.environ.get("ENV_S3_KEY", "")!r}
ARCHIVE=/tmp/bells-sis-deploy.tar.gz

if ! curl -fsSL --retry 3 -o "$ARCHIVE" "$ARTIFACT_URL"; then
  echo "presigned GET failed; trying instance-role s3 cp"
  aws s3 cp "s3://${{BUCKET}}/${{ARTIFACT_KEY}}" "$ARCHIVE" --region "$REGION"
fi
mkdir -p "$REMOTE_PATH"
tar -xzf "$ARCHIVE" -C "$REMOTE_PATH"
rm -f "$ARCHIVE"
{env_block}
if [[ -z "$ENV_URL" && -n "$ENV_S3_KEY" ]]; then
  aws s3 cp "s3://${{BUCKET}}/${{ENV_S3_KEY}}" "$REMOTE_PATH/.env" --region "$REGION"
  chown www-data:www-data "$REMOTE_PATH/.env" || true
  chmod 640 "$REMOTE_PATH/.env"
fi
chown -R www-data:www-data "$REMOTE_PATH/storage" "$REMOTE_PATH/bootstrap/cache" 2>/dev/null || true
chmod +x "$REMOTE_PATH/scripts/"*.sh 2>/dev/null || true
"""
    )


def write_params() -> None:
    script = pathlib.Path(os.environ["REMOTE_SCRIPT"]).read_text()
    extra = os.environ.get("REMOTE_COMMAND", "")
    if extra.strip():
        script = script.rstrip() + "\n" + extra + "\n"
    import base64

    b64 = base64.b64encode(script.encode()).decode()
    # One line so AWS-RunShellScript does not split on newlines.
    command = f"echo {b64} | base64 -d | bash"
    pathlib.Path(os.environ["SSM_PARAMS"]).write_text(json.dumps({"commands": [command]}))


if __name__ == "__main__":
    cmd = sys.argv[1]
    if cmd == "remote":
        write_remote()
    elif cmd == "params":
        write_params()
    else:
        raise SystemExit(f"unknown command: {cmd}")
