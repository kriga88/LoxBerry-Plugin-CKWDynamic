# Loxone-Vorlagen

Vorlagen für **Loxone Config → Virtuelle Eingänge → Vorlage importieren**. Sie passen zu den Standardeinstellungen des Plugins: Komponente `total` mit Relativ-Reihe (Spotpreis-Optimierer +0…+23), alle Momentanwerte, Kennzahlen und Status.

| Datei | Wann verwenden | Port |
|---|---|---|
| `VIU_CKW_Dynamisch_UDP.xml` | Plugin-Einstellung **UDP** aktiv (Plugin sendet direkt an den Miniserver) | 7000 |
| `VIU_CKW_Dynamisch_MQTT-Gateway.xml` | Werte kommen über das **LoxBerry-MQTT-Gateway**, Protokoll im Tab *Gateway* auf **UDP** oder **Beide** | 11883 |

Hinweise:
- Ports in Loxone Config anpassen, falls im Plugin bzw. MQTT-Gateway andere Ports eingestellt sind.
- Bei anderer Auswahl (weitere Komponenten, Absolut-/Morgen-Reihen, anderes Basis-Topic) die passende Vorlage im Plugin unter **Loxone** herunterladen (UDP) bzw. die Befehlserkennung anpassen.
- Texte (`last_update_text`, `price_source`, `price_warning`) gehen nicht über UDP. Dafür im MQTT-Gateway HTTP verwenden und einen *Virtuellen Texteingang* `ckwdynamic_<schlüssel>` anlegen.
- Beim MQTT-Gateway mit Protokoll **HTTP** sind keine Vorlagen möglich: pro Wert einen *Virtuellen Eingang* mit dem Namen `ckwdynamic_<schlüssel>` anlegen (Liste im Plugin-Reiter **Loxone**).
