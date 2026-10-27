# Verfahrensdokumentation Ladenkasse Hanfjack Ingolstadt

Stand: Oktober 2026 · Rechtsträger: SZM Trade & IT Solutions e.K., Kratzmühlstr. 14, 92339 Beilngries · Betriebsstätte: Harderstraße 1, 85049 Ingolstadt

## 1. Systemübersicht

| Komponente | Produkt | Aufgabe |
|---|---|---|
| Warenwirtschaft / Shop | WooCommerce auf hanfjack.de | Artikelstamm, Preise, Bestand, Bestellungen (online und im Laden) |
| Kassensoftware | WCPOS (WordPress-Plugin, Bedienung im Browser) | Erfassung der Ladenverkäufe als WooCommerce-Bestellungen, Bondruck, Bestandsabzug |
| Zahlungsterminal | SumUp | Kartenzahlung; erhält nur Betrag und Bestellnummer |
| Bondrucker | Epson TM-m30III (Server Direct Print) | Druck des Kassenbelegs mit TSE-Daten und QR-Code |
| TSE | Epson TSE im Bondrucker (BSI-zertifiziert, Zertifikats-ID siehe Admin → Hardware-TSE → Übersicht) | Signatur aller Kassenvorgänge nach § 146a AO / KassenSichV |
| TSE-Anbindung | Plugin „Hardware-TSE (Epson) für WCPOS“ mit TSE-Agent im Browser am Kassen-PC | Start und Abschluss der TSE-Transaktionen, Journal, Belegdaten, Kassenbuch, Kassenabschluss, DSFinV-K, TSE-Export |

Kassen-ID (Seriennummer des Aufzeichnungssystems, Client-ID an der TSE): `HANFJACK-INGOLSTADT-KASSE-1`.
TSE-Seriennummer (SHA-256 des öffentlichen Schlüssels, hexadezimal): siehe Admin → Hardware-TSE → Übersicht.

## 2. Ablauf eines Verkaufs

1. Die Artikel werden im POS gescannt (EAN oder Artikelnummer) oder gesucht. WCPOS legt die Bestellung im Shop an.
2. Mit dem Anlegen erzeugt das Plugin einen Journaleintrag (= Bon-Nr.). Der TSE-Agent startet die TSE-Transaktion (Zeitpunkt Start).
3. Bezahlt wird bar oder per SumUp.
4. Mit der Bezahlung legt das Plugin die Belegdaten fest:
   - Bruttobeträge je Steuersatz
   - Zahlart Bar oder Unbar
   - Positionen mit Menge, Preis und Steuer
   - Kassierer
   Der TSE-Agent schließt die Transaktion ab (processType `Kassenbeleg-V1`). Signatur, Signaturzähler, Transaktionsnummer und Zeitstempel werden im Journal gespeichert und an der Bestellung vermerkt.
5. Erst danach wird der Bon gedruckt. Er enthält:
   - Händlerdaten, Positionen, MwSt-Ausweis und Zahlart
   - Bon-Nr. und Kassen-ID
   - TSE-Seriennummer und Transaktionsnummer
   - Start, Ende und Signaturzähler
   - Zeitformat, Signaturalgorithmus und Signatur
   - QR-Code nach DSFinV-K Anhang I (§ 6 KassenSichV)
6. WooCommerce reduziert den Lagerbestand.

**Rückerstattungen** werden über die WooCommerce-Bestellung erfasst. Sie werden als eigener Beleg mit negativen Beträgen signiert und verweisen auf den Ursprungsbeleg.

**Abgebrochene, nicht bezahlte Vorgänge** werden als `AVBelegabbruch` signiert. Hat die TSE einen Vorgang nie gesehen, etwa ein Warenkorb ohne Start, wird er nur im Journal als verworfen geführt.

## 3. Kassenbuch und Kassenabschluss

- Bargeldbewegungen ohne Verkauf werden unter Hardware-TSE → Kassenbuch gebucht und ebenfalls signiert:
  - Anfangsbestand
  - Privateinlage und Privatentnahme
  - Geldtransit
  - Ein- und Auszahlung
  - Lohnzahlung
  - Kassendifferenz
