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
            '• First version: the gateway connects Symcon via EEBUS (SHIP/SPINE) to a device in the home network, e.g. a control box under § 14a EnWG.',
            '• Its own certificate and SKI are created automatically; the remote device is checked once and then pinned.',
            '• Application modules (e.g. SteuerboxHub) attach as child instances to the gateway and receive limits and the heartbeat state.',
        ],
        '0.1.3' => [
            '• 🪟 New: choose the form layout yourself – in “Form: layout” you decide for each section whether it is expanded, collapsed or hidden when the form opens, and where it appears.',
        ],
        '0.2.0' => [
            '• 🌍 New: the module is bilingual (German/English) and follows the language of the Symcon console.',
        ],
        '0.3.0' => [
            '• 🔗 New: pairing via the SHIP Pairing Service. The gateway shows its pairing data as text and QR code for the metering point operator; the control box then announces itself, and the gateway checks it and trusts it by itself - no address, port or SKI to enter.',
            '• The “This gateway” section is now at the bottom with the actions.',
        ],
        '0.4.0' => [
            '• 🔁 New: the gateway can also act as energy management towards EEBUS devices such as heat pumps or wallboxes - it subscribes to their data and writes limits on behalf of an application module (first of all NRG-Stack WPEEBUSHub for heat pumps).',
            '• One gateway per device: create a separate gateway for each heat pump, wallbox or control box.',
        ],
        '0.5.0' => [
            '• 🔄 New: server role - devices that only open the connection themselves (e.g. Wolf Link) can now connect to the gateway. Choose the direction under “Remote device”, announce the gateway in the network (DNS-SD) and trust the device when it reports.',
            '• TLS for this direction is done by the gateway itself (mandatory EEBUS cipher, client certificate required); the server socket only passes the bytes through.',
            '• Fix: large EEBUS messages split by the remote device into several WebSocket frames are now put back together.',
        ],
        '0.6.0' => [
            '• 🔍 New: “Search the network” lists the EEBUS devices announced in the home network with SKI, address and port. A click on a device enters the values - no more guessing the port.',
            '• The message log now names the step and reason when an EEBUS connection is aborted or closed (once per new reason, not with every attempt).',
        ],
        '0.6.1' => [
            '• Fix: large EEBUS messages are now split into WebSocket frames of at most 4 KB, like eebus-go does. A Vaillant heat pump closed the connection with “Frame Error” as soon as the gateway sent its device overview in one piece.',
            '• After changing the connection direction, “Replace socket to match the direction” creates the matching client or server socket - no more creating it by hand.',
        ],
        '0.6.2' => [
            '• Fix: SPINE messages now wrap their payload exactly like the reference software ({"payload":{"datagram":…}}). A real Vaillant heat pump closed the connection with “Frame Error” right after the gateway’s first reply - this was the actual cause.',
            '• Fix: Symcon no longer shows the warning “parent instance incompatible” when the gateway is attached to a server socket.',
        ],
        '0.6.3' => [
            '• Fix: the gateway now sends a WebSocket ping every 50 seconds as the SHIP specification requires. A real Vaillant closed the connection about every minute without it.',
            '• The gateway answers queries for its operating state (like evcc).',
            '• New debug channel “SPINE-Inhalt” with the complete EEBUS messages for remote diagnosis of real devices.',
        ],
        '0.7.0' => [
            '• 🛡️ New: application modules can register additional entities, e.g. a separate grid guard (GridGuard) for power limits, as evcc does. A real Vaillant heat pump accepts power limits only from there and otherwise closed the connection every minute.',
            '• The message log no longer shows a second abort entry after the remote device has closed the connection.',
        ],
    ];

    private static $EEBGW_REPO_URL     = 'https://github.com/DG65/NRGEEBUSGateway';
    private static $EEBGW_ISSUES_URL   = 'https://github.com/DG65/NRGEEBUSGateway/issues';
    private static $EEBGW_LICENSE_URL  = 'https://github.com/DG65/NRGEEBUSGateway/blob/main/LICENSE';
    private static $EEBGW_PAYPAL_URL   = 'https://paypal.me/DietmarGureth';
    private static $EEBGW_LIBRARY_GUID = '{1EA1FC14-0E05-4D19-8FEB-28E207100499}';

    /** @var array Übersetzungen dieses Aufrufs (Stolperstein 24: jeden Text nur einmal übersetzen). */
    private $eebgwUebersetzt = [];

    /**
     * Englischen Quelltext in die Sprache der Konsole übersetzen (locale.json), Platzhalter %s wie sprintf.
     * Jeder Text wird je Aufruf nur einmal an Symcon gegeben.
     */
    protected function t(string $text, string ...$werte): string
    {
        if (!isset($this->eebgwUebersetzt[$text])) {
            $this->eebgwUebersetzt[$text] = (string)$this->Translate($text);
        }
        $aus = $this->eebgwUebersetzt[$text];
        if ($werte === []) {
            return $aus;
        }
        $mit = @vsprintf($aus, $werte);
        return $mit === false ? $aus : $mit;
    }

    /**
     * Kennzeichnet einen festen Formulartext, der nicht direkt hinter 'caption' => steht.
     * Übersetzt NICHT (das macht die Konsole, Store-Checkliste 25); nur damit
     * .tools/i18n-texte.php den Text für locale.json findet.
     */
    protected static function fest(string $text): string
    {
        return $text;
    }

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
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'The EEBUS gateway establishes the encrypted EEBUS connection (SHIP/SPINE) to a device in the home network, e.g. to a grid operator\'s control box under § 14a EnWG.'],
                ['type' => 'Label', 'caption' => 'It only takes care of the connection: certificate, pairing, connection setup, heartbeat. What happens with the values is decided by the application modules attached as child instances (e.g. NRG-Stack SteuerboxHub for the power limitation).'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'EEBGW_AckPurposeIntro($id);'],
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
            $items[] = ['type' => 'Label', 'caption' => $this->t('Version %s:', (string)$version)];
            foreach ($lines as $line) {
                // Feste Texte übersetzt die Konsole selbst (Store-Checkliste 25).
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'EEBGW_AckNews($id);'];
        $keys = array_keys($pending);
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => $this->t('🆕  New up to version %s', (string)end($keys)),
            'items' => $items,
        ];
    }

    protected function formDocPanel(): array
    {
        $lib = $this->eebgwLibrary();
        $ver = isset($lib['Version'])
            ? 'ℹ️ ' . $this->t('EEBUS gateway version %s (build %s)', (string)$lib['Version'], (string)($lib['Build'] ?? '?'))
            : self::fest('ℹ️ EEBUS gateway');
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '📖  Documentation & help',
            'items' => [
                ['type' => 'Label', 'caption' => $ver],
                ['type' => 'Label', 'caption' => 'Setup in three steps: 1. Enter address and port of the remote device (see its manual or app). 2. Click “Check remote device” – the gateway fetches the certificate and shows the SKI. If it matches the SKI of the remote device, trust it. 3. Trust the SKI of this gateway in the remote device (app, web interface or with the grid operator).'],
                ['type' => 'Label', 'caption' => 'If your metering point operator uses the SHIP Pairing Service, use “Pairing via the metering point operator” at the bottom instead: you hand over the QR code, and the gateway finds the control box by itself.'],
                ['type' => 'Label', 'caption' => 'The SKI is the fingerprint of an EEBUS device. Each side must know the other side\'s SKI; only then can a connection be established.'],
                ['type' => 'Label', 'caption' => 'Most devices are reached by the gateway. Devices that only open the connection themselves (e.g. Wolf Link): choose “The device connects to the gateway” under “Remote device”, announce the gateway in the network at the bottom and trust it in the app of the device; when the device reports, trust its SKI here.'],
                ['type' => 'Button', 'caption' => 'Manual on GitHub', 'onClick' => "echo '" . self::$EEBGW_REPO_URL . "';", 'link' => true],
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
            'caption' => '💬  Feedback',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 The EEBUS gateway is new. Which device did you connect it to, and did it work? Feedback with manufacturer and model helps a lot.'],
                ['type' => 'Button', 'caption' => 'Report a problem on GitHub', 'onClick' => "echo '" . self::$EEBGW_ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'EEBGW_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of real enthusiasm for my own setup — and quite a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::$EEBGW_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'You like the module and would like to leave something anyway? I am happy about a small donation — completely voluntary, nothing required in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::$EEBGW_PAYPAL_URL . "';", 'link' => true],
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
            $anzeige = $this->t($name) . (self::eebgwBereich($fach[$key]) === 'actions' ? $this->t(' (below, with the actions)') : '');
            $rows[] = ['Key' => (string)$key, 'Panel' => $anzeige, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $this->t($name)));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => $this->t('🙈 Hidden: %s — to show again, clear the “Hidden” checkbox. Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => $this->t('🪟  Form: layout') . ($hidden ? $this->t(' (%s hidden)', (string)count($hidden)) : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing, so this setting applies every time the form opens – after “Apply”, from the next opening on.'],
                ['type' => 'Label', 'caption' => 'No checkbox means “default”: as the module intends (e.g. “Remote device” expanded as long as none is set). With equal positions the default order applies. “Hidden” only removes a section from the display; its settings are kept and stay in effect. If several boxes are ticked, “Hidden” wins over “Collapsed” over “Expanded”. The sections at the top (purpose, news, documentation) and at the bottom (feedback, about this module) stay fixed.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Sections', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Section', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo EEBGW_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** EEBGW_ResetFormLayout($id): string — Aufbau im offenen Formular auf Standard (gilt nach „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        $rows = [];
        $i = 0;
        foreach ($this->eebgwFachpanels() as $key => $panel) {
            $anzeige = $this->t(self::eebgwPanelName($panel, (string)$key)) . (self::eebgwBereich($panel) === 'actions' ? $this->t(' (below, with the actions)') : '');
            $rows[] = ['Key' => (string)$key, 'Panel' => $anzeige, 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', (string)json_encode($rows, JSON_UNESCAPED_UNICODE));
        return 'ℹ️ ' . $this->t('Set to default. Click “Apply” below to save.');
    }

    /** Bereich eines Fachpanels: 'elements' (Standard) oder 'actions' (Felder ohne Eigenschaft). */
    private static function eebgwBereich(array $panel): string
    {
        return ($panel['bereich'] ?? 'elements') === 'actions' ? 'actions' : 'elements';
    }

    protected function eebgwBuildForm(array $fachpanels, array $actions, array $status): string
    {
        $layout = $this->eebgwFormLayout(array_keys($fachpanels));
        $bereiche = ['elements' => [], 'actions' => []];
        foreach ($layout as $key => $l) {
            $panel = $fachpanels[$key];
            $bereich = self::eebgwBereich($panel);
            unset($panel['bereich']);
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen“ erhalten.
                $panel['visible'] = false;
            }
            // Die Position gilt innerhalb des jeweiligen Bereichs (SUITE.md 3a).
            $bereiche[$bereich][] = $panel;
        }
        $elements = array_values(array_filter(array_merge(
            [$this->formPurposeIntro(), $this->formNewsBanner(), $this->formDocPanel()],
            $bereiche['elements'],
            [$this->formLayoutPanel($layout, $fachpanels), $this->formForumHint(), $this->formLicenseHint()]
        )));
        return (string)json_encode(['elements' => $elements, 'actions' => array_merge($bereiche['actions'], $actions), 'status' => $status]);
    }
}
