#!/bin/bash
# Führt ein Playwright-Skript im Container aus, der den Netzwerkraum der Testinstanz (Container lk-anleitung) teilt.
# Aufruf: pw_run.sh <Skript.py> <Arbeitsordner mit shots/ und demo/> [Argumente]
set -euo pipefail
SK=$(cd "$(dirname "$1")" && pwd)/$(basename "$1"); ARB=$2; shift 2
docker run --rm --network container:lk-anleitung -v "$SK":/skript.py:ro -v "$ARB":/arbeit -w /arbeit \
  mcr.microsoft.com/playwright/python:v1.52.0-noble \
  sh -c 'pip install -q playwright==1.52.0 >/dev/null 2>&1; python /skript.py "$@"' -- "$@"
