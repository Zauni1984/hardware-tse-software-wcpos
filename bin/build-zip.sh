#!/usr/bin/env bash
# Builds hardware-tse-wcpos-<version>.zip (plugin folder hardware-tse-wcpos/) from the committed tree.
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(sed -n 's/^ \* Version: *//p' hardware-tse-wcpos.php | head -1)
out="${1:-dist}"
mkdir -p "$out"
git archive --format=zip --prefix=hardware-tse-wcpos/ -o "$out/hardware-tse-wcpos-$version.zip" HEAD \
	hardware-tse-wcpos.php includes assets schema templates readme.md VERFAHRENSDOKUMENTATION.md NOTICE LICENSE
echo "$out/hardware-tse-wcpos-$version.zip"
