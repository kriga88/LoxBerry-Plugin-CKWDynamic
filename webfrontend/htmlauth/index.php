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

// Vorschau einer Schaltregel (JSON, aus dem Regel-Editor)
if (isset($_GET['rules_preview'])) {
	header('Content-Type: application/json; charset=utf-8');
	$rerr = array();
	$rin = isset($_POST['rule']) && is_array($_POST['rule']) ? $_POST['rule'] : array();
	$rin['block'] = isset($rin['block']) ? '1' : '0';
	$rule = ckw_sanitize_rule($rin, $rerr);
	try {
		echo json_encode(ckw_rule_preview_data($rule, $ckwcfg, time()));
	} catch (Throwable $e) {
		echo json_encode(array('error' => 'Vorschau nicht möglich: ' . $e->getMessage()));
	}
	exit;
}

// ---------------------------------------------------------------------------
// Formularaktionen
// ---------------------------------------------------------------------------
$page = isset($_GET['page']) ? $_GET['page'] : 'settings';
$ruleView = isset($_GET['view']) && $_GET['view'] === 'edit' ? 'edit' : 'list';
$ruleIdx = isset($_GET['idx']) && ctype_digit((string)$_GET['idx']) && isset($ckwcfg['rules'][(int)$_GET['idx']]) ? (int)$_GET['idx'] : null;
$ruleEdit = null;
if ($ruleView === 'edit') {
	$tpls = ckw_rule_templates();
	$copyIdx = isset($_GET['copy']) && ctype_digit((string)$_GET['copy']) && isset($ckwcfg['rules'][(int)$_GET['copy']]) ? (int)$_GET['copy'] : null;
	if ($ruleIdx !== null) {
		$ruleEdit = $ckwcfg['rules'][$ruleIdx];
	} elseif ($copyIdx !== null) {
		// Kopie als neue Regel: eigener Name und neuer Kurzname (sonst gleiche Loxone-Schluessel)
		$ruleEdit = $ckwcfg['rules'][$copyIdx];
		$base = function_exists('mb_substr') ? mb_substr($ruleEdit['name'], 0, 32) : substr($ruleEdit['name'], 0, 32);
		$ruleEdit['name'] = $base . ' (Kopie)';
		$ruleEdit['key'] = '';
	} else {
		$tk = isset($_GET['tpl']) && isset($tpls[$_GET['tpl']]) ? $_GET['tpl'] : 'leer';
		$tmpErr = array();
		$ruleEdit = ckw_sanitize_rule($tpls[$tk]['rule'], $tmpErr);
	}
}
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
	} elseif (in_array($_POST['action'], array('rule_save', 'rule_delete', 'rule_toggle'), true)) {
		$rules = $ckwcfg['rules'];
		$i = isset($_POST['idx']) && ctype_digit((string)$_POST['idx']) && isset($rules[(int)$_POST['idx']]) ? (int)$_POST['idx'] : null;
		$done = '';
		if ($_POST['action'] === 'rule_save') {
			$rin = isset($_POST['rule']) && is_array($_POST['rule']) ? $_POST['rule'] : array();
			$rin['block'] = isset($rin['block']) ? '1' : '0';
			if ($i === null) {
				$rules[] = $rin;
			} else {
				$rules[$i] = $rin;
			}
			$done = 'Regel gespeichert.';
		} elseif ($i !== null && $_POST['action'] === 'rule_delete') {
			array_splice($rules, $i, 1);
			$done = 'Regel gelöscht.';
		} elseif ($i !== null) {
			$rules[$i]['enabled'] = !$rules[$i]['enabled'];
			$done = $rules[$i]['enabled'] ? 'Regel aktiviert.' : 'Regel deaktiviert.';
		}
		$new = ckw_sanitize_config(array_merge($ckwcfg, array('rules' => $rules)), $errors);
		if (!$errors && $_POST['action'] === 'rule_save') {
			// feste Preise nur innerhalb des CKW-Preisbands (Total wie eingestellt)
			$saved = $new['rules'][$i === null ? count($new['rules']) - 1 : $i];
			$nowSlot = time() - (time() % 900);
			$ref = ckw_rule_reference($saved, ckw_rules_ui_table($new, time()), $nowSlot, $nowSlot + 900);
			if ($ref['band'] !== null) {
				list($bl, $bh) = $ref['band'];
				$fp = function ($x) use ($new) { return number_format($x, $new['unit'] === 'rp' ? 2 : 4, '.', ''); };
				$u = $new['unit'] === 'rp' ? 'Rp./kWh' : 'CHF/kWh';
				$eps = 1e-6;
				if ($saved['limit_mode'] === 'abs' && ($saved['limit_value'] < $bl - $eps || $saved['limit_value'] > $bh + $eps)) {
					$errors[] = "Fester Preis: zwischen {$fp($bl)} und {$fp($bh)} $u (CKW-Preisband).";
				}
				if ($saved['limit_mode'] === 'fixed' && abs($saved['limit_value']) > $bh - $bl + $eps) {
					$errors[] = "Fixtarif ±: höchstens ±{$fp($bh - $bl)} $u.";
				}
				if ($saved['analog'] === 'linear' && min($saved['lin_p1'], $saved['lin_p2']) < $bl - $eps || $saved['analog'] === 'linear' && max($saved['lin_p1'], $saved['lin_p2']) > $bh + $eps) {
					$errors[] = "Preis A und B: zwischen {$fp($bl)} und {$fp($bh)} $u.";
				}
				if ($saved['analog'] === 'steps' && $saved['steps_mode'] === 'abs') {
					foreach ($saved['steps'] as $k => $st) {
						if ($st['from'] < $bl - $eps || $st['to'] > $bh + $eps) {
							$errors[] = 'Stufe ' . ($k + 1) . ": von/bis zwischen {$fp($bl)} und {$fp($bh)} $u.";
						}
					}
				}
			}
		}
		if (!$errors && $done !== '') {
			if (ckw_save_config($new)) {
				$ckwcfg = $new;
				exec("$fetchCmd > /dev/null 2>&1 &");
				$messages[] = $done . ' Die Ausgänge werden im Hintergrund neu berechnet und gesendet.';
			} else {
				$errors[] = 'Einstellungen konnten nicht gespeichert werden (Schreibrechte?).';
			}
		} elseif ($errors && $_POST['action'] === 'rule_save') {
			// Formular mit den Eingaben erneut zeigen
			$ruleView = 'edit';
			$ruleIdx = $i;
			$tmpErr = array();
			$ruleEdit = ckw_sanitize_rule($rin, $tmpErr);
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
$navbar[4]['Name'] = 'Regeln';
$navbar[4]['URL'] = 'index.php?page=rules';
$navbar[5]['Name'] = 'Loxone';
$navbar[5]['URL'] = 'index.php?page=loxone';
$navbar[6]['Name'] = 'Log';
$navbar[6]['URL'] = LBWeb::loglist_url(array('NAME' => 'Abruf'));
$navbar[6]['target'] = '_blank';
$active = array('settings' => 1, 'prices' => 2, 'stats' => 3, 'rules' => 4, 'loxone' => 5);
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

<?php elseif ($page === 'rules'): ?>
<!-- =================================================================== -->
<!-- SCHALTREGELN                                                         -->
<!-- =================================================================== -->
<?php
$punit = $ckwcfg['unit'] === 'rp' ? 'Rp./kWh' : 'CHF/kWh';
$fmtP = function ($v) use ($ckwcfg) { return $v === null ? '-' : number_format($v, $ckwcfg['unit'] === 'rp' ? 2 : 4, '.', "'"); };
$ruleState = ($state && isset($state['run']['rules']) && is_array($state['run']['rules'])) ? $state['run']['rules'] : array();
global $CKW_RULE_STEP_MODES;
?>
<?php if ($ruleView === 'list'): ?>
	<h3 class="ckw-section">Schaltregeln</h3>
	<p class="ckw-hint">Jede Regel schaltet einen Ausgang <code>rule_&lt;kurzname&gt;_on</code> (0/1) und auf Wunsch einen Analogwert <code>rule_&lt;kurzname&gt;_value</code>, z. B. Leistung in % oder SG-Ready 1–4. Das Plugin plant bei jedem Lauf (alle 15 Minuten) neu mit den aktuellen Preisen. Fehlende Stunden (morgen vor ca. 12 Uhr) rechnet es mit dem CKW-Durchschnittspreis.</p>
	<?php if (!$ckwcfg['rules']): ?>
		<div class="ckw-msg ckw-warn">Noch keine Regeln angelegt. Starte mit einer Vorlage:</div>
	<?php else: ?>
		<table class="ckw-table">
			<tr><th>Regel</th><th>Typ</th><th>Zeitfenster</th><th>Jetzt</th><th>Nächstes Ein</th><th style="text-align:right">Ein-Zeit im Fenster</th><th style="text-align:right">Kosten</th><th></th></tr>
			<?php foreach ($ckwcfg['rules'] as $i => $r): $rs = isset($ruleState[$r['key']]) ? $ruleState[$r['key']] : null; ?>
				<tr<?= $r['enabled'] ? '' : ' style="opacity:0.5"' ?>>
					<td><b><?= h($r['name']) ?></b><br><code>rule_<?= h($r['key']) ?>_on</code></td>
					<td><?= h($CKW_RULE_TYPES[$r['type']]) ?><?= $r['analog'] !== 'none' ? ' + Analogwert' : '' ?></td>
					<td><?= $r['win_from'] === $r['win_to'] ? 'ganzer Tag' : h($r['win_from'] . '–' . $r['win_to']) ?></td>
					<td><?php if (!$r['enabled']): ?>inaktiv<?php elseif ($rs): ?><span class="ckw-status" style="background:<?= $rs['on'] ? '#3cb043' : '#bbb' ?>"></span><?= $rs['on'] ? 'Ein' : 'Aus' ?><?= $rs['value'] !== null ? ' · ' . h(rtrim(rtrim(number_format($rs['value'], 2, '.', ''), '0'), '.')) : '' ?><?php else: ?>-<?php endif; ?></td>
					<td><?= $rs && $rs['next'] ? h(ckw_rule_day_label($rs['next']) . ckw_local_time($rs['next'], ' H:i')) : '-' ?></td>
					<td class="num"><?php if ($rs): $tm = $rs['left_min'] + $rs['done_min']; ?><?= h(sprintf('%d:%02d h', intdiv($tm, 60), $tm % 60)) ?><?= $rs['done_min'] ? '<br><span class="ckw-hint">' . h(sprintf('noch %d:%02d h', intdiv($rs['left_min'], 60), $rs['left_min'] % 60)) . '</span>' : '' ?><?php else: ?>-<?php endif; ?></td>
					<td class="num"><?= $rs && isset($rs['cost_chf']) && $rs['cost_chf'] !== null ? h(number_format($rs['cost_chf'], 2, '.', "'")) . ' CHF' : '-' ?></td>
					<td style="white-space:nowrap">
						<a data-role="button" data-inline="true" data-mini="true" data-icon="edit" data-iconpos="notext" data-ajax="false" href="index.php?page=rules&amp;view=edit&amp;idx=<?= $i ?>">Bearbeiten</a>
						<?php if (count($ckwcfg['rules']) < CKW_RULES_MAX): ?>
							<a data-role="button" data-inline="true" data-mini="true" data-icon="plus" data-iconpos="notext" data-ajax="false" title="Kopieren" href="index.php?page=rules&amp;view=edit&amp;copy=<?= $i ?>">Kopieren</a>
						<?php endif; ?>
						<form method="post" action="index.php?page=rules" data-ajax="false" style="display:inline">
							<input type="hidden" name="action" value="rule_toggle"><input type="hidden" name="idx" value="<?= $i ?>">
							<button type="submit" data-inline="true" data-mini="true" data-icon="<?= $r['enabled'] ? 'minus' : 'plus' ?>" data-iconpos="notext"><?= $r['enabled'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
						</form>
						<form method="post" action="index.php?page=rules" data-ajax="false" style="display:inline" onsubmit="return confirm('Regel löschen?');">
							<input type="hidden" name="action" value="rule_delete"><input type="hidden" name="idx" value="<?= $i ?>">
							<button type="submit" data-inline="true" data-mini="true" data-icon="delete" data-iconpos="notext">Löschen</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
	<?php endif; ?>
	<?php if (count($ckwcfg['rules']) < CKW_RULES_MAX): ?>
		<h4>Neue Regel aus Vorlage</h4>
		<p>
		<?php foreach (ckw_rule_templates() as $k => $t): ?>
			<a data-role="button" data-inline="true" data-mini="true" data-icon="plus" data-ajax="false" href="index.php?page=rules&amp;view=edit&amp;tpl=<?= h($k) ?>"><?= h($t['label']) ?></a>
		<?php endforeach; ?>
		</p>
		<p class="ckw-hint"><b>Auto laden:</b> günstigste 4 h zwischen 18 und 7 Uhr · <b>Waschmaschine:</b> 2 h am Stück unter dem Tagesmittel · <b>Wärmepumpe:</b> mind. 8 h/Tag, höchstens 3 h aus, Blöcke ab 30 min, Leistung 100/70/40 % je nach Preis · <b>SG-Ready:</b> Betriebszustand 1–4 · <b>Sperrzeit:</b> Ein im teuersten Drittel · <b>Boiler:</b> günstigste 3 h</p>
	<?php endif; ?>
	<p class="ckw-hint">In Loxone den Eingang (z. B. <code><?= h(str_replace('/', '_', $ckwcfg['mqtt_topic'])) ?>_rule_boiler_on</code> über MQTT) direkt an das Gerät hängen; eine Handübersteuerung macht man in Loxone. Alle Werte stehen im Reiter Loxone.</p>

<?php else: ?>
<?php
	$r = $ruleEdit;
	$stepsForm = $r['steps'];
	while (count($stepsForm) < CKW_RULE_STEPS_MAX) {
		$stepsForm[] = array('from' => '', 'to' => '', 'out' => '');
	}
	$sel = function ($name, $value, array $opts) {
		$x = '<select name="rule[' . $name . ']" id="r_' . $name . '" data-mini="true">';
		foreach ($opts as $k => $v) {
			$x .= '<option value="' . h($k) . '"' . ((string)$value === (string)$k ? ' selected' : '') . '>' . h($v) . '</option>';
		}
		return $x . '</select>';
	};
	$inp = function ($name, $value, $type = 'text', $extra = '') {
		return '<input type="' . $type . '" name="rule[' . $name . ']" id="r_' . $name . '" value="' . h($value) . '" data-mini="true" ' . $extra . '>';
	};
	$compOpts = array();
	foreach ($CKW_COMPONENTS as $k => $v) {
		$compOpts[$k] = $k;
	}
?>
	<h3 class="ckw-section"><?php $isCopy = $ruleIdx === null && isset($copyIdx) && $copyIdx !== null; ?>
	<?= $ruleIdx !== null ? 'Regel bearbeiten' : ($isCopy ? 'Regel kopieren' : 'Neue Regel') ?></h3>
	<?php if ($isCopy): ?>
		<p class="ckw-hint">Kopie von „<?= h($ckwcfg['rules'][$copyIdx]['name']) ?>“ – erst mit Speichern angelegt. Name und Kurzname anpassen; der Kurzname wird sonst automatisch aus dem Namen gebildet und eindeutig gemacht.</p>
	<?php endif; ?>
	<form method="post" action="index.php?page=rules" data-ajax="false" id="ruleForm">
		<input type="hidden" name="action" value="rule_save">
		<input type="hidden" name="idx" value="<?= $ruleIdx === null ? '' : (int)$ruleIdx ?>">
		<input type="hidden" name="rule[enabled]" value="<?= $r['enabled'] ? '1' : '0' ?>">

		<div class="ui-field-contain"><label for="r_name">Name</label><?= $inp('name', $r['name'], 'text', 'maxlength="40"') ?></div>
		<div class="ui-field-contain"><label for="r_key">Kurzname (Loxone)</label><?= $inp('key', $r['key'], 'text', 'maxlength="20" placeholder="automatisch aus dem Namen"') ?></div>
		<p class="ckw-hint">Ergibt die Schlüssel <code>rule_&lt;kurzname&gt;_on</code> usw. (nur a–z und 0–9). Nach dem Einrichten in Loxone nicht mehr ändern.</p>
		<div class="ui-field-contain"><label for="r_type">Typ</label><?= $sel('type', $r['type'], $CKW_RULE_TYPES) ?></div>
		<p class="ckw-hint rf" data-types="cheapest">Ein in den günstigsten Stunden des Zeitfensters, z. B. Auto laden, Boiler, Waschmaschine (mit „am Stück“).</p>
		<p class="ckw-hint rf" data-types="runtime">Mindest-Ein-Zeit mit möglichst tiefen Kosten, dazu max. Aus-Zeit am Stück und Mindest-Laufblock, z. B. Wärmepumpe.</p>
		<p class="ckw-hint rf" data-types="threshold">Ein, solange der Preis über (z. B. Sperrzeit) bzw. unter (z. B. Billigstrom) einer Grenze liegt.</p>
		<p class="ckw-hint rf" data-types="window">Immer Ein im Zeitfenster – sinnvoll für einen reinen Analogwert, z. B. SG-Ready oder Leistung nach Preis.</p>

		<div class="ui-field-contain"><label for="r_win_from">Zeitfenster von</label><?= $inp('win_from', $r['win_from'], 'time', 'step="900"') ?></div>
		<div class="ui-field-contain"><label for="r_win_to">bis</label><?= $inp('win_to', $r['win_to'], 'time', 'step="900"') ?></div>
		<p class="ckw-hint">Gleiche Zeiten (00:00–00:00) = ganzer Tag. Ist „bis“ früher als „von“, geht das Fenster über Mitternacht (z. B. 18:00–07:00 = bis 7 Uhr fertig). Ausserhalb des Fensters ist der Ausgang aus.</p>

		<div class="rf" data-types="cheapest runtime">
			<div class="ui-field-contain"><label for="r_hours"><span class="rf" data-types="cheapest">Ein-Zeit (Stunden)</span><span class="rf" data-types="runtime">Mindest-Ein-Zeit (Stunden)</span></label><?= $inp('hours', $r['hours'], 'number', 'step="0.25" min="0.25" max="24"') ?></div>
		</div>
		<div class="rf" data-types="cheapest">
			<label><input type="checkbox" name="rule[block]" value="1" <?= $r['block'] ? 'checked' : '' ?>> am Stück (ein zusammenhängender Block, z. B. Waschprogramm)</label>
			<p class="ckw-hint"><b>Aus:</b> die günstigsten Viertelstunden, egal wo sie liegen (z. B. Auto, Boiler). <b>Ein:</b> ein einziger Block mit den tiefsten Gesamtkosten für Geräte, die nicht unterbrochen werden dürfen. Ein begonnener Block läuft immer zu Ende. Mit Obergrenze muss der ganze Block darunter liegen, sonst schaltet die Regel in diesem Fenster nicht ein.</p>
		</div>

		<div class="rf" data-types="cheapest runtime threshold">
			<div class="rf" data-types="threshold">
				<div class="ui-field-contain"><label for="r_direction">Ein, wenn Preis</label><?= $sel('direction', $r['direction'], array('above' => 'über der Grenze (Sperre)', 'below' => 'unter der Grenze')) ?></div>
			</div>
			<div class="ui-field-contain"><label for="r_limit_mode"><span class="rf" data-types="cheapest runtime">Obergrenze</span><span class="rf" data-types="threshold">Grenze</span></label><?= $sel('limit_mode', $r['limit_mode'], $CKW_RULE_LIMIT_MODES) ?></div>
			<div class="ui-field-contain lim-val">
				<label for="r_limit_value">Wert <span class="lim-unit"></span></label>
				<input type="range" id="r_limit_range" data-role="none" style="width:100%;max-width:420px">
				<?= $inp('limit_value', $r['limit_value'], 'number', 'step="any" style="max-width:140px"') ?>
				<b class="lim-info"></b>
			</div>
			<p class="ckw-hint"><b>Fester Preis:</b> wählbar im CKW-Preisband. <b>% Preisband ab Minimum:</b> 0 % = tiefster, 100 % = höchster möglicher Preis laut CKW (Netznutzung 0.1–32 Rp./kWh, umgerechnet auf deinen Preis). <b>Ab Maximum:</b> gleich, von oben gezählt (20 % ab Max = 80 % ab Min). Das Band ist fix und ändert sich nicht mit den Tagespreisen. <b>Anteil der Viertelstunden:</b> z. B. 30 % = Grenze, unter der die günstigsten 30 % der Viertelstunden des Fensters liegen; passt sich jedem Tag an. <b>Fixtarif ±:</b> Abstand zum Fixtarif-Preis (0 = gleich teuer, negativ = günstiger).</p>
			<p class="ckw-hint ref-info"></p>
		</div>

		<div class="rf" data-types="runtime">
			<div class="ui-field-contain"><label for="r_max_off_h">Max. Aus-Zeit am Stück (Stunden, 0 = egal)</label><?= $inp('max_off_h', $r['max_off_h'], 'number', 'step="0.25" min="0" max="24"') ?></div>
			<div class="ui-field-contain"><label for="r_min_block_min">Mindest-Laufblock (Minuten)</label><?= $inp('min_block_min', $r['min_block_min'], 'number', 'step="15" min="0" max="480"') ?></div>
			<div class="ui-field-contain"><label for="r_priority">Vorrang</label><?= $sel('priority', $r['priority'], array('comfort' => 'Komfort: Mindestzeit und max. Aus-Zeit einhalten, notfalls über der Obergrenze', 'price' => 'Preis: nie über der Obergrenze, Mindestzeit notfalls verfehlen')) ?></div>
		</div>
		<div class="rf" data-types="threshold">
			<div class="ui-field-contain"><label for="r_min_dur_min">Mindestdauer Ein/Aus (Minuten)</label><?= $inp('min_dur_min', $r['min_dur_min'], 'number', 'step="15" min="0" max="480"') ?></div>
			<p class="ckw-hint">Verhindert häufiges Umschalten, wenn der Preis um die Grenze pendelt.</p>
		</div>

		<h4>Analogwert (optional)</h4>
		<div class="ui-field-contain"><label for="r_analog">Analogwert</label><?= $sel('analog', $r['analog'], array('none' => 'keiner', 'steps' => 'Stufen nach Preis', 'linear' => 'linear nach Preis')) ?></div>
		<div class="af" data-analog="steps">
			<div class="ui-field-contain"><label for="r_steps_mode">Preis von–bis als</label><?= $sel('steps_mode', $r['steps_mode'], $CKW_RULE_STEP_MODES) ?></div>
			<table class="ckw-table" style="max-width:720px" id="stepTable">
				<tr><th>von</th><th>bis</th><th>Wert</th><th>entspricht</th></tr>
				<?php foreach ($stepsForm as $si => $stp): ?>
					<tr class="step-row">
						<td><input type="number" step="any" class="st-from" name="rule[steps][<?= $si ?>][from]" value="<?= h($stp['from']) ?>" data-role="none" style="width:90px"></td>
						<td><input type="number" step="any" class="st-to" name="rule[steps][<?= $si ?>][to]" value="<?= h($stp['to']) ?>" data-role="none" style="width:90px"></td>
						<td><input type="number" step="any" class="st-out" name="rule[steps][<?= $si ?>][out]" value="<?= h($stp['out']) ?>" data-role="none" style="width:90px"></td>
						<td class="st-info ckw-hint"></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p class="ckw-hint">Es gilt die erste Zeile, in deren Bereich der Preis liegt (ein Preis genau auf der Grenze gehört zur oberen Zeile). Leere Zeilen werden ignoriert. Ausserhalb aller Zeilen gilt „Wert übrige Preise“. Beispiel Wärmepumpe mit „Anteil der Viertelstunden“: 0–25 → 100, 25–60 → 70, übrige 40.</p>
			<p class="ckw-hint step-warn" style="color:#c0392b"></p>
			<div class="ui-field-contain"><label for="r_else_value">Wert übrige Preise</label><?= $inp('else_value', $r['else_value'], 'number', 'step="any"') ?></div>
		</div>
		<div class="af" data-analog="linear">
			<div class="ui-field-contain"><label for="r_lin_p1">Preis A (<?= h($punit) ?>)</label><?= $inp('lin_p1', $r['lin_p1'], 'number', 'step="any" class="band-price"') ?></div>
			<div class="ui-field-contain"><label for="r_lin_v1">ergibt Wert</label><?= $inp('lin_v1', $r['lin_v1'], 'number', 'step="any"') ?></div>
			<div class="ui-field-contain"><label for="r_lin_p2">Preis B (<?= h($punit) ?>)</label><?= $inp('lin_p2', $r['lin_p2'], 'number', 'step="any" class="band-price"') ?></div>
			<div class="ui-field-contain"><label for="r_lin_v2">ergibt Wert</label><?= $inp('lin_v2', $r['lin_v2'], 'number', 'step="any"') ?></div>
			<p class="ckw-hint">Dazwischen wird gleitend umgerechnet, ausserhalb bleibt der Wert bei A bzw. B. Preise A und B im CKW-Preisband <span class="band-text"></span>.</p>
		</div>
		<div class="af" data-analog="steps linear">
			<div class="ui-field-contain"><label for="r_off_value">Wert, wenn die Regel aus ist</label><?= $inp('off_value', $r['off_value'], 'number', 'step="any"') ?></div>
			<div class="ui-field-contain"><label for="r_hold_min">Haltezeit pro Wert (Minuten)</label><?= $inp('hold_min', $r['hold_min'], 'number', 'step="15" min="0" max="480"') ?></div>
			<p class="ckw-hint">Ein neuer Wert gilt erst, wenn der bisherige mindestens so lange anlag (gegen Springen). Beim Ein-/Ausschalten wechselt der Wert sofort.</p>
		</div>

		<h4>Weiteres</h4>
		<div class="ui-field-contain"><label for="r_comp">Preisbasis</label><?= $sel('comp', $r['comp'], $compOpts) ?></div>
		<div class="ui-field-contain"><label for="r_nodata">Ohne aktuelle CKW-Preise</label><?= $sel('nodata', $r['nodata'], array('plan' => 'nach Plan (CKW-Durchschnittspreis)', 'off' => 'Aus', 'on' => 'Ein')) ?></div>
		<p class="ckw-hint">Gilt nur, wenn für die <b>laufende</b> Viertelstunde kein echter CKW-Preis vorliegt (längere Störung, auch der letzte Abruf hat keinen Preis für jetzt). <b>Nach Plan:</b> weiter nach dem mit dem CKW-Durchschnittspreis gerechneten Plan. <b>Aus/Ein:</b> Ausgang fest aus bzw. ein (z. B. Auto: Aus, Wärmepumpe: Ein). Fehlende Preise für morgen vor ca. 12 Uhr sind davon nicht betroffen – dafür wird immer mit dem Durchschnittspreis geplant.</p>
		<div class="ui-field-contain"><label for="r_power_kw">Leistung des Geräts (kW, optional)</label><?= $inp('power_kw', $r['power_kw'], 'number', 'step="any" min="0"') ?></div>
		<p class="ckw-hint">Nur für die Kostenschätzung, beeinflusst das Schalten nicht: Leistung × ¼ h × Preis jeder Ein-Viertelstunde im Zeitfenster – bereits gelaufene plus noch geplante –, als <code>rule_…_cost</code> (CHF) und in der Vorschau. Beispiel: 11 kW, 4 h zu 20 Rp. = 8.80 CHF. Bei 0 wird kein Kostenwert gesendet. Die Ein-Zeit in Stunden kommt immer als <code>rule_…_hours</code>.</p>

		<h4>Vorschau</h4>
		<p id="ruleSummary" class="ckw-hint">Wird berechnet …</p>
		<div class="ckw-chart"><canvas id="ruleChart"></canvas></div>
		<p class="ckw-hint">Grün = Ein, Linie = Preis (<?= h($punit) ?>), gestrichelt = Grenze, orange = Analogwert. Helle Fläche = Preise noch nicht publiziert (CKW-Durchschnittspreis). Die Vorschau rechnet mit den Formularwerten, ohne zu speichern.</p>

		<button type="submit" data-inline="true" data-icon="check">Speichern</button>
		<a data-role="button" data-inline="true" data-ajax="false" href="index.php?page=rules">Abbrechen</a>
	</form>
	<script src="js/chart.umd.min.js"></script>
	<script>
	(function () {
		var form = document.getElementById('ruleForm');
		function val(n) { var e = document.getElementById('r_' + n); return e ? e.value : ''; }
		function toggle() {
			var t = val('type'), a = val('analog');
			Array.prototype.forEach.call(document.querySelectorAll('.rf'), function (e) {
				e.style.display = e.getAttribute('data-types').split(' ').indexOf(t) >= 0 ? '' : 'none';
			});
			Array.prototype.forEach.call(document.querySelectorAll('.af'), function (e) {
				e.style.display = e.getAttribute('data-analog').split(' ').indexOf(a) >= 0 ? '' : 'none';
			});
		}
		var ref = null, dec = <?= $ckwcfg['unit'] === 'rp' ? 2 : 4 ?>, unit = <?= json_encode($punit) ?>;
		var pctModes = ['band_min', 'band_max', 'rank'];
		function priceFor(mode, v) {
			if (!ref || v === '' || isNaN(v)) return null;
			v = parseFloat(v);
			var b = ref.band;
			if (mode === 'abs') return v;
			if (mode === 'band_min') return b ? b[0] + (b[1] - b[0]) * v / 100 : null;
			if (mode === 'band_max') return b ? b[1] - (b[1] - b[0]) * v / 100 : null;
			if (mode === 'rank') { var p = ref.prices; if (!p.length) return null; return p[Math.floor((p.length - 1) * Math.max(0, Math.min(100, v)) / 100)]; }
			if (mode === 'fixed') return ref.fixed === null ? null : ref.fixed + v;
			return null;
		}
		// Umkehrung von priceFor: Wert im neuen Modus, der denselben Preis ergibt
		function valueFor(mode, price) {
			var b = ref && ref.band;
			if (price === null) return null;
			if (mode === 'abs') return price;
			if (mode === 'band_min') return b && b[1] > b[0] ? (price - b[0]) / (b[1] - b[0]) * 100 : null;
			if (mode === 'band_max') return b && b[1] > b[0] ? (b[1] - price) / (b[1] - b[0]) * 100 : null;
			if (mode === 'rank') { var p = ref.prices, n = 0; if (p.length < 2) return null; p.forEach(function (x) { if (x <= price + 1e-9) n++; }); return Math.max(0, n - 1) / (p.length - 1) * 100; }
			if (mode === 'fixed') return ref.fixed === null ? null : price - ref.fixed;
			return null;
		}
		var lastLimitMode = null;
		function bandPct(price) { var b = ref && ref.band; return b && b[1] > b[0] ? (price - b[0]) / (b[1] - b[0]) * 100 : null; }
		function fmt(x) { return x === null ? '–' : x.toFixed(dec); }
		function rangeFor(mode) {
			var b = ref && ref.band;
			if (pctModes.indexOf(mode) >= 0) return [0, 100, 1, '%'];
			if (mode === 'abs' && b) return [b[0], b[1], dec === 2 ? 0.01 : 0.0001, unit];
			if (mode === 'fixed' && b) return [-(b[1] - b[0]), b[1] - b[0], dec === 2 ? 0.01 : 0.0001, unit];
			return null;
		}
		function setupLimit() {
			var mode = val('limit_mode'), box = document.querySelector('.lim-val');
			if (ref && lastLimitMode !== null && lastLimitMode !== mode && lastLimitMode !== 'none' && mode !== 'none') {
				var nv = valueFor(mode, priceFor(lastLimitMode, document.getElementById('r_limit_value').value));
				if (nv !== null) document.getElementById('r_limit_value').value = +nv.toFixed(pctModes.indexOf(mode) >= 0 ? 0 : (dec === 2 ? 2 : 4));
			}
			if (ref) lastLimitMode = mode;
			box.style.display = mode === 'none' ? 'none' : '';
			var rg = rangeFor(mode), sl = document.getElementById('r_limit_range'), num = document.getElementById('r_limit_value');
			document.querySelector('.lim-unit').textContent = rg ? '(' + rg[3] + ')' : '';
			if (rg) {
				sl.min = rg[0]; sl.max = rg[1]; sl.step = rg[2]; num.min = rg[0]; num.max = rg[1];
				var v = parseFloat(num.value);
				if (isNaN(v) || v < rg[0] || v > rg[1]) { v = Math.max(rg[0], Math.min(rg[1], isNaN(v) ? rg[0] : v)); num.value = +v.toFixed(dec === 2 ? 2 : 4); }
				sl.value = num.value;
			}
			var p = priceFor(mode, num.value), bp = p === null ? null : bandPct(p);
			document.querySelector('.lim-info').textContent = p === null ? '' : '= ' + fmt(p) + ' ' + unit + (bp !== null && mode !== 'band_min' && mode !== 'band_max' ? ' (' + Math.round(bp) + ' % des Preisbands)' : '');
		}
		function setupSteps() {
			var mode = val('steps_mode'), warn = [], rows = [];
			Array.prototype.forEach.call(document.querySelectorAll('.step-row'), function (tr, i) {
				var f = tr.querySelector('.st-from').value, t = tr.querySelector('.st-to').value, o = tr.querySelector('.st-out').value;
				var info = tr.querySelector('.st-info'), bad = false;
				if (f === '' && t === '' && o === '') { info.textContent = ''; tr.style.background = ''; return; }
				var ff = parseFloat(f), tt = parseFloat(t), rg = rangeFor(mode);
				if (isNaN(ff) || isNaN(tt) || o === '') { bad = true; warn.push('Zeile ' + (i + 1) + ': von, bis und Wert angeben'); }
				else if (ff >= tt) { bad = true; warn.push('Zeile ' + (i + 1) + ': „von“ muss kleiner als „bis“ sein'); }
				else if (rg && (ff < rg[0] - 1e-9 || tt > rg[1] + 1e-9)) { bad = true; warn.push('Zeile ' + (i + 1) + ': nur ' + fmtR(rg)); }
				else rows.push([ff, tt]);
				tr.style.background = bad ? '#fde8e8' : '';
				var a = priceFor(mode, f), b = priceFor(mode, t);
				info.textContent = a !== null && b !== null && mode !== 'abs' ? fmt(a) + ' – ' + fmt(b) + ' ' + unit : (mode === 'abs' && !bad ? Math.round(bandPct(ff)) + '–' + Math.round(bandPct(tt)) + ' % Band' : '');
			});
			rows.sort(function (x, y) { return x[0] - y[0]; });
			for (var i = 1; i < rows.length; i++) {
				if (rows[i][0] > rows[i - 1][1] + 1e-9) warn.push('Lücke ' + rows[i - 1][1] + '–' + rows[i][0] + ' → „Wert übrige Preise“');
				if (rows[i][0] < rows[i - 1][1] - 1e-9) warn.push('Überlappung ' + rows[i][0] + '–' + rows[i - 1][1] + ' → die obere Zeile gilt');
			}
			document.querySelector('.step-warn').textContent = warn.join(' · ');
		}
		function fmtR(rg) { return rg[3] === '%' ? '0–100 %' : 'zwischen ' + fmt(rg[0]) + ' und ' + fmt(rg[1]) + ' ' + rg[3]; }
		function setupRef() {
			if (!ref) return;
			var b = ref.band, p = ref.prices;
			Array.prototype.forEach.call(document.querySelectorAll('.band-price'), function (e) { if (b) { e.min = b[0]; e.max = b[1]; } });
			Array.prototype.forEach.call(document.querySelectorAll('.band-text'), function (e) { e.textContent = b ? '(' + fmt(b[0]) + ' – ' + fmt(b[1]) + ' ' + unit + ')' : ''; });
			var t = b ? 'Preisband CKW: ' + fmt(b[0]) + ' – ' + fmt(b[1]) + ' ' + unit + '.' : 'Für diesen Tarif ist kein Preisband hinterlegt.';
			if (p.length) t += ' Preise im Zeitfenster: ' + fmt(p[0]) + ' – ' + fmt(p[p.length - 1]) + ' ' + unit + (b ? ' = ' + Math.round(bandPct(p[0])) + ' % bis ' + Math.round(bandPct(p[p.length - 1])) + ' % des Preisbands' : '') + '.';
			document.querySelector('.ref-info').textContent = t;
		}
		function refresh() { setupLimit(); setupSteps(); setupRef(); }
		document.getElementById('r_limit_range').addEventListener('input', function () { document.getElementById('r_limit_value').value = this.value; setupLimit(); schedule(); });

		var chart = null, timer = null;
		function preview() {
			var fd = new FormData(form);
			fetch('index.php?rules_preview=1', { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (d.error) { document.getElementById('ruleSummary').textContent = d.error; return; }
					ref = d.ref;
					refresh();
					var s = d.summary;
					var hm = function (m) { return Math.floor(m / 60) + ':' + ('0' + m % 60).slice(-2) + ' h'; };
					var tot = s.on_min + s.done_min;
					var txt = (s['in'] ? 'Aktuelles Zeitfenster ' : 'Nächstes Zeitfenster ') + s.window + ': Ein-Zeit ' + hm(tot) + ' (' + (tot / 60).toFixed(2) + ' h)' + (s.done_min ? ', davon ' + hm(s.done_min) + ' gelaufen' : '');
					if (s.limit !== null) txt += ', Grenze ' + s.limit.toFixed(<?= $ckwcfg['unit'] === 'rp' ? 2 : 4 ?>) + ' <?= h($punit) ?>';
					if (s.cost_chf !== null) txt += ', geschätzte Kosten im Fenster ' + s.cost_chf.toFixed(2) + ' CHF';
					document.getElementById('ruleSummary').textContent = txt + '.';
					var pmax = Math.max.apply(null, d.price.filter(function (x) { return x !== null; }).concat([0]));
					var data = {
						labels: d.labels,
						datasets: [
							{ type: 'line', label: 'Preis', data: d.price, yAxisID: 'p', borderColor: '#1f6fb2', pointRadius: 0, borderWidth: 2, stepped: true },
							{ type: 'line', label: 'Grenze', data: d.limit, yAxisID: 'p', borderColor: '#888', borderDash: [6, 4], pointRadius: 0, borderWidth: 1.5, stepped: true },
							{ type: 'line', label: 'Analogwert', data: d.value, yAxisID: 'v', borderColor: '#e67e22', pointRadius: 0, borderWidth: 2, stepped: true, hidden: d.value.every(function (x) { return x === null; }) },
							{ type: 'bar', label: 'Ein', data: d.on.map(function (o) { return o ? 1 : null; }), yAxisID: 'o', backgroundColor: 'rgba(60,176,67,0.35)', barPercentage: 1.0, categoryPercentage: 1.0 },
							{ type: 'bar', label: 'geschätzte Preise', data: d.real.map(function (r) { return r ? null : 1; }), yAxisID: 'o', backgroundColor: 'rgba(0,0,0,0.05)', barPercentage: 1.0, categoryPercentage: 1.0 }
						]
					};
					var opts = {
						responsive: true, maintainAspectRatio: false, animation: false, interaction: { mode: 'index', intersect: false },
						scales: {
							x: { stacked: true, ticks: { autoSkip: true, maxTicksLimit: 16 } },
							p: { position: 'left', grace: '10%', title: { display: true, text: '<?= h($punit) ?>' } },
							v: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Wert' } },
							o: { display: false, min: 0, max: 1, stacked: false }
						}
					};
					if (chart) { chart.data = data; chart.update(); } else { chart = new Chart(document.getElementById('ruleChart'), { data: data, options: opts }); }
				})
				.catch(function () { document.getElementById('ruleSummary').textContent = 'Vorschau nicht verfügbar.'; });
		}
		function schedule() { clearTimeout(timer); timer = setTimeout(preview, 400); }
		form.addEventListener('change', function () { toggle(); refresh(); schedule(); });
		form.addEventListener('input', function () { setupLimit(); setupSteps(); schedule(); });
		toggle();
		refresh();
		preview();
	})();
	</script>
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
