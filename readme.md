# Hardware-TSE (Epson) für WCPOS

WordPress-Plugin, das Kassenvorgänge aus WooCommerce (WCPOS) mit einer **Hardware-TSE im Epson-Bondrucker** signiert (KassenSichV, § 146a AO). Es braucht keine Cloud-TSE, keine JTL-Software und keine Middleware im Laden.

Die Kasse ist austauschbar: Das Plugin hängt an den WooCommerce-Bestellungen, nicht an WCPOS-Interna. SumUp und alle anderen Zahlarten bleiben, wie sie sind.

## Wie es funktioniert

```
WCPOS (Browser) ──► WooCommerce-Bestellung ──► Journal (Server)  ◄──REST──►  TSE-Agent (Browser am Kassen-PC)
                                                     │                              │ ePOS-Device (wss://Drucker:8043)
                                       Bon (WCPOS-Vorlage, QR-Code)        Epson TM-m30III + TSE („local_TSE“)
```

Die TSE steckt im Drucker im Laden-Netz. Der Webserver kann den Drucker nicht erreichen. Deshalb läuft der **TSE-Agent** als Seite im Browser am Kassen-PC (WooCommerce → Hardware-TSE → TSE-Agent). Er spricht über das Epson ePOS SDK für JavaScript direkt mit der TSE und arbeitet die Aufträge ab, die das Plugin auf dem Server anlegt.

| Ereignis | TSE |
|---|---|
| Kassenbestellung angelegt (WCPOS speichert den Warenkorb) | `StartTransaction` |
| Bestellung bezahlt (bar, SumUp, Karte …) | `FinishTransaction` mit `Kassenbeleg-V1`, z. B. `Beleg^19.00_26.00_0.00_0.00_0.00^45.00:Unbar` |
| Nicht bezahlte Bestellung storniert, fehlgeschlagen oder gelöscht | `AVBelegabbruch` (oder verworfen, falls die TSE den Vorgang nie gesehen hat) |
| Rückerstattung | eigener Beleg mit negativen Beträgen, Verweis auf den Ursprungsbeleg |
| Kassenbuch (Anfangsbestand, Einlage, Entnahme, Geldtransit …) | eigener Beleg, nicht steuerbar, Zahlart Bar |

- **Bon:** Das Plugin füllt den Bereich `fiscal` der WCPOS-Belegdaten (Filter `woocommerce_pos_receipt_data`). Die Felder sind Bon-Nr., Kassen-ID, TSE-Seriennummer, Transaktionsnummer, Start, Ende, Signaturzähler, Zeitformat, Algorithmus und Signatur, dazu der QR-Code nach DSFinV-K Anhang I (V0).
- **Druck:** Der WCPOS-Cloud-Druck (z. B. Epson Server Direct Print) wird für bezahlte Kassenbestellungen angehalten, bis die Signatur da ist, und danach automatisch ausgelöst. Antwortet die TSE nicht innerhalb der Wartezeit (Standard 25 Sekunden), druckt der Bon mit dem Vermerk „TSE ausgefallen“. Der Vorgang bleibt in der Warteschlange und wird signiert, sobald die TSE wieder erreichbar ist.
- **Kassenabschluss:** Läuft täglich automatisch (Standard 23:58 Uhr) oder per Knopf. Er vergibt die Z-Nummer, schreibt die Stammdaten fest und liefert einen druckbaren Z-Bon.
- **DSFinV-K 2.4:** Wird lokal erzeugt. Das ZIP enthält alle 20 CSV-Dateien, `index.xml` und die DTD; die Spaltenreihenfolge kommt aus der offiziellen `index.xml`.
- **TSE-Export (TAR):** Archiv oder Zeitraum. Der Agent liest die TSE aus, der Server speichert die Datei.

## Voraussetzungen

- WooCommerce 8+, WCPOS 1.11+ (für Bon-Filter und Cloud-Druck), PHP 8.1+ mit `zip`
- Epson-Bondrucker mit TSE (z. B. TM-m30III mit Epson TSE), **feste IP im Laden-Netz**, ePOS-Device aktiviert
- Am Kassen-PC ein aktueller Chrome oder Edge, angemeldet als Shop-Manager
- **Epson ePOS SDK for JavaScript** (Datei `epos-2.x.x.js`, Download bei Epson). Sie liegt aus Lizenzgründen nicht im Plugin.

