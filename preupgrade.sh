#!/bin/bash
# Wird bei einem Update VOR der Installation ausgefuehrt (User loxberry).
# Sichert die Einstellungen, da LoxBerry die alte Installation loescht.
# Argumente: <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

PTEMPDIR=$1
PDIR=$3
BACKUP=/tmp/${PTEMPDIR}_upgrade

# Statistik-Puffer aus der RAM-Disk in die Datenbank schreiben (ab 1.2.0)
FETCH="$LBPBIN/$PDIR/fetch.php"
if [ -f "$FETCH" ] && grep -q -- '--flush-stats' "$FETCH"; then
	if php "$FETCH" --flush-stats; then
		echo "<OK> Statistik-Puffer gesichert."
	else
		echo "<WARNING> Statistik-Puffer konnte nicht gesichert werden (höchstens eine Stunde Feinauflösung geht verloren)."
	fi
fi

echo "<INFO> Sichere Einstellungen nach $BACKUP"
mkdir -p "$BACKUP/config" "$BACKUP/data"
cp -p -v -r "$LBPCONFIG/$PDIR/." "$BACKUP/config/" 2>/dev/null
cp -p -v -r "$LBPDATA/$PDIR/." "$BACKUP/data/" 2>/dev/null
echo "<OK> Einstellungen gesichert."
exit 0
