<?php

declare(strict_types=1);

// ===========================================================================
// Formular-Rahmen nach der NRG-Stack-Konvention (SUITE.md „Einheitliche
// Formular-Optik“): „Wozu dieses Modul?“ → „Neu bis Version“ →
// „Dokumentation & Hilfe“ → Fachpanels → Rückmeldungen → „Über dieses Modul“.
// Vorbild: RoboHubFormular. Trait-Name mit Modul-Präfix, weil Klassen- und
// Trait-Namen in Symcon prozessweit global sind.
// ===========================================================================

trait EEBGWFormular
{
    // „Was ist neu": Schlüssel = Version ohne Beta-/Build-Suffix.
    private static $EEBGW_NEWS_VERSIONS = [
        '0.1.0' => [
            '• Erste Fassung: Das Gateway verbindet Symcon über EEBUS (SHIP/SPINE) mit einem Gerät im Heimnetz, z. B. einer Steuerbox nach § 14a EnWG.',
            '• Eigenes Zertifikat und SKI werden automatisch erzeugt; die Gegenstelle wird einmal geprüft und danach fest hinterlegt.',
            '• Fachmodule (z. B. SteuerboxHub) hängen sich als Kind-Instanzen an das Gateway und bekommen Grenzwerte und Heartbeat gemeldet.',
        ],
        '0.1.3' => [
            '• 🪟 Neu: Formular-Aufbau selbst wählen – im Bereich „Formular: Aufbau“ legst du je Bereich fest, ob er beim Öffnen aufgeklappt, zugeklappt oder ausgeblendet ist und an welcher Stelle er steht.',
        ],
    ];

    private static $EEBGW_REPO_URL     = 'https://github.com/DG65/NRGEEBUSGateway';
    private static $EEBGW_ISSUES_URL   = 'https://github.com/DG65/NRGEEBUSGateway/issues';
    private static $EEBGW_LICENSE_URL  = 'https://github.com/DG65/NRGEEBUSGateway/blob/main/LICENSE';
    private static $EEBGW_PAYPAL_URL   = 'https://paypal.me/DietmarGureth';
    private static $EEBGW_LIBRARY_GUID = '{1EA1FC14-0E05-4D19-8FEB-28E207100499}';

