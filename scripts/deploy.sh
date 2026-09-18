#!/bin/bash
# Deploy to the SiteGround preview.
#
# The preview build needs every path prefixed with /preview/, so it is built
# into dist-preview/ and dist/ is never touched. That way the local preview at
# localhost:8000 keeps working while a deploy runs.
set -euo pipefail

HOST="u3-6vfcfzxh8ciw@c1119786.sgvps.net"
REMOTE="~/www/sociallab.com.au/public_html/preview/"
KEY="$HOME/.ssh/sociallab_deploy"
cd "$(dirname "$0")/.."

echo "→ building for /preview (into dist-preview/, leaving dist/ alone)"
python3 build.py --base=/preview --out=dist-preview >/dev/null

echo "→ uploading pages and images"
rsync -rz --partial --timeout=180 --exclude='*.mp4' \
  --exclude='storage/' --exclude='api/config.local.php' \
  -e "ssh -i $KEY -p 18765" dist-preview/ "$HOST:$REMOTE"

echo "→ uploading video (slow, only changed files)"
rsync -rz --partial --timeout=600 --include='*/' --include='*.mp4' --exclude='*' \
  -e "ssh -i $KEY -p 18765" dist-preview/ "$HOST:$REMOTE"

echo "✓ deployed — local preview at localhost:8000 was never interrupted"
