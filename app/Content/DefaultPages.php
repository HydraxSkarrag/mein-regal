<?php
declare(strict_types=1);

namespace App\Content;

use App\Core\Config;

/**
 * The text a fresh installation starts with.
 *
 * These used to be templates. That was fine while there was one installation,
 * and wrong the moment there could be two: a privacy policy naming a hosting
 * company in the source code is a policy that is false for everyone who hosts
 * somewhere else, and no amount of configuration placeholders fixes a text
 * whose structure assumes one particular setup.
 *
 * So they are a starting point, written once into the pages table when the
 * first account is created, and owned by the operator from that moment on.
 * Editing them needs no deployment and no developer, which is the only way a
 * legal text ever stays current.
 *
 * A word on what this is not: it is not legal advice, and it does not become
 * correct by being generated. It describes an installation that has no
 * analytics, no CDN, no external fonts and no embedded images, because that
 * is what this application does. Change any of that and the text is no longer
 * true. Someone should read it before a site goes live.
 */
final class DefaultPages
{
    /**
     * German is the only language seeded.
     *
     * An English privacy policy for a German operator would be a translation
     * of a legal text, and a translation nobody has checked is worse than an
     * honest absence: the reader cannot tell which version governs. The legal
     * pages therefore fall back to whichever language exists, and an operator
     * who wants an English version writes one knowingly.
     */
    public const SEEDED_LOCALE = 'de';

    /**
     * Stands in for the address block while the texts are unwrapped.
     *
     * The address is the one place where a line break means a line break.
     * Keeping it out of unwrap() until the end is simpler than teaching
     * unwrap() to recognise a postal address.
     */
    private const HOLD_ADDRESS = '{{address}}';

    /** Likewise for the cookie list, which is built from list items. */
    private const HOLD_COOKIES = '{{cookies}}';

    /**
     * @return array<string, array{title: string, body: string}>
     */
    public static function all(Config $config, string $operator = '', string $email = ''): array
    {
        $address = self::address($operator, $email);
        $host = self::mark('', 'Name und Anschrift des Hosters');
        $siteName = $config->str('site_name', 'Mein Regal');

        return [
            'imprint' => [
                'title' => 'Impressum',
                'body'  => str_replace(
                    self::HOLD_ADDRESS,
                    $address,
                    self::unwrap(self::imprint($address, $operator))
                ),
            ],
            'privacy' => [
                'title' => 'Datenschutzerklärung',
                'body'  => str_replace(
                    [self::HOLD_ADDRESS, self::HOLD_COOKIES],
                    [$address, self::cookies($config->bool('language_switcher', true))],
                    self::unwrap(self::privacy($address, $host, $siteName))
                ),
            ],
        ];
    }

    /**
     * Join the hard-wrapped source back into one line per paragraph.
     *
     * The texts below are wrapped at eighty columns because that is how they
     * are read here. What gets stored must not be: a newline inside a
     * paragraph is a line break when it is rendered, which is exactly right
     * for a postal address and exactly wrong for a paragraph of prose - it
     * would arrive in the editor pre-broken at whatever width suited this
     * file. Headings, list items and quotes keep their own lines; the address
     * block never passes through here.
     */
    private static function unwrap(string $text): string
    {
        $out = [];
        foreach (explode("\n\n", $text) as $block) {
            $lines = explode("\n", $block);
            $joined = [];
            foreach ($lines as $line) {
                $starts = preg_match('/^\s*(#|[-*>]\s|\d+[.)]\s)/u', $line) === 1;
                if ($starts || $joined === []) {
                    $joined[] = rtrim($line);
                    continue;
                }
                $joined[count($joined) - 1] .= ' ' . trim($line);
            }
            $out[] = implode("\n", $joined);
        }

        return implode("\n\n", $out);
    }

    /**
     * The address block.
     *
     * Name and e-mail come from the account being created, because at that
     * moment they are already known and asking twice is how two answers
     * start to disagree. The postal address cannot be guessed and is marked
     * instead: it is filled in on the page, which is where the text is
     * corrected from then on anyway.
     */
    private static function address(string $operator, string $email): string
    {
        return implode("\n", [
            self::mark($operator, 'Name der Betreiberin oder des Betreibers'),
            self::mark('', 'Straße und Hausnummer'),
            self::mark('', 'Postleitzahl und Ort'),
            'E-Mail: ' . self::mark($email, 'E-Mail-Adresse'),
        ]);
    }

