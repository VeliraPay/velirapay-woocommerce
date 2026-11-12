#!/usr/bin/env bash
#
# Zip the files WordPress needs, and nothing else, as build/velirapay-for-woocommerce-<version>.zip, in a folder named
# after the plugin's WordPress.org slug.

set -euo pipefail

cd "$(dirname "$0")/.."

slug=velirapay-for-woocommerce
version=$(sed -n 's/^ \* Version: *//p' velirapay-woocommerce.php | head -n 1)

if [ -z "$version" ]; then
    echo "Could not read the version from velirapay-woocommerce.php" >&2
    exit 1
fi

rm -rf "build/${slug}"
mkdir -p "build/${slug}"

cp velirapay-woocommerce.php readme.txt LICENSE CHANGELOG.md README.md "build/${slug}/"
cp -R src assets "build/${slug}/"

(cd build && rm -f "${slug}-${version}.zip" && zip -rq "${slug}-${version}.zip" "${slug}")

rm -rf "build/${slug}"

echo "build/${slug}-${version}.zip"
