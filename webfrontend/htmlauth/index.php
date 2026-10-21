<?php
/**
 * CKW Dynamischer Tarif - Weboberflaeche (Einstellungen, Preisuebersicht, Statistik, Loxone-Einbindung)
 */

require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "loxberry_io.php";
require_once "$lbpbindir/ckw_lib.php";

function h($s)
{
	return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$ckwcfg = ckw_load_config();
$tariffs = ckw_load_tariffs($ckwcfg['tariffs_remote'], null, false);   // Anzeige: nur gespeicherte Stände, kein Netzzugriff
$products = ckw_product_list($tariffs['data']);
$fetchCmd = 'php ' . escapeshellarg("$lbpbindir/fetch.php");
$today = ckw_local_time(time(), 'Y-m-d');   // Schweizer Datum (PHP-Standardzeitzone auf dem LoxBerry ist UTC)

// ---------------------------------------------------------------------------
// Download Loxone-Vorlage (Virtueller UDP-Eingang)
// ---------------------------------------------------------------------------
if (isset($_GET['download']) && $_GET['download'] === 'udp') {
	$state = ckw_read_json(ckw_state_file());
	$keys = ($state && isset($state['values'])) ? array_keys($state['values']) : array();
	$xml = ckw_udp_template_xml($keys, $ckwcfg['udp_port'], $state && isset($state['unit']) ? $state['unit'] : 'CHF/kWh');
	header('Content-Type: application/xml; charset=utf-8');
	header('Content-Disposition: attachment; filename="VIU_CKW_Dynamisch.xml"');
	echo $xml;
	exit;
}

// ---------------------------------------------------------------------------
// Statistik: gewaehlter Zeitraum (Tag / Monat / Jahr)
// ---------------------------------------------------------------------------
$statView = isset($_GET['view']) && in_array($_GET['view'], array('day', 'month', 'year', 'setup'), true) ? $_GET['view'] : 'day';
$tzCh = new DateTimeZone(CKW_TZ);
$statRef = new DateTimeImmutable('now', $tzCh);
if ($statView === 'day' && isset($_GET['d']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['d'])) {
	$statRef = DateTimeImmutable::createFromFormat('!Y-m-d', $_GET['d'], $tzCh) ?: $statRef;
} elseif ($statView === 'month' && isset($_GET['m']) && preg_match('/^\d{4}-\d{2}$/', $_GET['m'])) {
	$statRef = DateTimeImmutable::createFromFormat('!Y-m-d', $_GET['m'] . '-01', $tzCh) ?: $statRef;
} elseif ($statView === 'year' && isset($_GET['y']) && preg_match('/^\d{4}$/', $_GET['y'])) {
	$statRef = DateTimeImmutable::createFromFormat('!Y-m-d', $_GET['y'] . '-01-01', $tzCh) ?: $statRef;
}
$statRef = $statRef->setTime(12, 0, 0);   // Mitte des Tages: robust bei Zeitumstellung

// CSV-Export des gewaehlten Zeitraums (Viertelstunden)
if (isset($_GET['csv']) && in_array($statView, array('day', 'month', 'year'), true)) {
	list($a, $z) = ckw_stats_period($statView, $statRef->getTimestamp());
	$name = $statRef->format($statView === 'day' ? 'Y-m-d' : ($statView === 'month' ? 'Y-m' : 'Y'));
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="ckw_statistik_' . $name . '.csv"');
	try {
		echo "\xEF\xBB\xBF" . ckw_stats_csv($a, $z);   // BOM fuer Excel (Umlaute)
	} catch (Exception $e) {
		echo 'Fehler: ' . $e->getMessage();
	}
	exit;
}

// ---------------------------------------------------------------------------
// Formularaktionen
// ---------------------------------------------------------------------------
$page = isset($_GET['page']) ? $_GET['page'] : 'settings';
$messages = array();
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
	if ($_POST['action'] === 'save') {
		$in = array_merge($ckwcfg, $_POST);   // Felder anderer Seiten (Statistik) bleiben erhalten
		foreach (array('vat_enabled', 'series_rel', 'series_abs', 'series_tmr', 'mqtt_enabled', 'udp_enabled', 'tariffs_remote', 'formula_enabled') as $cb) {
			$in[$cb] = isset($_POST[$cb]) ? '1' : '0';
		}
		$in['components'] = isset($_POST['components']) && is_array($_POST['components']) ? $_POST['components'] : array();
		if (isset($_POST['select_miniserver'])) {
			$in['udp_msnr'] = $_POST['select_miniserver'];
		}
		$new = ckw_sanitize_config($in, $errors);
		if (!$errors) {
			if (ckw_save_config($new)) {
				$ckwcfg = $new;
				exec("$fetchCmd > /dev/null 2>&1 &");
				$messages[] = 'Einstellungen gespeichert. Die Preise werden im Hintergrund neu abgerufen und gesendet.';
			} else {
				$errors[] = 'Einstellungen konnten nicht gespeichert werden (Schreibrechte?).';
			}
		} else {
			$ckwcfg = $new;
		}
	} elseif ($_POST['action'] === 'stats_save' || $_POST['action'] === 'meter_test') {
		// Statistik -> Einrichtung: nur die Zaehler-Felder uebernehmen
		$in = $ckwcfg;
		foreach (array('meter_source', 'meter_msnr', 'meter_name', 'meter_output', 'meter_topic', 'meter_unit', 'meter_max_kwh') as $f) {
			if (isset($_POST[$f])) {
				$in[$f] = $_POST[$f];
			}
		}
		$new = ckw_sanitize_config($in, $errors);
		$ckwcfg = $new;
		if ($_POST['action'] === 'meter_test') {
			$errors = array();
			$merr = null;
			$mval = $new['meter_source'] === 'off' ? null : ckw_stats_read_meter($new, $merr);
			if ($mval !== null) {
				$messages[] = 'Zählerstand gelesen: ' . number_format($mval, 3, '.', "'") . ' kWh (noch nicht gespeichert).';
			} else {
				$errors[] = 'Zählerstand nicht gelesen: ' . ($merr ?: 'Bitte zuerst eine Quelle wählen.');
			}
		} elseif (!$errors) {
			if (ckw_save_config($new)) {
				exec("$fetchCmd > /dev/null 2>&1 &");
				$messages[] = 'Einstellungen der Statistik gespeichert.' . ($new['meter_source'] !== 'off' ? ' Ab der nächsten Viertelstunde wird der Verbrauch erfasst.' : '');
			} else {
				$errors[] = 'Einstellungen konnten nicht gespeichert werden (Schreibrechte?).';
			}
		}
	} elseif ($_POST['action'] === 'stats_reset') {
		try {
			ckw_stats_reset();
			$messages[] = 'Statistik zurückgesetzt. Die Erfassung beginnt ab der nächsten Viertelstunde neu.';
		} catch (Exception $e) {
			$errors[] = 'Statistik konnte nicht zurückgesetzt werden: ' . $e->getMessage();
		}
	} elseif ($_POST['action'] === 'fetch') {
		$output = array();
		$rc = 0;
		exec("$fetchCmd 2>&1", $output, $rc);
		if ($rc === 0) {
			$messages[] = 'Preise erfolgreich abgerufen und gesendet.';
		} elseif ($rc === 2) {
			$errors[] = 'Es läuft gerade schon ein Abruf (Cronjob oder nach dem Speichern) - bitte in einer Minute erneut versuchen.';
		} else {
			$errors[] = 'Abruf mit Fehlern beendet - Details im Log.';
		}
	}
}

