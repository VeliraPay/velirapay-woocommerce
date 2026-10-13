#!/usr/bin/env bash
#
# Zip the files WordPress needs, and nothing else, as build/velirapay-woocommerce-<version>.zip.

set -euo pipefail

cd "$(dirname "$0")/.."

version=$(sed -n 's/^ \* Version: *//p' velirapay-woocommerce.php | head -n 1)

if [ -z "$version" ]; then
    echo "Could not read the version from velirapay-woocommerce.php" >&2
    exit 1
fi

rm -rf build/velirapay-woocommerce
mkdir -p build/velirapay-woocommerce

cp velirapay-woocommerce.php readme.txt LICENSE CHANGELOG.md README.md build/velirapay-woocommerce/
cp -R src assets build/velirapay-woocommerce/

(cd build && rm -f "velirapay-woocommerce-${version}.zip" && zip -rq "velirapay-woocommerce-${version}.zip" velirapay-woocommerce)

rm -rf build/velirapay-woocommerce

echo "build/velirapay-woocommerce-${version}.zip"
