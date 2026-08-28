<?php
/**
 * Datenschutzerklärung.
 *
 * NOT a copy of the blog's. The blog is hosted at DomainFactory and this site
 * is at all-inkl; taking that text over unchanged would make the hosting
 * section simply false. It also runs Google Analytics, which this site
 * deliberately does not.
 *
 * The short text below is a consequence of the architecture, not an omission:
 * no analytics, no CDN, no external fonts, no social plugins, and every cover
 * served from this server rather than embedded from someone else's. That is
 * why there is no consent banner. Every one of those choices has to hold for
 * this page to stay true.
 *
 * Sections describing features only the operator can reach have deliberately
 * been left out. A privacy policy addresses visitors, and the operator does
 * not need to inform herself; padding it with processing the reader can never
 * trigger only buries the parts that do apply to them.
 *
 * A person should read this before it goes live. It is structure and honest
 * description, not legal advice.
 */
declare(strict_types=1);

$missing = static fn (string $value, string $hint): string => trim($value) !== ''
    ? $value
    : '⚠ ' . $hint . ' in config.php eintragen';
?>
<h1><?= e(t('legal.privacy')) ?></h1>

<h2>Verantwortliche</h2>
<p>
  <?= e($missing((string) ($legal['operator'] ?? ''), 'legal.operator')) ?><br>
  <?= e($missing((string) ($legal['street'] ?? ''), 'legal.street')) ?><br>
  <?= e($missing((string) ($legal['city'] ?? ''), 'legal.city')) ?><br>
  E-Mail: <?= e($missing((string) ($legal['email'] ?? ''), 'legal.email')) ?>
</p>

<h2>Hosting</h2>
<p>
  Diese Seite wird bei der ALL-INKL.COM – Neue Medien Münnich, Hauptstraße 68,
  02742 Friedersdorf, gehostet. Die Server stehen in Deutschland. Mit dem
  Anbieter besteht ein Vertrag zur Auftragsverarbeitung nach Art. 28 DSGVO.
  Eine Übermittlung personenbezogener Daten in Drittländer findet nicht statt.
</p>

<h2>Server-Logfiles</h2>
<p>
  Beim Aufruf dieser Seite werden technisch notwendige Zugriffsdaten
  gespeichert: aufgerufene Adresse, Zeitpunkt, übertragene Datenmenge,
  Browsertyp und IP-Adresse. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO;
  das berechtigte Interesse liegt im sicheren und störungsfreien Betrieb.
  Diese Daten werden nach sieben Tagen gelöscht und nicht mit anderen Daten
  zusammengeführt.
</p>

<h2>Cookies</h2>
<p>
  Diese Seite setzt zwei Cookies, beide technisch notwendig im Sinne des
  § 25 Abs. 2 TDDDG. Eine Einwilligung ist dafür nicht erforderlich, weshalb
  es hier auch kein Cookie-Banner gibt:
</p>
<ul>
  <li>
    <strong>Sitzungs-Cookie</strong> – nur nach dem Anmelden. Es hält die
    Sitzung der angemeldeten Person und wird beim Abmelden gelöscht.
  </li>
  <li>
    <strong>Sprach-Cookie</strong> – merkt sich, ob die Oberfläche auf Deutsch
    oder Englisch angezeigt werden soll. Es enthält ausschließlich diese
    Angabe und läuft nach einem Jahr ab.
  </li>
</ul>
<p>
  Wer angemeldet bleibt, erhält zusätzlich ein Anmelde-Token als Cookie. Es
  enthält keine personenbezogenen Angaben, sondern eine Zufallszeichenfolge,
  und wird bei jeder Nutzung ausgetauscht.
</p>

<h2>Keine Analyse, keine externen Dienste</h2>
<p>
  Diese Seite verwendet keine Reichweitenmessung, keine Analysewerkzeuge und
  keine Social-Media-Plugins. Schriftarten, Skripte und Stylesheets werden
  ausschließlich vom eigenen Server ausgeliefert; es werden keine Inhalte von
  Content-Delivery-Netzwerken oder von Google Fonts nachgeladen. Beim Besuch
  dieser Seite entsteht damit keine Verbindung zu Dritten.
</p>

<h2>Coverabbildungen</h2>
<p>
  Alle auf dieser Seite gezeigten Coverabbildungen werden vom eigenen Server
  ausgeliefert. Es werden keine Bilder von fremden Servern nachgeladen; beim
  Betrachten des Regals entsteht daher keine Verbindung zu Dritten.
</p>

<h2>Verwaltungsbereich</h2>
<p>
  Das Erfassen und Bearbeiten von Büchern steht ausschließlich der Betreiberin
  nach Anmeldung zur Verfügung. Daten von Besucherinnen und Besuchern werden
  dabei nicht verarbeitet.
</p>

<h2>Ihre Rechte</h2>
<p>
  Sie haben das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16),
  Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18),
  Datenübertragbarkeit (Art. 20) und Widerspruch (Art. 21). Wenden Sie sich
  dafür an die oben genannte Adresse. Zudem besteht ein Beschwerderecht bei
  einer Datenschutz-Aufsichtsbehörde.
</p>

<h2>Verschlüsselung</h2>
<p>
  Diese Seite ist ausschließlich über eine verschlüsselte Verbindung (TLS)
  erreichbar. Aufrufe über HTTP werden automatisch weitergeleitet.
</p>