    /**
     * A missing detail is marked, not left blank.
     *
     * An empty line in an Impressum reads like a formatting slip and survives
     * for years. A warning sign in the middle of the page does not.
     */
    private static function mark(string $value, string $hint): string
    {
        $value = trim($value);

        return $value !== '' ? $value : '⚠ ' . $hint . ' eintragen';
    }

    /**
     * The notice that this is a draft.
     *
     * The ⚠ marks show where something is missing. They do not show that the
     * rest has never been read by anyone, and a legal page that looks
     * finished is read as finished - by its operator most of all, who has
     * every reason to want it to be.
     *
     * It stands at the top, in the text, where it is published along with
     * everything else: an operator who leaves it there is telling visitors
     * something true. And it says how to remove itself, so finishing the job
     * is what makes it go away.
     */
    private static function draftNotice(string ...$checks): string
    {
        $text = '> **Dieser Text ist ein Entwurf.** Er wird mit der Anwendung ausgeliefert'
            . ' und ist von niemandem geprüft worden, der diese Installation kennt.'
            . ' Bitte ganz lesen, die mit ⚠ markierten Stellen ausfüllen und streichen,'
            . " was nicht zutrifft.\n";
        foreach ($checks as $check) {
            $text .= '> ' . $check . "\n";
        }

        return $text . '> Dies ist keine Rechtsberatung. Ist alles geprüft und richtig,'
            . " kann dieser Absatz gelöscht werden.\n\n";
    }

    private static function imprint(string $address, string $operator): string
    {
        $text = self::draftNotice()
            . "## Angaben gemäß § 5 DDG\n\n" . self::HOLD_ADDRESS . "\n\n";

        /* The section is always here, with a note saying when it does not
           apply. It used to appear only when a value was configured, which
           meant that whoever had never heard of § 18 (2) MStV - the reason
           the section exists - was also never told about it. A heading that
           explains how to delete itself is the safer of the two mistakes. */
        $text .= "## Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV\n\n"
            . self::mark($operator, 'Verantwortliche Person')
            . "\n\n> Dieser Abschnitt ist nur nötig, wenn hier redaktionelle Inhalte"
            . " erscheinen - etwa eigene Rezensionstexte. Trifft das nicht zu, kann er"
            . " gelöscht werden.\n\n";

        return $text . <<<'TEXT'
            ## Haftung für Inhalte

            Als Diensteanbieterin bin ich für eigene Inhalte auf diesen Seiten nach den
            allgemeinen Gesetzen verantwortlich. Ich bin nicht verpflichtet, übermittelte
            oder gespeicherte fremde Informationen zu überwachen oder nach Umständen zu
            forschen, die auf eine rechtswidrige Tätigkeit hinweisen.

            ## Haftung für Links

            Diese Seite enthält Links zu externen Websites Dritter, auf deren Inhalte ich
            keinen Einfluss habe. Für die Inhalte der verlinkten Seiten ist stets die
            jeweilige Anbieterin oder der jeweilige Anbieter verantwortlich.

            ## Urheberrecht

            Die auf dieser Seite gezeigten Buchcover sind urheberrechtlich geschützt und
            gehören den jeweiligen Rechteinhabern. Gezeigt werden selbst aufgenommene
            Fotografien der eigenen Exemplare sowie Coverabbildungen aus den öffentlichen
            Verzeichnissen von Google Books und Open Library. Diese werden auf den eigenen
            Server übernommen und von dort ausgeliefert, jeweils mit Angabe der Quelle;
            eingebunden von fremden Servern wird nichts. Rechteinhaber können die Entfernung
            einer einzelnen Abbildung unter der oben genannten Adresse verlangen. Die
            bibliografischen Daten stammen unter anderem von der Deutschen Nationalbibliothek
            und stehen dort unter CC0.
            TEXT;
    }