## Einrichtung

1. ZIP hochladen und aktivieren (`bin/build-zip.sh` erzeugt das ZIP aus dem Repository).
2. **Einstellungen** öffnen:
   - Drucker-IP eintragen, Port 8043 lassen.
   - Kassen-ID prüfen (Standard `HANFJACK-INGOLSTADT-KASSE-1`).
   - `epos-2.x.x.js` hochladen.
   - Stammdaten ergänzen (Steuernummer bzw. USt-IdNr.).
3. Am Kassen-PC einmal `https://<Drucker-IP>:8043/` öffnen und das selbstsignierte Zertifikat akzeptieren.
4. **TSE-Agent** öffnen und **Verbinden** klicken. Fragt der Browser nach Zugriff auf lokale Netzwerkgeräte, erlauben.
5. **Einrichtung / Kasse registrieren** klicken:
   - **Neue TSE:** Das Plugin erzeugt PUK, Admin-PIN, TimeAdmin-PIN und einen neuen Secret Key. Diese Daten **ausdrucken bzw. notieren**. Danach laufen SetUp, Wechsel des Secret Keys, Uhrzeit setzen, Registrierung der Kasse und TimeAdmin-Login für 24 Stunden.
   - **Bereits eingerichtete TSE** (z. B. vom Händler): vorher PUK, PINs und Secret Key in den Einstellungen eintragen. Die Registrierung nutzt diese Daten.
6. In WCPOS die Thermo-Bonvorlage um den Block aus `templates/wcpos-thermal-tse-block.xml` ergänzen, vor dem Bestell-Barcode. Für HTML- oder PDF-Vorlagen gibt es dieselben Felder unter `fiscal.*`.
7. Testverkauf machen und den Bon prüfen. Danach im Kassenbuch den Anfangsbestand buchen.
8. Das alte Plugin „Hanfjack TSE (fiskaly)“ deaktivieren. Sonst signieren beide Plugins jeden Verkauf.
9. Kasse und TSE bei ELSTER melden (siehe Verfahrensdokumentation).

**Im Betrieb:** Die Seite TSE-Agent am Kassen-PC offen lassen, am besten in einem eigenen Fenster. Läuft WCPOS im selben Browser, weckt jede Zahlung den Agenten sofort; sonst fragt er alle 3 Sekunden nach Aufträgen. Es darf nur ein Agent gleichzeitig laufen, die TSE erlaubt nur eine Verbindung.

## Sicherheit

- Alle REST-Routen (`/wp-json/hwtse/v1/…`) verlangen `manage_woocommerce` (Filter `hwtse_capability`).
- PUK, PINs und Secret Key liegen in `wp_options` (`hwtse_settings`). Nur der Agent eines Shop-Managers bekommt sie, im Admin werden sie maskiert.
- Exporte liegen unter `wp-content/uploads/hwtse/` mit `.htaccess`-Sperre und zufälligen Dateinamen. **Unter nginx greift `.htaccess` nicht.** Dort zusätzlich `location ^~ /wp-content/uploads/hwtse/ { deny all; }` setzen, aber `…/hwtse/sdk/` erlauben, damit die SDK-Datei ladbar bleibt.

## Filter

| Filter | Zweck |
|---|---|
| `hwtse_is_register_order` | Entscheidet, ob eine Bestellung ein Kassenvorgang ist (Standard: WCPOS-Bestellung, im POS übernommene Bestellung oder Kassen-Zahlart) |
| `hwtse_payment_type` | DSFinV-K-Zahlart und -Name je Zahlart-ID |
| `hwtse_capability` | Berechtigung für Agent und Admin |

## Grenzen der Version 0.1

- Nur ein Kassenplatz (eine Kassen-ID) und eine TSE. Mehrere Kassen sind im Datenmodell vorbereitet (`client_id` je Vorgang), die Oberfläche fehlt noch.
- Gemischte Zahlungen (Teil bar, Teil Karte) werden als eine Zahlart der Bestellung erfasst.
- Rabatte stehen als Preisfindung (`base_amount` / `discount`) an der Position, nicht als eigener Geschäftsvorfall.
- Bons, die WCPOS **im Browser** druckt (ohne Cloud-Druck), kann das Plugin nicht anhalten. Für signierte Bons den Cloud-Druck nutzen oder den Bon nach der Signatur nachdrucken.
