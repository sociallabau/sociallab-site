#!/bin/bash
# Deploy to preview.sociallab.com.au.
#
# The subdomain has its own document root, so the site is built exactly as it
# will go live — no /preview/ path prefix. Built into dist-preview/ so dist/
# (what the local server reads) is never touched mid-deploy.
set -euo pipefail

HOST="u3-6vfcfzxh8ciw@c1119786.sgvps.net"
REMOTE="~/www/preview.sociallab.com.au/public_html/"
KEY="$HOME/.ssh/sociallab_deploy"
cd "$(dirname "$0")/.."

echo "→ building"
python3 build.py --out=dist-preview >/dev/null

echo "→ uploading pages and images"
rsync -rz --partial --timeout=180 --exclude='*.mp4' \
  --exclude='storage/' --exclude='api/config.local.php' \
  -e "ssh -i $KEY -p 18765" dist-preview/ "$HOST:$REMOTE"

echo "→ uploading video (only what changed)"
rsync -rz --partial --timeout=900 --include='*/' --include='*.mp4' --exclude='*' \
  -e "ssh -i $KEY -p 18765" dist-preview/ "$HOST:$REMOTE"

echo "✓ deployed → https://preview.sociallab.com.au"
