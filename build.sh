#!/bin/sh
# Builds dist/pkg_gssaiimage-<version>.zip, the installable package of AI Cover Image for Joomla.
set -e

cd "$(dirname "$0")"

rm -rf dist
mkdir -p dist/pkg/packages

for plugin in plg_system_gssaiimage plg_system_gssaiimageflexi; do
	(cd "$plugin" && zip -q -r -X "../dist/pkg/packages/$plugin.zip" . -x '.*' -x '*/.*')
done

cp -R pkg_gssaiimage/pkg_gssaiimage.xml pkg_gssaiimage/script.php pkg_gssaiimage/language pkg_gssaiimage/LICENSE dist/pkg/
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' pkg_gssaiimage/pkg_gssaiimage.xml | head -1)

(cd dist/pkg && zip -q -r -X "../pkg_gssaiimage-$VERSION.zip" . -x '.*' -x '*/.*')
rm -rf dist/pkg

ls -l "dist/pkg_gssaiimage-$VERSION.zip"
