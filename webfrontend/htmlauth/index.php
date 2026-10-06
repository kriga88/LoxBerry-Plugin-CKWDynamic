<?php
/**
 * CKW Dynamischer Tarif - Weboberflaeche (Einstellungen, Preisuebersicht, Loxone-Einbindung)
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
// Formularaktionen
// ---------------------------------------------------------------------------
$page = isset($_GET['page']) ? $_GET['page'] : 'settings';
$messages = array();
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
	if ($_POST['action'] === 'save') {
		$in = $_POST;
		foreach (array('vat_enabled', 'series_rel', 'series_abs', 'series_tmr', 'mqtt_enabled', 'udp_enabled', 'tariffs_remote') as $cb) {
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
$navbar[3]['Name'] = 'Loxone';
$navbar[3]['URL'] = 'index.php?page=loxone';
$navbar[4]['Name'] = 'Log';
$navbar[4]['URL'] = LBWeb::loglist_url(array('NAME' => 'Abruf'));
$navbar[4]['target'] = '_blank';
$active = array('settings' => 1, 'prices' => 2, 'loxone' => 3);
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
	<p class="ckw-hint">Stunden mit Daten: <?= h($state['hours_avail']) ?>/24<?php if (!empty($state['ref'])): ?> &middot; CKW-Standard-Durchschnittspreis: <?= h($fmt($state['ref']['total'])) ?> (Netznutzung <?= h($fmt($state['ref']['gridusage'])) ?>)<?php endif; ?><?php if ($cheap): ?> &middot; günstigstes <?= h($ckwcfg['cheap_hours']) ?>-h-Fenster ab <?= h(sprintf('%02d:00', $cheap['start_clock'])) ?> Uhr (Ø <?= h($fmt($cheap['avg'])) ?>)<?php endif; ?></p>
	<table class="ckw-table">
		<tr><th>+h</th><th>Uhrzeit</th><th style="text-align:right"><?= h($unit) ?></th><th style="width:50%"></th></tr>
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
			</tr>
		<?php endforeach; ?>
	</table>
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
