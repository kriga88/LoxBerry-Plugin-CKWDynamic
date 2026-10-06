# Changelog

## 0.1.5 – 2026-10-07 (Beta)
- Log: Bei Loglevel „Fehler“ werden nur noch fehlerhafte Abrufe protokolliert (bisher jeder Lauf mit Start/Ende), bei „Info“/„Debug“ weiterhin jeder Lauf
- Fix: Eine leere oder unvollständige Antwort der CKW-API gilt als Störung und überschreibt die zuletzt gültigen Preise nicht mehr
- Fix: Fehlerhafte Einträge in den Preistabellen (GitHub/CKW) werden verworfen statt als 0 gerechnet
- Fix: „Jetzt abrufen“ meldet „Abruf läuft bereits“, statt fälschlich Erfolg zu melden
- Fix: Weboberfläche verwendet Schweizer Datum (Preise/Abgaben um Mitternacht und an Silvester)
- Fix: Einstellungen werden atomar gespeichert (kein Lesen halber Dateien durch den Cronjob)
- Deinstallation entfernt die retained MQTT-Werte vom Broker
- GitHub-Preistabelle: nach einem Fehler erst nach 6 Stunden erneut versuchen
- Texte zum Füllmodus im Reiter Loxone und im README korrigiert

## 0.1.4 – 2026-10-06 (Beta)
- Fix: Absturz beim Zusammenführen der Preistabelle von GitHub (Infotext in dynamic_reference)
- Fix: UDP-Werte wieder als `schlüssel=wert` (das LoxBerry-SDK liess das „=“ weg). **Bestehende UDP-Befehle mit `schlüssel\v` bitte auf `schlüssel=\v` ändern bzw. die Vorlage neu importieren.**
- Loxone-Vorlage mit Einheit (`<v.3>` für Preise, `<v>` für ganze Zahlen) und ohne Präfix; Standard-Präfix im Plugin leer
- MQTT-Gateway-Vorlage entfernt (Loxone importiert nur UDP-Vorlagen)

## 0.1.3 – 2026-10-xx (Beta)
- Hinweistext zur Einstellung „Günstigstes Zeitfenster“

## 0.1.2 – 2026-10-xx (Beta)
- Neuer Standard für fehlende Stunden: CKW-Durchschnittspreis (Home 6.65 / Business 5.30 Rp./kWh Netznutzung + fixe Zuschläge, Produkt, Abgaben)
- Konzessionsabgabe 2026 aus dem CKW-Preisblatt ergänzt (bisher 0)
- Gemeinden mit „10 % der Netznutzung“ werden exakt pro Viertelstunde gerechnet (Erkennung automatisch aus der CKW-Tarifdatei)
- Reiter Loxone: Beschreibung zu jedem Wert, auch als Kommentar in der UDP-Vorlage
- Cache der CKW-Tarifdatei wird nach Plugin-Updates neu eingelesen

## 0.1.1 – 2026-10-xx (Beta)
- Fix: MQTT-Versand meldete „Kein MQTT-Broker konfiguriert“ (Variablenkonflikt mit dem LoxBerry-SDK)
- Fix: Einstellungsseite brach beim Netztarif ab (gleiche Ursache)
- MQTT-Rückfall über die HTTP-Schnittstelle des MQTT-Gateways, genauere Fehlermeldungen

## 0.1.0 – 2026-10-06 (Beta, nicht veröffentlicht)
- Erste Version
- Abruf der dynamischen CKW-Preise (Home/Business dynamic) alle 15 Minuten
- Stromprodukte ClassicStrom (live aus API), BudgetStrom, MeinRegioStrom oder eigener Preis
- Jahrespreise und Konzessionsabgaben der Gemeinden automatisch aus der maschinenlesbaren CKW-Tarifdatei (Rückfall: tariffs.json auf GitHub / mitgeliefert)
- Gemeinde-Auswahl mit Komponente concession
- Komponenten total, integrated, grid, gridusage, gridfix, energy, concession; MwSt, CHF oder Rp.
- Stundenreihen relativ/absolut/morgen für den Loxone Spotpreis-Optimierer, Auffüllen fehlender Stunden
- Günstigstes Zeitfenster, Rang der aktuellen Stunde, Online-Status
- Ausgabe per MQTT (automatisches Abo im MQTT-Gateway) und UDP inkl. Loxone-Vorlage
