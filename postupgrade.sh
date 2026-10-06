#!/bin/bash
# Wird bei einem Update NACH der Installation ausgefuehrt (User loxberry).
# Spielt die in preupgrade.sh gesicherten Einstellungen zurueck.
# Argumente: <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

PTEMPDIR=$1
PDIR=$3
BACKUP=/tmp/${PTEMPDIR}_upgrade

if [ -d "$BACKUP" ]; then
	echo "<INFO> Stelle Einstellungen aus $BACKUP wieder her"
	cp -p -v -r "$BACKUP/config/." "$LBPCONFIG/$PDIR/" 2>/dev/null
	cp -p -v -r "$BACKUP/data/." "$LBPDATA/$PDIR/" 2>/dev/null
	rm -rf "$BACKUP"
	echo "<OK> Einstellungen wiederhergestellt."
else
	echo "<WARNING> Keine gesicherten Einstellungen gefunden - Standardwerte bleiben aktiv."
fi
php "$LBPBIN/$PDIR/fetch.php" > /dev/null 2>&1 && echo "<OK> Preise abgerufen." || echo "<WARNING> Abruf fehlgeschlagen - wird alle 15 Minuten wiederholt."
exit 0
