# Inaktive Kundenkonten: Vorwarnung vor Löschung

WordPress-Plugin für WooCommerce. WooCommerce löscht Kundenkonten, die länger als eine eingestellte Frist
weder angemeldet noch aktiv waren (WooCommerce → Einstellungen → Konten & Datenschutz → „Inaktive Konten
aufbewahren“), und zwar ohne jede Vorwarnung. Dieses Plugin ergänzt:

1. eine **Warn-E-Mail an den Kunden** etwa 28 Tage (einstellbar, 14 bis 90) vor der möglichen Löschung,
2. eine **Übersicht für das Shop-Team** (nur Anzahlen, keine Kundendaten),
3. eine **Löschsperre**: WooCommerce darf ein Konto erst löschen, wenn dessen Warnung mindestens so alt ist wie die
   Vorlaufzeit. Die Löschung selbst bleibt bei WooCommerce. Das Plugin löscht nie etwas.

Geprüft gegen WooCommerce **11.2.1 und 11.2.2** (Integrationstest mit echtem WooCommerce-Code, HPOS an und aus) und WordPress 7.1.4. Es gibt keine Rechtsberatung: Die angemessene
Aufbewahrungsdauer legt der Betreiber fest.

## Wie die Sperre funktioniert

WooCommerce löscht in `WC_Privacy::delete_inactive_accounts_query()` per `WP_User_Query` alle Konten mit der Rolle
Kunde oder Abonnent, deren `wc_last_active` vor dem Stichtag liegt. Es gibt keinen Filter, der einzelne Löschungen
verhindert. Das Plugin erkennt genau diese Abfrage (Bedingung `wc_last_active` „kleiner als“) im dokumentierten Hook
`pre_get_users` und hängt UND-Bedingungen an:

- eine Freigabe-Markierung des Plugins muss existieren, ihr Datum muss erreicht und noch gültig sein (90 Tage),
- die Markierung muss sich auf die aktuelle letzte Aktivität beziehen (per SQL in `pre_user_query`),
- optional: keine Konten mit Team-Rechten.

Die Markierung entsteht erst, nachdem der Mailserver die Warn-E-Mail angenommen hat. Meldet sich der Kunde an oder
bestellt er, ändert WooCommerce `wc_last_active`, und die Markierung verfällt sofort.

**Was die Sperre nicht garantiert:** Ist das Plugin deaktiviert, löscht WooCommerce wieder ohne Vorwarnung. Ändert
ein WooCommerce-Update den Ablauf, kann die Sperre still wirkungslos werden. Dagegen gibt es einen täglichen
Selbsttest, die Bindung an geprüfte WooCommerce-Versionen und einen Wächter, der das Team sofort alarmiert, wenn
WooCommerce ein Konto ohne Freigabe löscht. Verhindern kann der Wächter das nicht mehr.

## Modi

| Modus | Kunden-Mails | Freigaben | Wirkung |
| --- | --- | --- | --- |
| Trockenlauf (Standard nach Aktivierung) | keine | keine | Bericht mit Anzahlen. Sperre aktiv: WooCommerce löscht nichts |
| Test | nur an die Testadresse | keine | Mailtext prüfen. WooCommerce löscht nichts |
| Live | an die Kunden | nach erfolgreichem Versand | Ablauf wie oben |

Der Wechsel zu „Live“ verlangt eine ausdrückliche Bestätigung und einen bestandenen Selbsttest.

## Einführung

1. Live-Shop nur lesend prüfen: aktuelle Frist, WooCommerce-Version, Rollen, Backup.
2. Frist in WooCommerce befristet leeren (pausiert die Löschung), falls dort bisher eine kürzere Frist gilt.
3. Auf Staging testen (siehe unten).
4. Im Live-Shop aktivieren, Trockenlauf-Bericht lesen.
5. Frist auf drei Jahre stellen (die Sperre ist bereits aktiv).
6. Auf „Live“ schalten (Tageslimit beachten).
7. Zwei Wochen täglich beobachten, danach monatlich und nach jedem WooCommerce-Update.

Die Umstellung von zwölf Monaten auf drei Jahre macht früher gelöschte Konten nicht wiederherstellbar.

## Randfälle (Auszug)

- Konten ohne gültige E-Mail-Adresse, mit wiederholtem Versandfehler oder mit unklarem Versandergebnis werden
  **nicht** automatisch freigegeben. Sie stehen unter WooCommerce → Inaktive Konten; dort entscheidet ein Mensch
  („Erneut prüfen und senden“ oder „Löschung freigeben“).
- Konten mit Team-Rechten werden nie gewarnt und (Standard) nie gelöscht.
- Gastbestellungen zählen nicht als Aktivität eines Kontos.
- Ändert sich die Frist, werden Markierungen neu bewertet. Nicht mehr fällige Konten verlieren ihre Freigabe.
- „Zugestellt“ kann WordPress nicht wissen. Das Plugin unterscheidet nur „vom Mailserver angenommen“ und „nicht angenommen“.

## Tests

```bash
# Rechenlogik (Datumsrechnung, Schaltjahre, Monatsenden), ohne WordPress
php tests/run-unit.php

# Integrationstest gegen echten WordPress- und WooCommerce-Code.
# NUR in einer Wegwerf-Installation. Der Test löscht echte Konten über WC_Privacy::delete_inactive_accounts().
wp eval-file wp-content/plugins/woocommerce-inactive-user-notification/tests/integration/run.php
```

Der Integrationstest beweist zuerst, dass WooCommerce ohne Sperre ungewarnte Konten löscht, und prüft dann, dass
es mit Sperre nur Konten mit gültiger, erreichter Freigabe löscht. Er läuft bei jeder neuen WooCommerce-Version
erneut, bevor die Version im Plugin als geprüft bestätigt wird.

## Deinstallation

Entfernt alle Optionen und Benutzer-Metadaten des Plugins. Danach gibt es keine Löschsperre mehr.