    /**
     * The cookies an installation actually sets.
     *
     * With the language switch turned off nothing ever writes the language
     * cookie, and a privacy policy that lists one is wrong in the direction
     * that matters: it describes processing that does not happen, which is
     * the kind of error nobody ever finds because nobody is harmed by it.
     */
    private static function cookies(bool $multilingual): string
    {
        /* One line per paragraph, because this is spliced in after unwrap()
           has run and the renderer turns a newline inside a paragraph into a
           line break. List items keep their own lines, which is the point of
           holding this back until then. */
        $items = ['- **Sitzungs-Cookie** – nur nach dem Anmelden. Es hält die Sitzung'
            . ' der angemeldeten Person und wird beim Abmelden gelöscht.'];
        if ($multilingual) {
            $items[] = '- **Sprach-Cookie** – merkt sich, ob die Oberfläche auf Deutsch'
                . ' oder Englisch angezeigt werden soll. Es enthält ausschließlich diese'
                . ' Angabe und läuft nach einem Jahr ab.';
        }

        $one = count($items) === 1;

        return ($one ? 'Diese Seite setzt ein Cookie, technisch notwendig'
                     : 'Diese Seite setzt zwei Cookies, beide technisch notwendig')
            . ' im Sinne des § 25 Abs. 2 TDDDG. Eine Einwilligung ist dafür nicht'
            . " erforderlich, weshalb es hier auch kein Cookie-Banner gibt:\n\n"
            . implode("\n", $items) . "\n\n"
            . 'Wer angemeldet bleibt, erhält zusätzlich ein Anmelde-Token als Cookie. Es'
            . ' enthält keine personenbezogenen Angaben, sondern eine Zufallszeichenfolge,'
            . ' und wird bei jeder Nutzung ausgetauscht.';
    }

    private static function privacy(string $address, string $host, string $siteName): string
    {
        /* The three claims below that nobody can check from inside the
           application. They read as statements of fact and are in truth
           tasks - which is the sort of error that is never found, because
           nobody is harmed by a privacy policy that is too flattering until
           somebody is. */
        return self::draftNotice(
            'Zu prüfen ist außerdem, ob stimmt, was hier behauptet wird: dass mit dem'
                . ' Hoster ein Vertrag zur Auftragsverarbeitung besteht, wie lange er die'
                . ' Server-Logs wirklich aufbewahrt, und dass die Seite tatsächlich nichts'
                . ' von fremden Servern nachlädt. Letzteres gilt für diese Anwendung im'
                . ' Auslieferungszustand und endet mit der ersten eingebundenen fremden'
                . ' Schrift, Karte oder Statistik.'
        )
            . "## Verantwortliche\n\n" . self::HOLD_ADDRESS . "\n\n"
            . "## Hosting\n\n"
            . 'Diese Seite wird gehostet bei: ' . $host . ". Mit dem Anbieter besteht ein\n"
            . "Vertrag zur Auftragsverarbeitung nach Art. 28 DSGVO.\n\n"
            . <<<TEXT
                ## Server-Logfiles

                Beim Aufruf dieser Seite werden technisch notwendige Zugriffsdaten
                gespeichert: aufgerufene Adresse, Zeitpunkt, übertragene Datenmenge,
                Browsertyp und IP-Adresse. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO;
                das berechtigte Interesse liegt im sicheren und störungsfreien Betrieb.
                Diese Daten werden nach sieben Tagen gelöscht und nicht mit anderen Daten
                zusammengeführt.

                ## Cookies

                {{cookies}}

                ## Keine Analyse, keine externen Dienste

                Diese Seite verwendet keine Reichweitenmessung, keine Analysewerkzeuge und
                keine Social-Media-Plugins. Schriftarten, Skripte und Stylesheets werden
                ausschließlich vom eigenen Server ausgeliefert; es werden keine Inhalte von
                Content-Delivery-Netzwerken oder von Google Fonts nachgeladen. Beim Besuch
                dieser Seite entsteht damit keine Verbindung zu Dritten.

                ## Coverabbildungen

                Alle auf dieser Seite gezeigten Coverabbildungen werden vom eigenen Server
                ausgeliefert. Es werden keine Bilder von fremden Servern nachgeladen; beim
                Betrachten des Regals entsteht daher keine Verbindung zu Dritten.

                ## Verwaltungsbereich

                Das Erfassen und Bearbeiten von Büchern steht ausschließlich der Betreiberin
                nach Anmeldung zur Verfügung. Daten von Besucherinnen und Besuchern werden
                dabei nicht verarbeitet.

                ## Ihre Rechte

                Sie haben das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16),
                Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18),
                Datenübertragbarkeit (Art. 20) und Widerspruch (Art. 21). Wenden Sie sich
                dafür an die oben genannte Adresse. Zudem besteht ein Beschwerderecht bei
                einer Datenschutz-Aufsichtsbehörde.

                ## Verschlüsselung

                $siteName ist ausschließlich über eine verschlüsselte Verbindung (TLS)
                erreichbar. Aufrufe über HTTP werden automatisch weitergeleitet.
                TEXT;
    }
}
