#!/bin/bash
# Wird bei einem Update NACH der Installation ausgefuehrt (User loxberry).
# Spielt die in preupgrade.sh gesicherten Einstellungen zurueck.
# Argumente: <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

PTEMPDIR=$1
PDIR=$3
# Ohne diese Argumente wuerden die Pfade unten auf fremde Ordner zeigen (cp in alle Plugin-Ordner, rm -rf)
if [ -z "$PTEMPDIR" ] || [ -z "$PDIR" ] || [ -z "$LBPCONFIG" ] || [ -z "$LBPDATA" ]; then
	echo "<ERROR> Aufruf ohne Plugin-Ordner bzw. Temp-Ordner - Abbruch."
	exit 1
fi
BACKUP=/tmp/${PTEMPDIR}_upgrade

if [ -L "$BACKUP" ] || { [ -e "$BACKUP" ] && [ ! -O "$BACKUP" ]; }; then
	echo "<ERROR> $BACKUP ist ein Symlink oder gehört einem anderen Benutzer - Einstellungen werden nicht übernommen."
elif [ -d "$BACKUP" ]; then
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
