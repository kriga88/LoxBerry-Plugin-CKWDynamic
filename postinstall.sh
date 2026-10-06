#!/bin/bash
# Wird NACH der Installation ausgefuehrt (User loxberry).
# Argumente: <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

PTEMPDIR=$1
PDIR=$3

# Bei einem Update erst nach dem Zurueckspielen der Einstellungen abrufen (postupgrade.sh)
if [ -d "/tmp/${PTEMPDIR}_upgrade" ]; then
	echo "<INFO> Update erkannt - Abruf folgt nach Wiederherstellung der Einstellungen."
	exit 0
fi

echo "<INFO> Erster Preisabruf bei CKW ..."
if php "$LBPBIN/$PDIR/fetch.php" > /dev/null 2>&1; then
	echo "<OK> Preise erfolgreich abgerufen."
else
	echo "<WARNING> Erster Abruf fehlgeschlagen - wird automatisch alle 15 Minuten wiederholt. Details im Plugin-Log."
fi
echo "<INFO> Bitte die Einstellungen im Plugin pruefen (Tarif, Stromprodukt, MQTT/UDP)."
exit 0
