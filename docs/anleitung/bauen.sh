#!/bin/bash
# Baut docs/anleitung/Lichtkammer-Anleitung.pdf (Docker nötig). Fonts: die statischen OTF aus ../../otf.
set -euo pipefail
cd "$(dirname "$0")"; REPO=$(cd ../.. && pwd)
docker build -q -t lk-anleitung-build . >/dev/null
docker run --rm -v "$REPO":/repo:ro -v "$PWD":/anleitung lk-anleitung-build sh -c \
  'mkdir -p ~/.fonts && cp /repo/otf/*.otf ~/.fonts/ && fc-cache -f >/dev/null && python /anleitung/bauen.py'
