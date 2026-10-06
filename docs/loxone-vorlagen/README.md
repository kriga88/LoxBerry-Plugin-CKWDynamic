# Loxone-Vorlage

`VIU_CKW_Dynamisch_UDP.xml` – Vorlage für **Loxone Config → Virtuelle Eingänge → Vorlage importieren** (Virtueller UDP-Eingang, Port 7000).

- Passt zu den Standardeinstellungen des Plugins: alle `…_now`, `total_rel00…total_rel23` (Spotpreis-Optimierer), Min/Max/Ø, günstigstes Zeitfenster, Rang und Status.
- Befehlserkennung `schlüssel=\v`, Einheit `<v.3>` für Preise und `<v>` für ganze Zahlen/Status, Beschreibung als Kommentar.
- Voraussetzung: im Plugin unter **Einstellungen → UDP** aktivieren, Miniserver wählen, Port 7000 (oder Port in Loxone anpassen). Ein UDP-Präfix ist nicht nötig.
- Bei anderer Auswahl (weitere Komponenten, Absolut-/Morgen-Reihen) die passende Vorlage im Plugin-Reiter **Loxone** herunterladen.

**MQTT:** Loxone Config kann für normale virtuelle Eingänge keine Vorlagen importieren. Für MQTT pro Wert einen *Virtuellen Eingang* mit dem Namen `ckwdynamic_<schlüssel>` anlegen (Liste im Plugin-Reiter **Loxone**).
