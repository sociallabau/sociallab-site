#!/bin/bash
# Deploy to the LIVE site: sociallab.com.au/public_html.
#
# Takes a confirmation argument so it cannot run by accident:
#     ./scripts/deploy-live.sh yes
#
# Nothing already on the server is deleted. The live docroot also holds legacy
# folders (listingvideo, cgi-bin, .well-known) and symlinks to the old subdomain
# landing pages (/digitalmarketing, /giveaway, /leadgeneration, /realestatevideo,
# /startupguide) — all of those must survive this deploy.
#
# api/config.local.php is excluded: the server's copy holds the Gmail app
# password and Meta CAPI token, and must not be overwritten by a local copy.
set -euo pipefail

[ "${1:-}" = "yes" ] || { echo "Refusing to deploy to LIVE without: $0 yes"; exit 1; }

HOST="u3-6vfcfzxh8ciw@c1119786.sgvps.net"
REMOTE="~/www/sociallab.com.au/public_html/"
KEY="$HOME/.ssh/sociallab_deploy"
cd "$(dirname "$0")/.."

echo "→ building the live site (into dist-live/, so dist/ and the local preview are untouched)"
python3 build.py --out=dist-live >/dev/null

echo "→ uploading pages and images"
rsync -rz --partial --timeout=180 --exclude='*.mp4' \
  --exclude='storage/' --exclude='api/config.local.php' \
  -e "ssh -i $KEY -p 18765" dist-live/ "$HOST:$REMOTE"

echo "→ uploading video (only what changed — slow the first time)"
rsync -rz --partial --timeout=900 --include='*/' --include='*.mp4' --exclude='*' \
  -e "ssh -i $KEY -p 18765" dist-live/ "$HOST:$REMOTE"

echo "✓ files are in the live docroot."
echo "  Until DNS points at SiteGround, sociallab.com.au still serves Framer."