- Täglich um 23:58 Uhr erstellt das Plugin den Kassenabschluss (Z-Nr. fortlaufend). Er kann auch manuell erstellt werden. Der Abschluss schreibt alle abgeschlossenen Vorgänge seit dem letzten Abschluss sowie die Stammdaten fest (Unternehmen, Betriebsstätte, Kasse, TSE).
- Der Z-Bon jedes Abschlusses ist im Backend abrufbar und druckbar. Er zeigt:
  - Umsatz je Steuersatz und je Zahlart
  - Kassenbuchungen
  - Anzahl der Vorgänge ohne Signatur

## 4. Ausfall der TSE

Ist die TSE nicht erreichbar (Drucker aus, Netzwerk, Agent geschlossen, TSE-Fehler), läuft der Verkauf weiter:

- Nach der eingestellten Wartezeit druckt der Bon mit dem Hinweis „TSE ausgefallen – Beleg ohne Signatur“.
- Der Ausfall wird mit Zeitpunkt protokolliert: Bestellnotiz, Journal und WooCommerce-Log „hardware-tse-wcpos“.
- Der Vorgang bleibt in der Warteschlange und wird nachsigniert, sobald die TSE wieder erreichbar ist.
- Vorgänge, die endgültig nicht mehr signiert werden sollen, schließt der Shop-Manager im Journal als TSE-Ausfall ab. Im DSFinV-K-Export stehen sie mit Fehlermeldung (`TSE_TA_FEHLER`) statt Signatur.

Bei einem Ausfall der TSE von mehr als einem Geschäftstag wird das Finanzamt formlos informiert. Bei einem Defekt wird die TSE getauscht, neu eingerichtet und gemeldet.

## 5. Aufbewahrung und Export

- Journal, Kassenabschlüsse, Bestellungen und Kassenbuchungen liegen in der WooCommerce-Datenbank (tägliches Server-Backup). Journaleinträge werden nicht gelöscht.
- Die TSE speichert alle signierten Protokolle. Mindestens einmal im Quartal und vor dem Ablauf des TSE-Zertifikats wird ein TSE-Export (TAR) im TSE-Agenten erstellt und mitgesichert.
- Für eine Kassen-Nachschau oder Betriebsprüfung:
  1. Unter „Abschlüsse & Export“ den DSFinV-K-Export für den angefragten Zeitraum erzeugen (ZIP nach DSFinV-K 2.4).
  2. Im TSE-Agenten den TSE-Export für den Zeitraum erstellen.
  3. Beide Dateien herunterladen und auf einen Datenträger kopieren.

## 6. Berechtigungen und Änderungen

- Kassierer melden sich mit eigenem WordPress-Konto an. Der Kassierer wird in jedem Vorgang gespeichert (Bediener).
- TSE-Agent, Kassenbuch, Abschlüsse und Exporte sind nur für Shop-Manager zugänglich.
- PUK, PINs und Secret Key der TSE sind schriftlich hinterlegt. Ort: ____________________
- Änderungen an Plugin, Bonvorlage oder TSE-Konfiguration werden im Git-Repository `Zauni1984/hardware-tse-software-wcpos` versioniert.
- Preise und Artikel werden ausschließlich im WooCommerce-Backend gepflegt. Die Kasse hat keinen eigenen Artikelstamm.

## 7. Meldepflicht

Kasse und TSE werden innerhalb eines Monats nach Inbetriebnahme über „Mein ELSTER“ gemeldet (Mitteilung über elektronische Aufzeichnungssysteme, § 146a Abs. 4 AO). Die Meldung enthält:

- Art: elektronisches Aufzeichnungssystem, Softwarekasse WCPOS
- Kassen-ID
- Anschaffungs- bzw. Inbetriebnahmedatum
- TSE-Art: Hardware-TSE (USB/microSD) im Epson-Drucker
- TSE-Seriennummer und BSI-Zertifizierungs-ID (Admin → Hardware-TSE → Übersicht)

Eine Außerbetriebnahme (auch ein TSE-Tausch) wird ebenfalls gemeldet.