$state = ckw_read_json(ckw_state_file());

// ---------------------------------------------------------------------------
// Seitenkopf
// ---------------------------------------------------------------------------
$template_title = 'CKW Dynamischer Tarif';
$helplink = 'https://wiki.loxberry.de/plugins/ckwdynamic/start';
$navbar[1]['Name'] = 'Einstellungen';
$navbar[1]['URL'] = 'index.php?page=settings';
$navbar[2]['Name'] = 'Preise';
$navbar[2]['URL'] = 'index.php?page=prices';
$navbar[3]['Name'] = 'Statistik';
$navbar[3]['URL'] = 'index.php?page=stats';
$navbar[4]['Name'] = 'Loxone';
$navbar[4]['URL'] = 'index.php?page=loxone';
$navbar[5]['Name'] = 'Log';
$navbar[5]['URL'] = LBWeb::loglist_url(array('NAME' => 'Abruf'));
$navbar[5]['target'] = '_blank';
$active = array('settings' => 1, 'prices' => 2, 'stats' => 3, 'loxone' => 4);
$navbar[isset($active[$page]) ? $active[$page] : 1]['active'] = true;

LBWeb::lbheader($template_title, $helplink, 'help.html');
?>
<style>
	.ckw-msg { padding: 8px 12px; border-radius: 6px; margin: 8px 0; }
	.ckw-ok { background: #e3f5e1; border: 1px solid #6dbb63; }
	.ckw-err { background: #fde8e8; border: 1px solid #d9534f; }
	.ckw-warn { background: #fff6dd; border: 1px solid #e0b400; }
	.ckw-status { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
	.ckw-table { border-collapse: collapse; width: 100%; max-width: 900px; font-size: 0.95em; }
	.ckw-table th, .ckw-table td { border-bottom: 1px solid #ddd; padding: 4px 8px; text-align: left; }
	.ckw-table td.num { text-align: right; font-family: monospace; }
	.ckw-bar { height: 14px; background: #6dbb63; border-radius: 3px; }
	.ckw-bar.cheap { background: #2e8b57; }
	.ckw-bar.filled { background: repeating-linear-gradient(45deg, #ccc, #ccc 4px, #eee 4px, #eee 8px); }
	.ckw-hint { color: #666; font-size: 0.9em; }
	code { background: #f3f3f3; padding: 1px 4px; border-radius: 3px; }
	.ckw-section { margin-top: 24px; }
	.ckw-subnav a { margin-right: 4px; }
	.ckw-tiles { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
	.ckw-tile { border: 1px solid #ddd; border-radius: 6px; padding: 8px 12px; min-width: 130px; }
	.ckw-tile b { display: block; font-size: 1.3em; }
	.ckw-tile span { color: #666; font-size: 0.85em; }
	.ckw-chart { position: relative; width: 100%; max-width: 1000px; height: 340px; }
	.ckw-pos { color: #2e8b57; }
	.ckw-neg { color: #c0392b; }
</style>

<?php foreach ($messages as $m): ?>
	<div class="ckw-msg ckw-ok"><?= h($m) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $m): ?>
	<div class="ckw-msg ckw-err"><?= h($m) ?></div>
<?php endforeach; ?>

<?php
// ---------------------------------------------------------------------------
// Statuszeile (auf allen Seiten)
// ---------------------------------------------------------------------------
if ($state && isset($state['run'])) {
	$online = $state['values']['online'] === '1';
	$color = $online ? '#3cb043' : ($state['values']['data_valid'] === '1' ? '#e0b400' : '#d9534f');
	$text = $online ? 'Online - aktuelle Daten von CKW' : ($state['values']['data_valid'] === '1' ? 'CKW-API nicht erreichbar - verwende letzten Abruf' : 'Keine aktuellen Preisdaten');
	echo '<p><span class="ckw-status" style="background:' . $color . '"></span><b>' . h($text) . '</b> &middot; letzter Lauf: '
		. h(ckw_local_time($state['run']['time'])) . ' &middot; letzter erfolgreicher Abruf: ' . h($state['texts']['last_update_text'] ?: '-') . '</p>';
	if (!empty($state['texts']['price_warning'])) {
		echo '<div class="ckw-msg ckw-warn">' . h($state['texts']['price_warning']) . '</div>';
	}
	if ($state['run']['sent']['mqtt'] === false) {
		echo '<div class="ckw-msg ckw-err">MQTT-Versand fehlgeschlagen: ' . h(isset($state['run']['errors']['mqtt']) ? $state['run']['errors']['mqtt'] : 'siehe Log') . '</div>';
	}
	if ($state['run']['sent']['udp'] === false) {
		echo '<div class="ckw-msg ckw-err">UDP-Versand fehlgeschlagen: ' . h(isset($state['run']['errors']['udp']) ? $state['run']['errors']['udp'] : 'Miniserver-Einstellungen prüfen') . '</div>';
	}
} else {
	echo '<div class="ckw-msg ckw-warn">Noch kein Abruf erfolgt. Einstellungen speichern oder "Jetzt abrufen" klicken.</div>';
}
?>

<form method="post" action="index.php?page=<?= h($page) ?>" style="display:inline" data-ajax="false">
	<input type="hidden" name="action" value="fetch">
	<button type="submit" data-inline="true" data-mini="true" data-icon="refresh">Jetzt abrufen</button>
</form>

<?php if ($page === 'settings'): ?>
<!-- =================================================================== -->
<!-- EINSTELLUNGEN                                                        -->
<!-- =================================================================== -->
<form method="post" action="index.php?page=settings" data-ajax="false">
	<input type="hidden" name="action" value="save">

	<h3 class="ckw-section">Tarif &amp; Stromprodukt</h3>
	<div class="ui-field-contain">
		<label for="tariff">Netztarif</label>
		<select name="tariff" id="tariff">
			<?php foreach ($CKW_TARIFF_NAMES as $k => $n): ?>
				<option value="<?= h($k) ?>" <?= $ckwcfg['tariff'] === $k ? 'selected' : '' ?>><?= h($n) ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="ui-field-contain">
		<label for="product">Stromprodukt (Energie)</label>
		<select name="product" id="product">
			<?php foreach ($products as $k => $n):
				$pp = $k !== 'custom' ? ckw_product_price($tariffs['data'], $k, $today) : null;
				$label = $n . ($pp ? ' - ' . number_format($pp['rp'], 2, '.', '') . ' Rp./kWh' : '');
				if (!empty($tariffs['data']['products'][$k]['from_api'])) {
					$label .= ' (live aus CKW-API)';
				}
			?>
				<option value="<?= h($k) ?>" <?= $ckwcfg['product'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="ui-field-contain">
		<label for="custom_energy_rp">Eigener Energiepreis (Rp./kWh exkl. MwSt)</label>
		<input type="text" name="custom_energy_rp" id="custom_energy_rp" value="<?= h($ckwcfg['custom_energy_rp']) ?>">
	</div>
	<p class="ckw-hint">Nur bei „Anderes Produkt / eigener Preis“.</p>
	<label><input type="checkbox" name="tariffs_remote" value="1" <?= $ckwcfg['tariffs_remote'] ? 'checked' : '' ?>> Jahrespreise automatisch aktualisieren (aus der Tarifdatei der CKW, Rückfall: Tabelle auf GitHub)</label>
	<p class="ckw-hint">Verwendete Preisquellen: <?= h($tariffs['source']) ?><?= ($ckwcfg['tariffs_remote'] && !$tariffs['ckw']) ? ' - die CKW-Tarifdatei wurde noch nicht oder nicht erfolgreich gelesen (siehe Log).' : '' ?></p>
	<div class="ui-field-contain">
		<label for="municipality">Gemeinde (Konzessionsabgabe)</label>
		<select name="municipality" id="municipality">
			<option value="">- keine / nicht berücksichtigen -</option>
			<?php
			$feeText = function ($fee) {
				if (!$fee) {
					return '-';
				}
				if (isset($fee['entry']['percent'])) {
					$t = number_format($fee['entry']['percent'], 0) . ' % der Netznutzung' . (!empty($fee['entry']['excl_reserve']) ? ' (ohne Stromreserve)' : '');
					return $fee['rp'] > 0 ? $t . ' + ' . number_format($fee['rp'], 2, '.', '') . ' Rp./kWh' : $t;
				}
				return number_format($fee['rp'], 2, '.', '') . ' Rp./kWh';
			};
			foreach (array_keys($tariffs['data']['municipalities']) as $mn):
				$now = ckw_municipality_fee($tariffs['data'], $mn, $today);
				$future = ckw_municipality_fee($tariffs['data'], $mn, '9999-12-31');
				$label = $mn . ' - ' . ($now ? $feeText($now) : 'noch keine Abgabe');
				if ($future && (!$now || $future['valid_from'] !== $now['valid_from']) && $feeText($future) !== ($now ? $feeText($now) : '')) {
					$label .= ', ab ' . implode('.', array_reverse(explode('-', $future['valid_from']))) . ': ' . $feeText($future);
				}
			?>
				<option value="<?= h($mn) ?>" <?= $ckwcfg['municipality'] === $mn ? 'selected' : '' ?>><?= h($label) ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<p class="ckw-hint">Abgabe an die Gemeinde laut CKW (Preisblatt Konzessionsabgabe bzw. CKW-Tarifdatei, in Luzern inkl. Klimarappen). Bei Gemeinden mit „% der Netznutzung“ wird die Abgabe pro Viertelstunde aus dem dynamischen Netzpreis gerechnet. Wird zum Total addiert und als Komponente <code>concession</code> ausgegeben.</p>
	<div class="ui-field-contain">
		<label for="extra_rp">Weitere Zusatzkosten (Rp./kWh exkl. MwSt)</label>
		<input type="text" name="extra_rp" id="extra_rp" value="<?= h($ckwcfg['extra_rp']) ?>">
	</div>
	<p class="ckw-hint">Optional, wird nur zum Total addiert.</p>

	<h3 class="ckw-section">Darstellung</h3>
	<label><input type="checkbox" name="vat_enabled" value="1" <?= $ckwcfg['vat_enabled'] ? 'checked' : '' ?>> MwSt einrechnen (gilt für alle ausgegebenen Preise)</label>
	<div class="ui-field-contain">
		<label for="vat_rate">MwSt-Satz (%)</label>
		<input type="text" name="vat_rate" id="vat_rate" value="<?= h($ckwcfg['vat_rate']) ?>">
	</div>
	<div class="ui-field-contain">
		<label for="unit">Einheit</label>
		<select name="unit" id="unit">
			<option value="chf" <?= $ckwcfg['unit'] === 'chf' ? 'selected' : '' ?>>CHF/kWh</option>
			<option value="rp" <?= $ckwcfg['unit'] === 'rp' ? 'selected' : '' ?>>Rp./kWh</option>
		</select>
	</div>

	<h3 class="ckw-section">Ausgabewerte</h3>
	<p class="ckw-hint">Die aktuellen Preise (<code>&lt;komponente&gt;_now</code>) werden immer für alle Komponenten gesendet. Für die angehakten Komponenten zusätzlich Stundenreihen, Min/Max/Durchschnitt und nächste Stunde.</p>
	<fieldset data-role="controlgroup">
		<?php foreach ($CKW_COMPONENTS as $k => $n): ?>
			<label><input type="checkbox" name="components[]" value="<?= h($k) ?>" <?= in_array($k, $ckwcfg['components'], true) ? 'checked' : '' ?>> <code><?= h($k) ?></code> - <?= h($n) ?></label>
		<?php endforeach; ?>
	</fieldset>
	<fieldset data-role="controlgroup">
		<legend>Stundenreihen (für den Loxone Spotpreis-Optimierer)</legend>
		<label><input type="checkbox" name="series_rel" value="1" <?= $ckwcfg['series_rel'] ? 'checked' : '' ?>> Relativ: <code>_rel00</code> … <code>_rel23</code> (laufende Stunde +0 … +23)</label>
		<label><input type="checkbox" name="series_abs" value="1" <?= $ckwcfg['series_abs'] ? 'checked' : '' ?>> Absolut: <code>_abs00</code> … <code>_abs23</code> (heute 00:00 … 23:00)</label>
		<label><input type="checkbox" name="series_tmr" value="1" <?= $ckwcfg['series_tmr'] ? 'checked' : '' ?>> Morgen: <code>_tmr00</code> … <code>_tmr23</code> (ab ca. 12 Uhr verfügbar)</label>
	</fieldset>
	<div class="ui-field-contain">
		<label for="fill_mode">Fehlende Stunden füllen mit</label>
		<select name="fill_mode" id="fill_mode">
			<?php
			$ref = ckw_reference($tariffs['data'], $ckwcfg['tariff'], $today);
			$refText = $ref ? number_format($ref['rp'], 2, '.', '') . ' Rp./kWh' : 'nicht hinterlegt';
			?>
			<option value="ckwavg" <?= $ckwcfg['fill_mode'] === 'ckwavg' ? 'selected' : '' ?>>CKW-Durchschnittspreis (empfohlen)</option>
			<option value="max" <?= $ckwcfg['fill_mode'] === 'max' ? 'selected' : '' ?>>Höchstpreis der bekannten Stunden</option>
			<option value="last" <?= $ckwcfg['fill_mode'] === 'last' ? 'selected' : '' ?>>letztem bekannten Wert</option>
			<option value="zero" <?= $ckwcfg['fill_mode'] === 'zero' ? 'selected' : '' ?>>0</option>
		</select>
	</div>
	<p class="ckw-hint">Gefüllt werden nur Stunden ohne CKW-Preis: jeden Morgen bis ca. 12 Uhr die Stunden von morgen und bei einem Ausfall der CKW-API. <b>CKW-Durchschnittspreis</b> = von CKW kommunizierter mengengewichteter Durchschnitt der dynamischen Netznutzung (<?= h($CKW_TARIFF_NAMES[$ckwcfg['tariff']]) ?>: <?= h($refText) ?>) plus fixe Netzzuschläge, Stromprodukt und Abgaben - also ein realistischer Durchschnitts-Totalpreis.</p>
	<div class="ui-field-contain">
		<label for="cheap_hours">Günstigstes Zeitfenster (Stunden)</label>
		<input type="number" min="1" max="12" name="cheap_hours" id="cheap_hours" value="<?= h($ckwcfg['cheap_hours']) ?>">
	</div>
	<p class="ckw-hint">Sucht in den nächsten 24 Stunden den zusammenhängenden Block dieser Länge mit dem tiefsten Durchschnittspreis (Total) – z. B. für Boiler, Geschirrspüler oder Wallbox. Ergebnis: <code>cheap_start_off</code> (Start in Stunden ab jetzt), <code>cheap_start_clock</code> (Startzeit als Stunde 0–23) und <code>cheap_avg</code> (Ø-Preis). Es zählen nur echte CKW-Preise; ist kein Block möglich, wird -1 gesendet. Unabhängig vom Spotpreis-Optimierer.</p>

	<h3 class="ckw-section">Loxone Spotpreis-Optimierer im Spotmarkt-Modus „CKW“</h3>
	<label><input type="checkbox" name="formula_enabled" value="1" <?= $ckwcfg['formula_enabled'] ? 'checked' : '' ?>> Korrekturwerte für die Preisberechnung ausgeben (<code>formula_factor</code>, <code>formula_offset</code>)</label>
	<p class="ckw-hint">Für den Fall, dass im Spotpreis-Optimierer der Spotmarkt-Modus mit Anbieter CKW (15 Minuten) verwendet wird: Loxone liefert dort einen festen CKW-Preis (vermutlich Netz + ClassicStrom, exkl. MwSt, ohne Konzession). Mit der Preisberechnung <code>I1*I2+I3</code> und diesen Werten wird daraus dein Endpreis mit Stromprodukt, Konzessionsabgabe, Zusatzkosten und MwSt. Details im Reiter <b>Loxone</b>.</p>

	<h3 class="ckw-section">MQTT</h3>
	<label><input type="checkbox" name="mqtt_enabled" value="1" <?= $ckwcfg['mqtt_enabled'] ? 'checked' : '' ?>> Werte per MQTT an den LoxBerry-Broker senden</label>
	<div class="ui-field-contain">
		<label for="mqtt_topic">Basis-Topic</label>
		<input type="text" name="mqtt_topic" id="mqtt_topic" value="<?= h($ckwcfg['mqtt_topic']) ?>">
	</div>
	<p class="ckw-hint">Das Plugin trägt <code><?= h($ckwcfg['mqtt_topic']) ?>/+</code> automatisch beim MQTT-Gateway ein. In Loxone heissen die virtuellen Eingänge dann z. B. <code><?= h(str_replace('/', '_', $ckwcfg['mqtt_topic'])) ?>_total_now</code>.</p>

	<h3 class="ckw-section">UDP</h3>
	<label><input type="checkbox" name="udp_enabled" value="1" <?= $ckwcfg['udp_enabled'] ? 'checked' : '' ?>> Werte per UDP direkt an den Miniserver senden</label>
	<?= LBWeb::mslist_select_html(array('LABEL' => 'Miniserver', 'SELECTED' => $ckwcfg['udp_msnr'], 'DATA_MINI' => 0)) ?>
	<div class="ui-field-contain">
		<label for="udp_port">UDP-Port</label>
		<input type="number" min="1" max="65535" name="udp_port" id="udp_port" value="<?= h($ckwcfg['udp_port']) ?>">
	</div>
	<div class="ui-field-contain">
		<label for="udp_prefix">Präfix (optional)</label>
		<input type="text" name="udp_prefix" id="udp_prefix" value="<?= h($ckwcfg['udp_prefix']) ?>">
	</div>
	<p class="ckw-hint">Normalerweise leer lassen. Ein Präfix wird nur vorne an jedes UDP-Paket gestellt (z. B. zur Unterscheidung im UDP-Monitor) und ist für die Befehlserkennung <code>schlüssel=\v</code> nicht nötig. Die Loxone-Vorlage funktioniert mit und ohne Präfix.</p>

	<button type="submit" data-icon="check">Speichern</button>
</form>

<?php elseif ($page === 'prices'): ?>
<!-- =================================================================== -->
<!-- PREISE                                                               -->
<!-- =================================================================== -->
<?php if (!$state): ?>
	<p>Noch keine Daten vorhanden.</p>
<?php else:
	$unit = $state['unit'];
	$fmt = function ($v) use ($state) {
		return $v === null ? '-' : number_format($v, $state['unit'] === 'Rp./kWh' ? 2 : 4, '.', "'");
	};
?>
	<h3 class="ckw-section">Aktuelle Viertelstunde</h3>
	<p class="ckw-hint">Tarif: <?= h($CKW_TARIFF_NAMES[$state['tariff']] ?? $state['tariff']) ?> &middot; Produkt: <?= h($products[$state['product']] ?? $state['product']) ?> &middot; Energiepreis-Quelle: <?= h($state['texts']['price_source']) ?> &middot; <?= $state['vat'] ? 'inkl. ' . h($state['vat']) . ' % MwSt' : 'exkl. MwSt' ?></p>
	<table class="ckw-table">
		<tr><th>Komponente</th><th style="text-align:right"><?= h($unit) ?></th></tr>
		<?php foreach ($CKW_COMPONENTS as $k => $n): ?>
			<tr><td><code><?= h($k) ?></code> <?= h($n) ?></td><td class="num"><?= h($fmt($state['now'][$k])) ?></td></tr>
		<?php endforeach; ?>
	</table>

	<h3 class="ckw-section">Nächste 24 Stunden (Total)</h3>
	<?php
	$rel = $state['series']['total']['rel'];
	$known = array_filter($rel, function ($v) { return $v !== null; });
	$maxv = $known ? max($known) : 1;
	$cheap = $state['cheap'];
	?>
	<?php $fx = !empty($state['fixed']) ? $state['fixed'] : null; ?>
	<?php if ($fx): ?>
		<p><b>Vergleich mit dem Fixtarif</b> (fixer CKW-Netztarif, gleiches Stromprodukt, Abgaben und MwSt): <?= h($fmt($fx['total'])) ?> <?= h($unit) ?>
		<?php if ($fx['diff'] !== null): ?> &middot; aktuell <b><?= h($fmt(abs($fx['diff']))) ?> <?= $fx['diff'] < 0 ? 'günstiger' : 'teurer' ?></b><?php endif; ?>
		&middot; <?= h($fx['cheaper_hours']) ?> der nächsten 24 Stunden günstiger <span class="ckw-hint">(Leistungstarif und Messentgelt nicht berücksichtigt)</span></p>
	<?php endif; ?>
	<p class="ckw-hint">Stunden mit Daten: <?= h($state['hours_avail']) ?>/24<?php if (!empty($state['ref'])): ?> &middot; CKW-Standard-Durchschnittspreis: <?= h($fmt($state['ref']['total'])) ?> (Netznutzung <?= h($fmt($state['ref']['gridusage'])) ?>)<?php endif; ?><?php if ($cheap): ?> &middot; günstigstes <?= h($ckwcfg['cheap_hours']) ?>-h-Fenster ab <?= h(sprintf('%02d:00', $cheap['start_clock'])) ?> Uhr (Ø <?= h($fmt($cheap['avg'])) ?>)<?php endif; ?></p>
	<table class="ckw-table">
		<tr><th>+h</th><th>Uhrzeit</th><th style="text-align:right"><?= h($unit) ?></th><th style="width:45%"></th><th></th></tr>
		<?php foreach ($rel as $i => $v):
			$isCheap = $cheap && $i >= $cheap['start_off'] && $i < $cheap['start_off'] + $ckwcfg['cheap_hours'];
			$fillv = isset($state['fill']['total']['rel'][$i]) ? $state['fill']['total']['rel'][$i] : null;
			$w = $v === null ? ($fillv !== null ? max(2, min(100, round($fillv / $maxv * 100))) : 100) : max(2, round($v / $maxv * 100));
		?>
			<tr>
				<td>+<?= $i ?></td>
				<td><?= sprintf('%02d:00', $state['hourclock'][$i]) ?></td>
				<td class="num"><?= h($v === null ? ($fillv !== null ? 'Ø CKW ' . $fmt($fillv) : 'keine Daten') : $fmt($v)) ?></td>
				<td><div class="ckw-bar<?= $v === null ? ' filled' : ($isCheap ? ' cheap' : '') ?>" style="width:<?= $w ?>%"></div></td>
				<td class="ckw-hint"><?= ($fx && $v !== null) ? ($v < $fx['total'] ? 'unter Fixtarif' : '') : '' ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php endif; ?>

<?php elseif ($page === 'stats'): ?>
<!-- =================================================================== -->
<!-- STATISTIK                                                            -->
<!-- =================================================================== -->
<?php
if ($ckwcfg['meter_source'] === 'off') {
	$statView = 'setup';
}
$pf = $ckwcfg['unit'] === 'rp' ? 100 : 1;
$punit = $ckwcfg['unit'] === 'rp' ? 'Rp./kWh' : 'CHF/kWh';
$pdec = $ckwcfg['unit'] === 'rp' ? 2 : 4;
$chf = function ($v) { return $v === null ? '-' : number_format($v, 2, '.', "'"); };
$kwh = function ($v) { return $v === null ? '-' : number_format($v, 2, '.', "'"); };
$pr = function ($v) use ($pf, $pdec) { return $v === null ? '-' : number_format($v * $pf, $pdec, '.', "'"); };
$monthNames = array(1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember');
$dayNames = array('So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa');
$sub = array('day' => 'Tag', 'month' => 'Monat', 'year' => 'Jahr', 'setup' => 'Einrichtung');
?>
<div class="ckw-subnav" style="margin-top:12px">
	<?php foreach ($sub as $v => $label): ?>
		<a data-role="button" data-inline="true" data-mini="true" data-ajax="false" <?= $statView === $v ? 'data-theme="b"' : '' ?> href="index.php?page=stats&amp;view=<?= $v ?>"><?= h($label) ?></a>
	<?php endforeach; ?>
</div>

<?php if ($statView === 'setup'): ?>
	<?php $si = ($state && isset($state['run']['stats'])) ? $state['run']['stats'] : null; ?>
	<h3 class="ckw-section">Zählerstand für die Verbrauchsstatistik</h3>
	<?php if ($ckwcfg['meter_source'] === 'off'): ?>
		<div class="ckw-msg ckw-warn">Die Statistik ist noch nicht eingerichtet. Wähle unten, woher das Plugin den Zählerstand Bezug (kWh) deines Netzzählers bekommt.</div>
	<?php elseif ($si): ?>
		<p><span class="ckw-status" style="background:<?= $si['meter_ok'] ? '#3cb043' : '#d9534f' ?>"></span>
		<?= $si['meter_ok'] ? 'Letzter Lauf: Zählerstand ' . h(number_format($si['meter'], 3, '.', "'")) . ' kWh gelesen.' : 'Letzter Lauf: ' . h($si['error']) ?></p>
	<?php endif; ?>
	<p class="ckw-hint">Das Plugin liest bei jedem Lauf (alle 15 Minuten) den Zählerstand. Die Differenz zum letzten Stand ist der Verbrauch der Viertelstunde und wird mit dem Preis genau dieser Viertelstunde bewertet (Total wie unter Einstellungen, inkl. MwSt falls aktiviert).</p>

	<form method="post" action="index.php?page=stats&amp;view=setup" data-ajax="false">
		<input type="hidden" name="action" id="stats_action" value="stats_save">
		<div class="ui-field-contain">
			<label for="meter_source">Quelle</label>
			<select name="meter_source" id="meter_source">
				<?php foreach (array('off' => 'aus (keine Statistik)', 'miniserver' => 'Miniserver abfragen', 'mqtt' => 'MQTT-Topic') as $k => $v): ?>
					<option value="<?= $k ?>" <?= $ckwcfg['meter_source'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<h4>Miniserver</h4>
		<?= LBWeb::mslist_select_html(array('FORMID' => 'meter_msnr', 'LABEL' => 'Miniserver', 'SELECTED' => $ckwcfg['meter_msnr'], 'DATA_MINI' => 0)) ?>
		<div class="ui-field-contain">
			<label for="meter_name">Name des Werts</label>
			<input type="text" name="meter_name" id="meter_name" value="<?= h($ckwcfg['meter_name']) ?>" placeholder="z. B. Netzzähler">
		</div>
		<div class="ui-field-contain">
			<label for="meter_output">Ausgang des Bausteins</label>
			<input type="text" name="meter_output" id="meter_output" value="<?= h($ckwcfg['meter_output']) ?>" placeholder="Mrc">
		</div>
		<p class="ckw-hint">Name des Zähler-Bausteins wie in Loxone Config, dazu der Ausgang mit dem Zählerstand Bezug (beim Zähler-Baustein <b>Mrc</b>, nicht Pf = aktuelle Leistung). Alternativ einen <b>virtuellen Eingang</b> oder <b>Merker</b> mit eindeutigem Namen an Mrc hängen – dann spielt der Ausgang keine Rolle. Der LoxBerry-Benutzer des Miniservers braucht Leserechte. Der Wert darf eine Einheit enthalten («12345.6kWh»). „Zählerstand jetzt lesen“ zeigt bei einem falschen Ausgang alle vorhandenen an.</p>

		<h4>MQTT</h4>
		<div class="ui-field-contain">
			<label for="meter_topic">Topic</label>
			<input type="text" name="meter_topic" id="meter_topic" value="<?= h($ckwcfg['meter_topic']) ?>" placeholder="z. B. loxone/zaehler_bezug">
		</div>
		<p class="ckw-hint">Der Zählerstand muss <b>retained</b> auf dem LoxBerry-Broker liegen, z. B. von Loxone über das MQTT-Gateway (virtueller UDP-/HTTP-Ausgang mit Präfix <code>retain</code>) oder direkt aus einem Smartmeter-Adapter.</p>

		<h4>Allgemein</h4>
		<div class="ui-field-contain">
			<label for="meter_unit">Einheit des Zählerstands</label>
			<select name="meter_unit" id="meter_unit">
				<option value="kwh" <?= $ckwcfg['meter_unit'] === 'kwh' ? 'selected' : '' ?>>kWh</option>
				<option value="wh" <?= $ckwcfg['meter_unit'] === 'wh' ? 'selected' : '' ?>>Wh</option>
			</select>
		</div>
		<div class="ui-field-contain">
			<label for="meter_max_kwh">Plausibilitätsgrenze (kWh pro Viertelstunde)</label>
			<input type="number" step="any" min="0.1" max="10000" name="meter_max_kwh" id="meter_max_kwh" value="<?= h($ckwcfg['meter_max_kwh']) ?>">
		</div>
		<p class="ckw-hint">Grössere Sprünge (z. B. falscher Wert nach einem Zählertausch) werden verworfen. 50 kWh pro Viertelstunde entspricht 200 kW Dauerleistung.</p>

		<button type="submit" data-inline="true" data-icon="search" onclick="document.getElementById('stats_action').value='meter_test'">Zählerstand jetzt lesen</button>
		<button type="submit" data-inline="true" data-icon="check" onclick="document.getElementById('stats_action').value='stats_save'">Speichern</button>
	</form>
	<h4>Statistik zurücksetzen</h4>
	<form method="post" action="index.php?page=stats&amp;view=setup" data-ajax="false" onsubmit="return confirm('Alle erfassten Verbrauchsdaten löschen?');">
		<input type="hidden" name="action" value="stats_reset">
		<button type="submit" data-inline="true" data-mini="true" data-icon="delete">Verbrauchsdaten löschen</button>
	</form>
	<p class="ckw-hint">Löscht alle erfassten Zählerstände und Verbrauchswerte, z. B. nach einer falschen Einrichtung. Die gespeicherten Preise bleiben erhalten.</p>
	<p class="ckw-hint">Speicher: Die Viertelstundenwerte sammeln sich in der RAM-Disk und werden einmal pro Stunde in <code>data/statistics.sqlite</code> geschrieben (ca. 3 MB pro Jahr, schont die SD-Karte). Massgebend für die Abrechnung bleibt die Rechnung der CKW.</p>

<?php else: ?>
<?php
	try {
		list($pa, $pz) = ckw_stats_period($statView, $statRef->getTimestamp());
		$slots = ckw_stats_slots($pa, $pz);
		$tiers = ckw_stats_tiers($slots);
		$rows = array();
		$chart = array('labels' => array(), 'kwh' => array(), 'price' => array(), 'fixed' => array());
		if ($statView === 'day') {
			$sum = ckw_stats_sum($slots);
			foreach ($slots as $ts => $u) {
				$chart['labels'][] = ckw_local_time($ts, 'H:i');
				$chart['kwh'][] = $u[0] !== null ? round($u[0], 4) : null;
				$chart['price'][] = $u[4] !== null ? round($u[4] * $pf, 5) : null;
				$chart['fixed'][] = $u[5] !== null ? round($u[5] * $pf, 5) : null;
				if ($u[0] !== null) {
					$rows[] = array(ckw_local_time($ts, 'H:i') . ($u[3] ? ' *' : ''), $u[0], $u[4], $u[1], $u[2] !== null && $u[1] !== null ? $u[2] - $u[1] : null);
				}
			}
			$prev = $statRef->modify('-1 day')->format('Y-m-d');
			$next = $statRef->modify('+1 day')->format('Y-m-d');
			$title = $dayNames[(int)$statRef->format('w')] . ', ' . $statRef->format('d.m.Y');
			$navParam = 'd';
		} else {
			$b = ckw_stats_buffer_load();
			$sum = ckw_stats_sum_range($pa, $pz, $b);
			$step = $statView === 'month' ? '+1 day' : '+1 month';
			$d = (new DateTimeImmutable('@' . $pa))->setTimezone($tzCh);
			while ($d->getTimestamp() < $pz) {
				$e = $d->modify($step);
				$s = ckw_stats_sum_range($d->getTimestamp(), $e->getTimestamp(), $b);
				$label = $statView === 'month' ? $dayNames[(int)$d->format('w')] . ' ' . $d->format('d.m.') : $monthNames[(int)$d->format('n')];
				$chart['labels'][] = $label;
				$chart['kwh'][] = round($s['kwh'], 3);
				$chart['price'][] = $s['avg_price'] !== null ? round($s['avg_price'] * $pf, 5) : null;
				$chart['fixed'][] = $s['kwh_fixed'] > 0 ? round($s['cost_fixed'] / $s['kwh_fixed'] * $pf, 5) : null;
				if ($s['kwh'] > 0) {
					$link = $statView === 'month' ? 'view=day&amp;d=' . $d->format('Y-m-d') : 'view=month&amp;m=' . $d->format('Y-m');
					$rows[] = array('<a data-ajax="false" href="index.php?page=stats&amp;' . $link . '">' . h($label) . '</a>' . ($s['estimated'] ? ' *' : ''), $s['kwh'], $s['avg_price'], $s['cost'], $s['saving']);
				}
				$d = $e;
			}
			if ($statView === 'month') {
				$prev = $statRef->modify('first day of previous month')->format('Y-m');
				$next = $statRef->modify('first day of next month')->format('Y-m');
				$title = $monthNames[(int)$statRef->format('n')] . ' ' . $statRef->format('Y');
				$navParam = 'm';
			} else {
				$prev = (string)((int)$statRef->format('Y') - 1);
				$next = (string)((int)$statRef->format('Y') + 1);
				$title = $statRef->format('Y');
				$navParam = 'y';
			}
		}
		$statErr = null;
	} catch (Exception $e) {
		$statErr = $e->getMessage();
	}
?>
	<?php if ($statErr !== null): ?>
		<div class="ckw-msg ckw-err">Statistik nicht lesbar: <?= h($statErr) ?></div>
	<?php else: ?>
	<h3 class="ckw-section">
		<a data-role="button" data-inline="true" data-mini="true" data-icon="arrow-l" data-iconpos="notext" data-ajax="false" href="index.php?page=stats&amp;view=<?= $statView ?>&amp;<?= $navParam ?>=<?= h($prev) ?>">zurück</a>
		<?= h($title) ?>
		<a data-role="button" data-inline="true" data-mini="true" data-icon="arrow-r" data-iconpos="notext" data-ajax="false" href="index.php?page=stats&amp;view=<?= $statView ?>&amp;<?= $navParam ?>=<?= h($next) ?>">weiter</a>
	</h3>
	<?php if ($statView === 'day'): ?>
		<form method="get" action="index.php" data-ajax="false" style="max-width:260px">
			<input type="hidden" name="page" value="stats"><input type="hidden" name="view" value="day">
			<input type="date" name="d" value="<?= h($statRef->format('Y-m-d')) ?>" data-mini="true" onchange="this.form.submit()">
		</form>
	<?php endif; ?>

	<div class="ckw-tiles">
		<div class="ckw-tile"><span>Verbrauch</span><b><?= h($kwh($sum['kwh'])) ?> kWh</b></div>
		<div class="ckw-tile"><span>Kosten dynamisch</span><b><?= h($chf($sum['cost'])) ?> CHF</b></div>
		<div class="ckw-tile"><span>Ø bezahlter Preis</span><b><?= h($pr($sum['avg_price'])) ?></b><span><?= h($punit) ?></span></div>
		<div class="ckw-tile"><span>Kosten mit Fixtarif</span><b><?= h($sum['kwh_fixed'] > 0 ? $chf($sum['cost_fixed']) . ' CHF' : '-') ?></b></div>
		<div class="ckw-tile"><span>Ersparnis dynamisch</span><b class="<?= $sum['saving'] !== null ? ($sum['saving'] >= 0 ? 'ckw-pos' : 'ckw-neg') : '' ?>"><?= h($sum['saving'] !== null ? $chf($sum['saving']) . ' CHF' : '-') ?></b></div>
	</div>
	<?php if ($sum['estimated']): ?>
		<p class="ckw-hint">* <?= h($sum['estimated']) ?> Viertelstunde(n) geschätzt: Nach einem Unterbruch wurde der Verbrauch gleichmässig auf die fehlenden Viertelstunden verteilt.</p>
	<?php endif; ?>

	<?php if (!$sum['kwh'] && !array_filter($chart['price'], function ($x) { return $x !== null; })): ?>
		<div class="ckw-msg ckw-warn">Für diesen Zeitraum sind keine Daten vorhanden.</div>
	<?php else: ?>
		<div class="ckw-chart"><canvas id="ckwStatChart"></canvas></div>
		<p class="ckw-hint"><?= $statView === 'day'
			? 'Balken: Verbrauch pro Viertelstunde (kWh). Linie: dynamischer Preis, gestrichelt: Fixtarif (' . h($punit) . ').'
			: 'Balken: Verbrauch (kWh). Linie: bezahlter Durchschnittspreis, gestrichelt: Fixtarif (' . h($punit) . ').' ?></p>
		<script src="js/chart.umd.min.js"></script>
		<script>
		(function () {
			var d = <?= json_encode($chart) ?>;
			var day = <?= $statView === 'day' ? 'true' : 'false' ?>;
			new Chart(document.getElementById('ckwStatChart'), {
				data: {
					labels: d.labels,
					datasets: [
						{ type: 'line', label: day ? 'Preis dynamisch' : 'Ø Preis', data: d.price, yAxisID: 'p', borderColor: '#1f6fb2', backgroundColor: '#1f6fb2', stepped: day, pointRadius: day ? 0 : 3, borderWidth: 2, spanGaps: false },
						{ type: 'line', label: 'Fixtarif', data: d.fixed, yAxisID: 'p', borderColor: '#888', borderDash: [6, 4], pointRadius: 0, borderWidth: 1.5, stepped: day },
						{ type: 'bar', label: 'Verbrauch kWh', data: d.kwh, yAxisID: 'e', backgroundColor: 'rgba(109,187,99,0.75)' }
					]
				},
				options: {
					responsive: true, maintainAspectRatio: false, animation: false, interaction: { mode: 'index', intersect: false },
					scales: {
						x: { ticks: { autoSkip: true, maxTicksLimit: day ? 24 : 31 } },
						e: { position: 'left', beginAtZero: true, title: { display: true, text: 'kWh' } },
						p: { position: 'right', grace: '10%', grid: { drawOnChartArea: false }, title: { display: true, text: <?= json_encode($punit) ?> } }
					}
				}
			});
		})();
		</script>
	<?php endif; ?>

	<h3 class="ckw-section">Verbrauch nach Preisstufe</h3>
	<?php $tt = $tiers['cheap']['kwh'] + $tiers['mid']['kwh'] + $tiers['expensive']['kwh']; ?>
	<table class="ckw-table" style="max-width:700px">
		<tr><th>Preisstufe</th><th style="text-align:right">kWh</th><th style="text-align:right">Anteil</th><th style="text-align:right">Kosten CHF</th><th style="text-align:right">Ø <?= h($punit) ?></th></tr>
		<?php foreach (array('cheap' => 'günstigstes Drittel des Tages', 'mid' => 'mittleres Drittel', 'expensive' => 'teuerstes Drittel', 'below_fixed' => 'unter dem Fixtarif', 'above_fixed' => 'über dem Fixtarif') as $k => $label): $t = $tiers[$k]; ?>
			<tr<?= $k === 'below_fixed' ? ' style="border-top:2px solid #999"' : '' ?>>
				<td><?= h($label) ?></td>
				<td class="num"><?= h($kwh($t['kwh'])) ?></td>
				<td class="num"><?= $tt > 0 ? h(number_format($t['kwh'] / $tt * 100, 0)) . ' %' : '-' ?></td>
				<td class="num"><?= h($chf($t['cost'])) ?></td>
				<td class="num"><?= h($t['kwh'] > 0 ? $pr($t['cost'] / $t['kwh']) : '-') ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
	<p class="ckw-hint">Die Drittel werden für jeden Tag aus seinen eigenen Viertelstundenpreisen gebildet. Je mehr Verbrauch im günstigen Drittel, desto besser nutzt du den dynamischen Tarif.</p>

	<h3 class="ckw-section"><?= $statView === 'day' ? 'Viertelstunden' : ($statView === 'month' ? 'Tage' : 'Monate') ?></h3>
	<?php if ($rows): ?>
	<div data-role="collapsible" data-collapsed="<?= $statView === 'day' ? 'true' : 'false' ?>" data-mini="true">
		<h4>Tabelle (<?= count($rows) ?> Zeilen)</h4>
		<table class="ckw-table">
			<tr><th><?= $statView === 'day' ? 'Zeit' : 'Datum' ?></th><th style="text-align:right">kWh</th><th style="text-align:right"><?= $statView === 'day' ? 'Preis' : 'Ø Preis' ?> <?= h($punit) ?></th><th style="text-align:right">Kosten CHF</th><th style="text-align:right">Ersparnis CHF</th></tr>
			<?php foreach ($rows as $r): ?>
				<tr><td><?= $statView === 'day' ? h($r[0]) : $r[0] ?></td><td class="num"><?= h(number_format($r[1], 3, '.', "'")) ?></td><td class="num"><?= h($pr($r[2])) ?></td><td class="num"><?= h($r[3] === null ? '-' : number_format($r[3], 3, '.', "'")) ?></td><td class="num"><?= h($r[4] === null ? '-' : number_format($r[4], 3, '.', "'")) ?></td></tr>
			<?php endforeach; ?>
		</table>
	</div>
	<?php else: ?>
		<p class="ckw-hint">Noch kein Verbrauch erfasst.</p>
	<?php endif; ?>
	<p><a data-role="button" data-inline="true" data-mini="true" data-icon="arrow-d" data-ajax="false" href="index.php?page=stats&amp;view=<?= $statView ?>&amp;<?= $navParam ?>=<?= h($statRef->format($statView === 'day' ? 'Y-m-d' : ($statView === 'month' ? 'Y-m' : 'Y'))) ?>&amp;csv=1">CSV-Export (Viertelstunden)</a></p>
	<?php endif; ?>
<?php endif; ?>

<?php elseif ($page === 'loxone'): ?>
<!-- =================================================================== -->
<!-- LOXONE                                                               -->
<!-- =================================================================== -->
<?php $viBase = str_replace('/', '_', $ckwcfg['mqtt_topic']); ?>
	<h3 class="ckw-section">Spotpreis-Optimierer</h3>
	<ol>
		<li>Im Baustein <b>Spotpreis-Optimierer</b> die Betriebsart <b>Relativ</b> wählen.</li>
		<li>Die Eingänge <b>+0 … +23</b> mit <code>total_rel00 … total_rel23</code> verbinden (bzw. der gewünschten Komponente).</li>
		<li>Das Plugin aktualisiert alle 15 Minuten - die Relativ-Werte sind damit immer in der laufenden Stunde gültig.</li>
		<?php $fillText = array(
			'ckwavg' => 'mit dem CKW-Durchschnittspreis gefüllt - der Optimierer plant dort mit einem realistischen Durchschnitt',
			'max'    => 'mit dem Höchstpreis gefüllt, damit der Optimierer dort nicht einschaltet',
			'last'   => 'mit dem letzten bekannten Preis gefüllt',
			'zero'   => 'mit 0 gefüllt - Achtung, der Optimierer hält diese Stunden für die günstigsten',
		); ?>
		<li>Stunden ohne Daten (morgen vor ca. 12 Uhr) werden <?= h($fillText[$ckwcfg['fill_mode']]) ?> (Einstellung „Fehlende Stunden füllen mit“). Wie viele Stunden echt sind, zeigt <code>hours_avail</code>.</li>
		<li>Betriebsart <b>Absolut</b>: Eingänge 00:00 … 23:00 mit <code>total_abs00 … total_abs23</code> verbinden (Option „Absolut“ in den Einstellungen aktivieren).</li>
	</ol>

	<?php if ($ckwcfg['formula_enabled']): $fv = $state && isset($state['values']['formula_factor']) ? $state['values'] : null; ?>
	<h3 class="ckw-section">Spotmarkt-Modus „CKW“ mit Korrekturwerten</h3>
	<ol>
		<li>Im Spotpreis-Optimierer den Spotmarkt-Modus mit Anbieter <b>CKW</b> verwenden (15-Minuten-Auflösung möglich).</li>
		<li>In den Eigenschaften die <b>Preisberechnung</b> auf <code>I1*I2+I3</code> setzen.</li>
		<li>Parameter <b>I2</b> mit <code>formula_factor</code> und <b>I3</b> mit <code>formula_offset</code> verbinden – oder die Werte einmal von Hand eintragen. Sie ändern sich nur beim Jahreswechsel oder wenn du hier Produkt, Gemeinde, MwSt, Einheit oder Zusatzkosten änderst.</li>
		<li>Kontrolle: Der Ausgang <b>Cv</b> des Optimierers (ohne Formel) sollte <code>formula_i1</code> entsprechen, mit Formel <code>total_now</code>. Weicht Cv ab, liefert Loxone einen anderen CKW-Preis als angenommen – bitte melden.</li>
		<li>Einheit beachten: Die Werte hier sind in <?= h($ckwcfg['unit'] === 'rp' ? 'Rp./kWh' : 'CHF/kWh') ?>. Rechnet Loxone in der anderen Einheit, hier die Einheit umstellen.</li>
	</ol>
	<?php if ($fv): ?>
		<table class="ckw-table" style="max-width:600px">
			<tr><td>I2 = <code>formula_factor</code></td><td class="num"><?= h($fv['formula_factor']) ?></td></tr>
			<tr><td>I3 = <code>formula_offset</code></td><td class="num"><?= h($fv['formula_offset']) ?></td></tr>
			<tr><td>erwartetes I1 jetzt (<code>formula_i1</code>)</td><td class="num"><?= h($fv['formula_i1']) ?></td></tr>
			<tr><td>I1*I2+I3 (<code>formula_check</code>) / <code>total_now</code></td><td class="num"><?= h($fv['formula_check']) ?> / <?= h($fv['total_now']) ?></td></tr>
		</table>
	<?php else: ?>
		<p class="ckw-hint">Werte erscheinen nach dem nächsten Abruf.</p>
	<?php endif; ?>
	<?php endif; ?>

	<h3 class="ckw-section">Online-Überwachung</h3>
	<p><code>online</code> = 1, wenn der letzte Abruf bei CKW geklappt hat und ein Preis für die aktuelle Viertelstunde vorliegt.
	Weil MQTT-Werte retained sind, zusätzlich <code>last_update_lox</code> überwachen: Ist <i>(Loxone-Zeit − last_update_lox) &gt; 1800 s</i>, läuft der LoxBerry oder das Plugin nicht mehr.</p>

	<h3 class="ckw-section">Werte</h3>
	<?php if ($ckwcfg['udp_enabled']): ?>
		<p><a data-role="button" data-inline="true" data-mini="true" data-icon="arrow-d" data-ajax="false" href="index.php?download=udp">Loxone-Vorlage (Virtueller UDP-Eingang) herunterladen</a>
		<span class="ckw-hint">In Loxone Config: Virtuelle Eingänge → Vorlage importieren. Absender-Adresse ggf. auf die LoxBerry-IP setzen.</span></p>
	<?php endif; ?>
	<?php if (!$state): ?>
		<p>Noch keine Daten - bitte zuerst abrufen.</p>
	<?php else: ?>
		<table class="ckw-table">
			<tr><th>Schlüssel</th><th>Beschreibung</th><?php if ($ckwcfg['mqtt_enabled']): ?><th>MQTT-Topic</th><th>Loxone VI (MQTT-Gateway)</th><?php endif; ?><?php if ($ckwcfg['udp_enabled']): ?><th>UDP-Befehlserkennung</th><?php endif; ?><th style="text-align:right">Wert</th></tr>
			<?php foreach ($state['values'] as $k => $v): ?>
				<tr>
					<td><code><?= h($k) ?></code></td>
					<td><?= h(ckw_key_description($k, $state['unit'])) ?></td>
					<?php if ($ckwcfg['mqtt_enabled']): ?><td><code><?= h($ckwcfg['mqtt_topic'] . '/' . $k) ?></code></td><td><code><?= h($viBase . '_' . $k) ?></code></td><?php endif; ?>
					<?php if ($ckwcfg['udp_enabled']): ?><td><code><?= h($k) ?>=\v</code></td><?php endif; ?>
					<td class="num"><?= h($v) ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ($ckwcfg['mqtt_enabled']): foreach ($state['texts'] as $k => $v): ?>
				<tr>
					<td><code><?= h($k) ?></code> <span class="ckw-hint">(Text)</span></td>
					<td><?= h(ckw_key_description($k, $state['unit'])) ?></td>
					<td><code><?= h($ckwcfg['mqtt_topic'] . '/' . $k) ?></code></td><td><code><?= h($viBase . '_' . $k) ?></code></td>
					<?php if ($ckwcfg['udp_enabled']): ?><td class="ckw-hint">nur MQTT</td><?php endif; ?>
					<td class="num"><?= h($v) ?></td>
				</tr>
			<?php endforeach; endif; ?>
		</table>
		<p class="ckw-hint">Die Werte entsprechen dem letzten Lauf. Nach Änderungen der Einstellungen ändert sich die Liste.
		<?php if ($ckwcfg['mqtt_enabled']): ?>Alle Daten als JSON: <code><?= h($ckwcfg['mqtt_topic']) ?>/data/json</code> (wird nicht ans Gateway weitergereicht).<?php endif; ?></p>
	<?php endif; ?>
<?php endif; ?>

<?php
LBWeb::lbfooter();