    protected function registerFormAttributes(): void
    {
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        // Gespeichert wird die tatsächlich installierte Version (SUITE.md Formular-Optik Punkt 1).
        $this->WriteAttributeString('SeenNews', $this->eebgwInstalledVersion());
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    protected function eebgwLibrary(): array
    {
        $lib = function_exists('IPS_GetLibrary') ? @IPS_GetLibrary(self::$EEBGW_LIBRARY_GUID) : false;
        return is_array($lib) ? $lib : [];
    }

    protected function eebgwInstalledVersion(): string
    {
        $lib = $this->eebgwLibrary();
        if (!empty($lib['Version'])) {
            return (string)preg_replace('/-.*$/', '', (string)$lib['Version']);
        }
        $keys = array_keys(self::$EEBGW_NEWS_VERSIONS);
        usort($keys, 'version_compare');
        return (string)end($keys);
    }

    protected function formPurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Das EEBUS-Gateway stellt die verschlüsselte EEBUS-Verbindung (SHIP/SPINE) zu einem Gerät im Heimnetz her, z. B. zu einer Steuerbox des Netzbetreibers nach § 14a EnWG.'],
                ['type' => 'Label', 'caption' => 'Es kümmert sich nur um die Verbindung: Zertifikat, Kopplung, Verbindungsaufbau, Heartbeat. Was mit den Werten geschieht, entscheiden die Fachmodule, die als Kind-Instanzen am Gateway hängen (z. B. NRG-Stack SteuerboxHub für die Leistungsbegrenzung).'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'EEBGW_AckPurposeIntro($id);'],
            ],
        ];
    }

    protected function formNewsBanner(): ?array
    {
        $seen = $this->ReadAttributeString('SeenNews');
        $pending = [];
        foreach (self::$EEBGW_NEWS_VERSIONS as $version => $lines) {
            if (version_compare((string)$version, $seen === '' ? '0' : $seen, '>')) {
                $pending[(string)$version] = $lines;
            }
        }
        if ($pending === []) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        foreach ($pending as $version => $lines) {
            $items[] = ['type' => 'Label', 'caption' => 'Version ' . $version . ':'];
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'EEBGW_AckNews($id);'];
        $keys = array_keys($pending);
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu bis Version ' . end($keys),
            'items' => $items,
        ];
    }

    protected function formDocPanel(): array
    {
        $lib = $this->eebgwLibrary();
        $ver = isset($lib['Version'])
            ? 'ℹ️ EEBUS-Gateway Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ EEBUS-Gateway';
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '📖  Dokumentation & Hilfe',
            'items' => [
                ['type' => 'Label', 'caption' => $ver],
                ['type' => 'Label', 'caption' => 'Einrichtung in drei Schritten: 1. Adresse und Port der Gegenstelle eintragen (stehen in deren Anleitung oder App). 2. „Gegenstelle prüfen“ klicken – das Gateway holt das Zertifikat ab und zeigt den SKI. Stimmt er mit dem SKI der Gegenstelle überein, „Übernehmen“. 3. Den SKI dieses Gateways in der Gegenstelle freigeben (App, Weboberfläche oder beim Netzbetreiber).'],
                ['type' => 'Label', 'caption' => 'Der SKI ist der Fingerabdruck eines EEBUS-Geräts. Beide Seiten müssen den SKI der jeweils anderen kennen, erst dann kommt eine Verbindung zustande.'],
                ['type' => 'Label', 'caption' => 'Das Gateway verbindet sich als Client zur Gegenstelle. Geräte, die ausschließlich selbst eine Verbindung aufbauen wollen, werden in dieser Fassung noch nicht unterstützt.'],
                ['type' => 'Button', 'caption' => 'Anleitung auf GitHub', 'onClick' => "echo '" . self::$EEBGW_REPO_URL . "';", 'link' => true],
            ],
        ];
    }

    protected function formForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        // Forum-Thread folgt mit der ersten Veröffentlichung; bis dahin GitHub.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 Das EEBUS-Gateway ist neu. Mit welchem Gerät hast du es verbunden, und hat es geklappt? Rückmeldungen mit Hersteller und Modell helfen sehr.'],
                ['type' => 'Button', 'caption' => 'Fehler auf GitHub melden', 'onClick' => "echo '" . self::$EEBGW_ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'EEBGW_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::$EEBGW_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::$EEBGW_PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /** Fachpanels in Standardreihenfolge, Schlüssel => Panel (liefert das Modul). */
    abstract protected function eebgwFachpanels(): array;

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus „FormLayout“ (SUITE.md 3a, Vorbild Batteriewächter).
     * Unbekannte oder fehlende Einträge fallen auf Standard und Standardposition zurück.
     * Rückgabe: Schlüssel => ['open' => std|open|closed|hidden, 'pos' => int, 'idx' => int], sortiert.
     */
    protected function eebgwFormLayout(array $keys): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach (array_values($keys) as $i => $k) {
            $r = $by[$k] ?? [];
            // Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt; kein Haken = Standard.
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = ['open' => $open, 'pos' => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1, 'idx' => $i];
        }
        uasort($out, function ($a, $b) {
            return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']];
        });
        return $out;
    }

    private static function eebgwPanelName(array $panel, string $key): string
    {
        return trim((string)preg_replace('/\s*\(.*$/u', '', (string)($panel['caption'] ?? $key)));
    }

    protected function formLayoutPanel(array $layout, array $fach): array
    {
        $rows = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = self::eebgwPanelName($fach[$key], (string)$key);
            $rows[] = ['Key' => (string)$key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => '🙈 Ausgeblendet: ' . implode(', ', $hidden) . ' — zum Wiederzeigen den Haken bei „Ausgeblendet“ entfernen. Ausgeblendete Bereiche arbeiten weiter, nur die Anzeige fehlt.']]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🪟  Formular: Aufbau' . ($hidden ? ' (' . count($hidden) . ' ausgeblendet)' : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Hier legst du fest, welche Bereiche beim Öffnen des Formulars aufgeklappt sind und in welcher Reihenfolge sie stehen. Symcon merkt sich das Auf- und Zuklappen nicht selbst, deshalb gilt die Einstellung jedes Mal beim Öffnen – nach „Übernehmen“ ab dem nächsten Öffnen.'],
                ['type' => 'Label', 'caption' => 'Kein Haken heißt „Standard“: wie das Modul es vorsieht (z. B. „Gegenstelle“ aufgeklappt, solange noch keine festgelegt ist). Bei gleicher Position bleibt die Standardreihenfolge. „Ausgeblendet“ nimmt einen Bereich nur aus der Anzeige, seine Einstellungen bleiben erhalten und wirken weiter. Sind mehrere Haken gesetzt, gilt „Ausgeblendet“ vor „Zugeklappt“ vor „Aufgeklappt“. Die Bereiche oben (Zweck, Neu, Dokumentation) und unten (Rückmeldungen, Über dieses Modul) bleiben fest.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Bereiche', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Bereich', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Schlüssel', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Aufgeklappt', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Zugeklappt', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Ausgeblendet', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Auf Standard zurücksetzen', 'onClick' => 'echo EEBGW_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** EEBGW_ResetFormLayout($id): string — Aufbau im offenen Formular auf Standard (gilt nach „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        $rows = [];
        $i = 0;
        foreach ($this->eebgwFachpanels() as $key => $panel) {
            $rows[] = ['Key' => (string)$key, 'Panel' => self::eebgwPanelName($panel, (string)$key), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', (string)json_encode($rows, JSON_UNESCAPED_UNICODE));
        return 'ℹ️ Auf Standard gesetzt. Zum Speichern unten „Übernehmen“ klicken.';
    }

    protected function eebgwBuildForm(array $fachpanels, array $actions, array $status): string
    {
        $layout = $this->eebgwFormLayout(array_keys($fachpanels));
        $mitte = [];
        foreach ($layout as $key => $l) {
            $panel = $fachpanels[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen“ erhalten.
                $panel['visible'] = false;
            }
            $mitte[] = $panel;
        }
        $elements = array_values(array_filter(array_merge(
            [$this->formPurposeIntro(), $this->formNewsBanner(), $this->formDocPanel()],
            $mitte,
            [$this->formLayoutPanel($layout, $fachpanels), $this->formForumHint(), $this->formLicenseHint()]
        )));
        return (string)json_encode(['elements' => $elements, 'actions' => $actions, 'status' => $status]);
    }
}
