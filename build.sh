#!/bin/bash
# Baut das Installations-ZIP fuer LoxBerry nach dist/
set -e
cd "$(dirname "$0")"
VERSION=$(grep '^VERSION=' plugin.cfg | head -1 | cut -d= -f2)
NAME=ckwdynamic
OUT=dist/LoxBerry-Plugin-CKWDynamic-$VERSION.zip
rm -rf dist && mkdir -p dist/$NAME
cp -R plugin.cfg *.sh tariffs.json release.cfg prerelease.cfg LICENSE bin config cron templates uninstall webfrontend icons dist/$NAME/
rm -f dist/$NAME/build.sh
(cd dist && zip -qr "$(basename "$OUT")" $NAME -x '*.DS_Store')
rm -rf dist/$NAME
echo "Erstellt: $OUT"
