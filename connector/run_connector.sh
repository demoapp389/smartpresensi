#!/usr/bin/env sh
# Menjalankan Connector Solution X601 lintas-OS (Linux/macOS).
set -e
cd "$(dirname "$0")"
exec python3 zkconnector.py "$@"
