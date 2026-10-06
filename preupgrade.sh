#!/bin/bash
# Wird bei einem Update VOR der Installation ausgefuehrt (User loxberry).
# Sichert die Einstellungen, da LoxBerry die alte Installation loescht.
# Argumente: <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

PTEMPDIR=$1
PDIR=$3
BACKUP=/tmp/${PTEMPDIR}_upgrade

echo "<INFO> Sichere Einstellungen nach $BACKUP"
mkdir -p "$BACKUP/config" "$BACKUP/data"
cp -p -v -r "$LBPCONFIG/$PDIR/." "$BACKUP/config/" 2>/dev/null
cp -p -v -r "$LBPDATA/$PDIR/." "$BACKUP/data/" 2>/dev/null
echo "<OK> Einstellungen gesichert."
exit 0
