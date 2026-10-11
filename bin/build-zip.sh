#!/usr/bin/env bash
# Build a release zip in dist/ from the committed tree.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(grep -m1 "Version:" mailerhive.php | awk '{print $NF}')
mkdir -p dist
git archive --format=zip --prefix=mailerhive/ -o "dist/mailerhive-${version}.zip" HEAD
echo "dist/mailerhive-${version}.zip"
