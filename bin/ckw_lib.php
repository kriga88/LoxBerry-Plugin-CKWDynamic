<?php
/**
 * CKW Dynamischer Tarif - gemeinsame Logik fuer Cronjob (fetch.php) und Weboberflaeche.
 *
 * Kompatibel mit PHP 7.4 (LoxBerry 3.x) und PHP 8.x.
 * Alle LoxBerry-SDK-Aufrufe (mqtt_connectiondetails, msudp_send, Pfad-Variablen)
 * sind auf wenige Funktionen beschraenkt, damit die Logik auch ausserhalb testbar bleibt.
 */

define('CKW_API_URL', getenv('CKW_API_URL') ?: 'https://e-ckw-public-data.de-c1.eu1.cloudhub.io/api/v1/netzinformationen/energie/dynamische-preise');   // Umgebungsvariable nur fuer Tests
define('CKW_TARIFFS_URL', 'https://raw.githubusercontent.com/kriga88/LoxBerry-Plugin-CKWDynamic/main/tariffs.json');
define('CKW_TZ', 'Europe/Zurich');
define('CKW_LOX_EPOCH_OFFSET', 1230768000);   // Loxone-Zeit = Unix-Zeit - 1.1.2009
// Gemeinden, deren prozentuale Konzessionsabgabe laut CKW-Preisblatt OHNE Stromreserve berechnet wird
define('CKW_PERCENT_EXCL_RESERVE', 'Ebikon|Escholzmatt-Marbach|Flühli|Hasle|Hergiswil bei Willisau|Honau|Nottwil|Oberkirch|Werthenstein');
define('CKW_TARIFFS_MAXAGE', 86400);
define('CKW_TARIFF_CACHE_FORMAT', 2);       // erhoehen, wenn sich die Auswertung der CKW-Tarifdatei aendert          // Preistabellen hoechstens 1x pro Tag laden
define('CKW_TARIFFS_PAGE', getenv('CKW_TARIFFS_PAGE') ?: 'https://www.ckw.ch/energie/strom/stromprodukte/privat');   // verlinkt die maschinenlesbaren Tarifdateien (Umgebungsvariable nur fuer Tests)

// Komponente => Beschreibung (Reihenfolge = Anzeige)
$CKW_COMPONENTS = array(
	'total'      => 'Total (Netz + Stromprodukt + Konzessionsabgabe + Zusatzkosten)',
	'integrated' => 'CKW integrated (Netz + ClassicStrom, laut API)',
	'grid'       => 'Netz gesamt (dynamisch + fixe Zuschläge)',
	'gridusage'  => 'Netznutzung dynamisch',
	'gridfix'    => 'Netz fixe Zuschläge (SDL, Reserve, Netzzuschlag ...)',
	'energy'     => 'Energie (Stromprodukt)',
	'concession' => 'Konzessionsabgabe Gemeinde',
);

$CKW_TARIFF_NAMES = array(
	'home_dynamic'     => 'CKW Netz Home dynamic (< 50 MWh/Jahr)',
	'business_dynamic' => 'CKW Netz Business dynamic (ab 50 MWh/Jahr)',
);

// ---------------------------------------------------------------------------
// Pfade
// ---------------------------------------------------------------------------

function ckw_config_dir()
{
	global $lbpconfigdir;
	return $lbpconfigdir;
}

function ckw_data_dir()
{
	global $lbpdatadir;
	if (!is_dir($lbpdatadir)) {
		@mkdir($lbpdatadir, 0775, true);
	}
	return $lbpdatadir;
}

function ckw_config_file()  { return ckw_config_dir() . '/ckwdynamic.json'; }
function ckw_state_file()   { return ckw_data_dir() . '/state.json'; }
function ckw_cache_file()   { return ckw_data_dir() . '/api_cache.json'; }
function ckw_tariffs_cache(){ return ckw_data_dir() . '/tariffs_cache.json'; }
function ckw_published_file(){ return ckw_data_dir() . '/published.json'; }

// ---------------------------------------------------------------------------
// Konfiguration
// ---------------------------------------------------------------------------

function ckw_defaults()
{
	return array(
		'tariff'           => 'home_dynamic',
		'product'          => 'classic',
		'custom_energy_rp' => 12.0,
		'extra_rp'         => 0.0,
		'municipality'     => '',
		'vat_enabled'      => false,
		'vat_rate'         => 8.1,
		'unit'             => 'chf',
		'components'       => array('total'),
		'series_rel'       => true,
		'series_abs'       => false,
		'series_tmr'       => false,
		'fill_mode'        => 'ckwavg',
		'cheap_hours'      => 3,
		'tariffs_remote'   => true,
		'mqtt_enabled'     => true,
		'mqtt_topic'       => 'ckwdynamic',
		'udp_enabled'      => false,
		'udp_msnr'         => 1,
		'udp_port'         => 7000,
		'udp_prefix'       => '',
	);
}

function ckw_valid_topic($topic)
{
	return is_string($topic) && strlen($topic) <= 100
		&& preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $topic) === 1;
}

function ckw_to_bool($v)
{
	if (is_bool($v)) {
		return $v;
	}
	return in_array(strtolower(trim((string)$v)), array('1', 'true', 'on', 'yes', 'ja'), true);
}

function ckw_to_float($v, $default)
{
	if (is_string($v)) {
		$v = str_replace(',', '.', trim($v));
	}
	return is_numeric($v) ? (float)$v : $default;
}

/**
 * Prueft und normalisiert eine Konfiguration. Fehler werden in $errors gesammelt,
 * ungueltige Werte durch die Standardwerte ersetzt.
 */
function ckw_sanitize_config(array $in, &$errors = null)
{
	global $CKW_COMPONENTS, $CKW_TARIFF_NAMES;
	$errors = array();
	$d = ckw_defaults();
	$c = array_merge($d, $in);
	$out = array();

	$out['tariff'] = isset($CKW_TARIFF_NAMES[$c['tariff']]) ? $c['tariff'] : $d['tariff'];

	$out['product'] = preg_match('/^[a-z0-9_]{1,30}$/', (string)$c['product']) ? $c['product'] : $d['product'];

	$out['custom_energy_rp'] = ckw_to_float($c['custom_energy_rp'], null);
	if ($out['custom_energy_rp'] === null || $out['custom_energy_rp'] < 0 || $out['custom_energy_rp'] > 200) {
		$errors[] = 'Eigener Energiepreis muss zwischen 0 und 200 Rp./kWh liegen.';
		$out['custom_energy_rp'] = $d['custom_energy_rp'];
	}

	$out['municipality'] = preg_match('/^[\p{L}0-9 .()\'\/-]{0,60}$/u', (string)$c['municipality']) ? trim((string)$c['municipality']) : '';

	$out['extra_rp'] = ckw_to_float($c['extra_rp'], null);
	if ($out['extra_rp'] === null || $out['extra_rp'] < -50 || $out['extra_rp'] > 100) {
		$errors[] = 'Zusatzkosten müssen zwischen -50 und 100 Rp./kWh liegen.';
		$out['extra_rp'] = $d['extra_rp'];
	}

	$out['vat_enabled'] = ckw_to_bool($c['vat_enabled']);
	$out['vat_rate'] = ckw_to_float($c['vat_rate'], null);
	if ($out['vat_rate'] === null || $out['vat_rate'] < 0 || $out['vat_rate'] > 30) {
		$errors[] = 'MwSt-Satz muss zwischen 0 und 30 % liegen.';
		$out['vat_rate'] = $d['vat_rate'];
	}

	$out['unit'] = in_array($c['unit'], array('chf', 'rp'), true) ? $c['unit'] : $d['unit'];

	$comps = is_array($c['components']) ? $c['components'] : array();
	$out['components'] = array_values(array_intersect(array_keys($CKW_COMPONENTS), $comps));

	$out['series_rel'] = ckw_to_bool($c['series_rel']);
	$out['series_abs'] = ckw_to_bool($c['series_abs']);
	$out['series_tmr'] = ckw_to_bool($c['series_tmr']);

	$out['fill_mode'] = in_array($c['fill_mode'], array('ckwavg', 'max', 'last', 'zero'), true) ? $c['fill_mode'] : $d['fill_mode'];

	$out['cheap_hours'] = (int)$c['cheap_hours'];
	if ($out['cheap_hours'] < 1 || $out['cheap_hours'] > 12) {
		$errors[] = 'Günstigstes Zeitfenster: 1 bis 12 Stunden.';
		$out['cheap_hours'] = $d['cheap_hours'];
	}

	$out['tariffs_remote'] = ckw_to_bool($c['tariffs_remote']);

	$out['mqtt_enabled'] = ckw_to_bool($c['mqtt_enabled']);
	$out['mqtt_topic'] = trim((string)$c['mqtt_topic']);
	if (!ckw_valid_topic($out['mqtt_topic'])) {
		$errors[] = 'Ungültiges MQTT-Topic. Erlaubt: Buchstaben, Ziffern, _ und -, Ebenen mit / getrennt, ohne # und +, ohne / am Anfang oder Ende.';
		$out['mqtt_topic'] = $d['mqtt_topic'];
	}

	$out['udp_enabled'] = ckw_to_bool($c['udp_enabled']);
	$out['udp_msnr'] = max(1, (int)$c['udp_msnr']);
	$out['udp_port'] = (int)$c['udp_port'];
	if ($out['udp_port'] < 1 || $out['udp_port'] > 65535) {
		$errors[] = 'UDP-Port muss zwischen 1 und 65535 liegen.';
		$out['udp_port'] = $d['udp_port'];
	}
	$out['udp_prefix'] = trim((string)$c['udp_prefix']);
	if (!preg_match('/^[A-Za-z0-9_\-]{0,20}$/', $out['udp_prefix'])) {
		$errors[] = 'UDP-Präfix: max. 20 Zeichen, nur Buchstaben, Ziffern, _ und -.';
		$out['udp_prefix'] = $d['udp_prefix'];
	}

	return $out;
}

function ckw_load_config()
{
	$file = ckw_config_file();
	$raw = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
	return ckw_sanitize_config(is_array($raw) ? $raw : array());
}

/**
 * Speichert die Konfiguration und aktualisiert die Abo-Datei fuer das MQTT-Gateway,
 * damit die Werte ohne manuelles Abonnieren beim Miniserver ankommen.
 */
function ckw_save_config(array $cfg)
{
	$json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (file_put_contents(ckw_config_file(), $json . "\n", LOCK_EX) === false) {
		return false;
	}
	ckw_write_subscriptions($cfg);
	return true;
}

function ckw_write_subscriptions(array $cfg)
{
	$file = ckw_config_dir() . '/mqtt_subscriptions.cfg';
	$content = $cfg['mqtt_enabled'] ? $cfg['mqtt_topic'] . "/+\n" : '';
	if (!is_file($file) || file_get_contents($file) !== $content) {
		file_put_contents($file, $content, LOCK_EX);
	}
}

// ---------------------------------------------------------------------------
// HTTP / JSON-Helfer
// ---------------------------------------------------------------------------

function ckw_http_get($url, $timeout, &$error, $accept = 'application/json')
{
	$error = null;
	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_USERAGENT      => 'LoxBerry-Plugin-CKWDynamic',
			CURLOPT_HTTPHEADER     => array('Accept: ' . $accept),
		));
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($body === false) {
			$error = 'HTTP-Fehler: ' . curl_error($ch);
		} elseif ($code !== 200) {
			$error = "HTTP-Status $code";
		}
		if (PHP_VERSION_ID < 80000) {
			curl_close($ch);
		}
		return $error === null ? $body : null;
	}
	$ctx = stream_context_create(array('http' => array(
		'timeout' => $timeout,
		'header'  => "Accept: $accept\r\nUser-Agent: LoxBerry-Plugin-CKWDynamic\r\n",
		'ignore_errors' => true,
	)));
	$body = @file_get_contents($url, false, $ctx);
	$headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ckw_legacy_headers(get_defined_vars());
	$status = isset($headers[0]) ? $headers[0] : '';
	if ($body === false) {
		$error = 'HTTP-Fehler: keine Antwort';
		return null;
	}
	if (strpos($status, ' 200') === false) {
		$error = 'HTTP-Status ' . $status;
		return null;
	}
	return $body;
}

// PHP < 8.4: Header stehen in der lokalen Variable $http_response_header
function ckw_legacy_headers(array $vars)
{
	return isset($vars['http_response_header']) ? $vars['http_response_header'] : array();
}

function ckw_read_json($file)
{
	if (!is_file($file)) {
		return null;
	}
	$data = json_decode((string)file_get_contents($file), true);
	return is_array($data) ? $data : null;
}

function ckw_write_json($file, $data)
{
	$tmp = $file . '.tmp';
	file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	rename($tmp, $file);
}

// ---------------------------------------------------------------------------
// Preistabelle der Stromprodukte (jaehrlich wechselnde Energiepreise)
// ---------------------------------------------------------------------------

function ckw_tariffs_valid($t)
{
	if (!is_array($t) || !isset($t['products']) || !is_array($t['products'])) {
		return false;
	}
	foreach ($t['products'] as $p) {
		if (!isset($p['name'], $p['prices']) || !is_array($p['prices'])) {
			return false;
		}
		foreach ($p['prices'] as $e) {
			if (!isset($e['valid_from'], $e['rp_kwh']) || !is_numeric($e['rp_kwh'])
				|| !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e['valid_from'])) {
				return false;
			}
		}
	}
	return true;
}

/** Leitet aus Dateinamen wie "20260817_tariffs.json" das Datum ab */
function ckw_date_from_name($url)
{
	return preg_match('#/(\d{4})(\d{2})(\d{2})_[^/]*$#', $url, $m) ? "$m[1]-$m[2]-$m[3]" : '';
}

/**
 * Wandelt eine maschinenlesbare CKW-Tarifdatei (VSE-Standard, gesetzlich ab 2026)
 * in unser Tabellenformat um: Energieprodukte der Grundversorgung + Abgaben pro Gemeinde.
 */
function ckw_parse_ckw_tariff_file(array $j)
{
	$out = array('products' => array(), 'municipalities' => array());
	if (!isset($j['tariffs']) || !is_array($j['tariffs'])) {
		return $out;
	}
	$map = array('budget' => 'budget', 'meinregio' => 'meinregio', 'class' => 'classic');   // CKW schreibt teils "ClassisStrom"
	foreach ($j['tariffs'] as $t) {
		$type = isset($t['tariffType']) ? $t['tariffType'] : '';
		$from = isset($t['startDate']) ? (string)$t['startDate'] : '';
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
			continue;
		}
		$name = isset($t['tariffName']) ? (string)$t['tariffName'] : '';

		if ($type === 'electricity' && isset($t['customerType']) && stripos($t['customerType'], 'grundversorgung') !== false) {
			$key = null;
			foreach ($map as $needle => $k) {
				if (stripos(str_replace(' ', '', $name), $needle) !== false) {
					$key = $k;
					break;
				}
			}
			$energy = isset($t['prices']['energy']) && is_array($t['prices']['energy']) ? $t['prices']['energy'] : array();
			if ($key === null || count($energy) !== 1 || !isset($energy[0]['price']) || !is_numeric($energy[0]['price'])) {
				continue;   // unbekanntes Produkt oder zeitvariabler Tarif
			}
			$rp = round($energy[0]['price'] * 100, 4);
			if ($rp < 2 || $rp > 50) {
				continue;   // unplausibel
			}
			$out['products'][$key][] = array('valid_from' => $from, 'rp_kwh' => $rp);
		}

		if ($type === 'regional_fees' && isset($t['prices']['municipalityTaxes']) && is_array($t['prices']['municipalityTaxes'])) {
			$fees = array();   // je Abgabe zaehlt eine Gemeinde nur einmal (CKW fuehrt z. B. Fluehli doppelt)
			foreach ($t['prices']['municipalityTaxes'] as $m) {
				$mn = isset($m['municipalityName']) ? trim((string)$m['municipalityName']) : '';
				$me = isset($m['municipalityEnergy']) && is_array($m['municipalityEnergy']) ? $m['municipalityEnergy'] : array();
				if ($mn === '' || count($me) !== 1 || !isset($me[0]['price']) || !is_numeric($me[0]['price'])) {
					continue;
				}
				$rp = round($me[0]['price'] * 100, 4);
				if ($rp < 0 || $rp > 5) {
					continue;
				}
				// CKW kennzeichnet prozentuale Abgaben im Kommentar ("noch mit 10% auf Netznutzung") und
				// traegt nur einen Durchschnitt ein -> exakt pro Viertelstunde rechnen
				$comment = isset($m['municipalityComment']) ? (string)$m['municipalityComment'] : '';
				if (preg_match('/(\d+(?:[.,]\d+)?)\s*%\s*auf\s+Netznutzung/iu', $comment, $pm)) {
					$fees[$mn] = array('percent' => (float)str_replace(',', '.', $pm[1]), 'avg_rp' => $rp);
				} else {
					$fees[$mn] = $rp;
				}
			}
			// Verschiedene Abgaben derselben Gemeinde (z. B. Klimarappen Luzern) zusammenzaehlen
			foreach ($fees as $mn => $fee) {
				$prev = isset($out['municipalities'][$mn][$from]) ? $out['municipalities'][$mn][$from] : array('rp' => 0);
				if (is_array($fee)) {
					$prev['percent'] = $fee['percent'];
					$prev['avg_rp'] = $fee['avg_rp'];
				} else {
					$prev['rp'] += $fee;
				}
				$out['municipalities'][$mn][$from] = $prev;
			}
		}
	}
	foreach ($out['municipalities'] as $mn => $byDate) {
		$list = array();
		foreach ($byDate as $from => $f) {
			$e = array('valid_from' => $from, 'rp_kwh' => round($f['rp'], 4));
			if (isset($f['percent'])) {
				// fixer Anteil (z. B. Klimarappen) bleibt in rp_kwh, der prozentuale Teil kommt dazu
				$e['percent'] = $f['percent'];
				$e['excl_reserve'] = in_array($mn, explode('|', CKW_PERCENT_EXCL_RESERVE), true);
				$e['avg_rp'] = $f['avg_rp'];
			}
			$list[] = $e;
		}
		$out['municipalities'][$mn] = $list;
	}
	return $out;
}

/**
 * Liest die CKW-Tarifdateien direkt von ckw.ch (Links von der Produktseite, da sich der Pfad jaehrlich aendert).
 * Hoechstens einmal pro Tag, nach einem Fehler erneuter Versuch nach 6 Stunden.
 * Rueckgabe: array('data' => Tabelle, 'updated' => 'YYYY-MM-DD') oder null
 */
function ckw_load_ckw_tariffs($allowNetwork, $log = null)
{
	$cacheFile = ckw_data_dir() . '/ckw_tariffs_cache.json';
	$cache = ckw_read_json($cacheFile);
	if ($cache && (!isset($cache['format']) || $cache['format'] !== CKW_TARIFF_CACHE_FORMAT)) {
		$cache = null;   // Cache einer aelteren Plugin-Version -> neu einlesen
	}
	$age = $cache ? time() - (int)$cache['checked'] : PHP_INT_MAX;
	$due = !$cache || (!empty($cache['failed']) ? $age > 6 * 3600 : $age > CKW_TARIFFS_MAXAGE);

	if ($allowNetwork && $due) {
		$err = null;
		$page = ckw_http_get(CKW_TARIFFS_PAGE, 20, $err, 'text/html,application/xhtml+xml,*/*;q=0.8');
		$urls = array();
		if ($page !== null && preg_match_all('#(?:https://www\.ckw\.ch)?/_Resources/Persistent/[^"\'\s<>]+?_tariffs\.json#i', html_entity_decode($page), $m)) {
			foreach (array_unique($m[0]) as $u) {
				$urls[] = strpos($u, 'http') === 0 ? $u : 'https://www.ckw.ch' . $u;
			}
		}
		$merged = array('products' => array(), 'municipalities' => array());
		$updated = '';
		foreach (array_slice($urls, 0, 6) as $u) {
			$body = ckw_http_get($u, 20, $err, 'application/json,*/*;q=0.8');
			$j = $body !== null ? json_decode($body, true) : null;
			if (!is_array($j)) {
				ckw_log($log, 'WARN', "CKW-Tarifdatei nicht lesbar: $u");
				continue;
			}
			$merged = ckw_merge_tariffs($merged, ckw_parse_ckw_tariff_file($j));
			$updated = max($updated, ckw_date_from_name($u));
		}
		if ($merged['products']) {
			$cache = array('format' => CKW_TARIFF_CACHE_FORMAT, 'checked' => time(), 'failed' => false, 'updated' => $updated, 'files' => $urls, 'data' => $merged);
			ckw_write_json($cacheFile, $cache);
			ckw_log($log, 'OK', 'Jahrespreise aus ' . count($urls) . ' CKW-Tarifdatei(en) übernommen (Stand ' . $updated . ').');
		} else {
			ckw_log($log, 'WARN', 'CKW-Tarifdateien nicht gefunden oder unbrauchbar (' . ($err ?: count($urls) . ' Links') . ') - verwende gespeicherte Preise bzw. Rückfalltabelle.');
			$cache = $cache ?: array('format' => CKW_TARIFF_CACHE_FORMAT, 'data' => null, 'updated' => '', 'files' => array());
			$cache['checked'] = time();
			$cache['failed'] = true;
			ckw_write_json($cacheFile, $cache);
		}
	}
	return ($cache && !empty($cache['data']['products'])) ? array('data' => $cache['data'], 'updated' => $cache['updated']) : null;
}

/** Fuehrt zwei Tabellen zusammen; Eintraege von $b ersetzen solche von $a mit gleichem Gueltig-ab-Datum. */
function ckw_merge_tariffs(array $a, array $b)
{
	foreach (array('products', 'municipalities', 'dynamic_reference') as $sec) {
		if (empty($b[$sec])) {
			continue;
		}
		foreach ($b[$sec] as $key => $entry) {
			if (!is_array($entry) || (isset($a[$sec][$key]) && !is_array($a[$sec][$key]))) {
				continue;   // Infotexte o. ae. ueberspringen
			}
			$newPrices = $sec === 'products' && isset($entry['prices']) ? $entry['prices'] : $entry;
			$old = array();
			if (isset($a[$sec][$key])) {
				$old = $sec === 'products' && isset($a[$sec][$key]['prices']) ? $a[$sec][$key]['prices'] : $a[$sec][$key];
			}
			$byDate = array();
			foreach (array_merge($old, $newPrices) as $e) {
				if (is_array($e) && isset($e['valid_from'])) {
					$byDate[$e['valid_from']] = $e;
				}
			}
			ksort($byDate);
			if ($sec === 'products') {
				$meta = isset($a[$sec][$key]) && isset($a[$sec][$key]['name']) ? $a[$sec][$key] : (isset($entry['name']) ? $entry : array('name' => $key));
				foreach (array('name', 'description', 'from_api') as $f) {
					if (isset($entry[$f])) {
						$meta[$f] = $entry[$f];
					}
				}
				$meta['prices'] = array_values($byDate);
				$a[$sec][$key] = $meta;
			} else {
				$a[$sec][$key] = array_values($byDate);
			}
		}
	}
	return $a;
}

/**
 * Liefert die Preistabelle (Energieprodukte + Gemeindeabgaben). Quellen, spaetere gewinnen:
 *   1. mitgelieferte tariffs.json  2. tariffs.json aus dem GitHub-Repo  3. maschinenlesbare CKW-Tarifdatei
 * $allowNetwork = false (Weboberflaeche): nur gespeicherte Stände verwenden.
 * Rueckgabe: array('data' => ..., 'source' => Text, 'updated' => 'YYYY-MM-DD', 'ckw' => bool)
 */
function ckw_load_tariffs($remote, $log = null, $allowNetwork = true)
{
	$bundled = ckw_read_json(__DIR__ . '/tariffs.json');
	$data = ckw_tariffs_valid($bundled) ? $bundled : array('products' => array());
	$sources = array('mitgeliefert ' . (isset($bundled['updated']) ? $bundled['updated'] : ''));
	$updated = isset($bundled['updated']) ? $bundled['updated'] : '';
	$ckwOk = false;

	if ($remote) {
		// GitHub-Tabelle (Rueckfall, falls CKW die Datei einmal nicht publiziert)
		$cacheFile = ckw_tariffs_cache();
		$gh = ckw_read_json($cacheFile);
		if ($allowNetwork && (!$gh || time() - filemtime($cacheFile) >= CKW_TARIFFS_MAXAGE)) {
			$err = null;
			$body = ckw_http_get(CKW_TARIFFS_URL, 15, $err);
			$new = $body !== null ? json_decode($body, true) : null;
			if (ckw_tariffs_valid($new)) {
				ckw_write_json($cacheFile, $new);
				$gh = $new;
			} else {
				ckw_log($log, 'DEB', 'GitHub-Preistabelle nicht geladen (' . ($err ?: 'ungültiges Format') . ').');
				if ($gh) {
					touch($cacheFile);
				}
			}
		}
		if (ckw_tariffs_valid($gh)) {
			$data = ckw_merge_tariffs($data, $gh);
			$sources[] = 'GitHub ' . (isset($gh['updated']) ? $gh['updated'] : '');
			$updated = max($updated, isset($gh['updated']) ? $gh['updated'] : '');
		}

		$ckw = ckw_load_ckw_tariffs($allowNetwork, $log);
		if ($ckw) {
			$data = ckw_merge_tariffs($data, $ckw['data']);
			$sources[] = 'CKW-Tarifdatei ' . $ckw['updated'];
			$updated = max($updated, $ckw['updated']);
			$ckwOk = true;
		}
	}
	foreach (array('municipalities', 'dynamic_reference') as $sec) {
		if (!isset($data[$sec])) {
			$data[$sec] = array();
		}
	}
	return array('data' => $data, 'source' => implode(', ', $sources), 'updated' => $updated, 'ckw' => $ckwOk);
}

/** Gueltiger Eintrag einer Preisliste fuer ein Datum (spaetestes valid_from <= Datum) */
function ckw_entry_for_date(array $entries, $ymd)
{
	$best = null;
	foreach ($entries as $e) {
		if ($e['valid_from'] <= $ymd && ($best === null || $e['valid_from'] > $best['valid_from'])) {
			$best = $e;
		}
	}
	if ($best === null) {
		return null;
	}
	return array(
		'rp'         => (float)$best['rp_kwh'],
		'valid_from' => $best['valid_from'],
		// Preis stammt aus einem Vorjahr -> vermutlich fehlt der neue Jahrespreis
		'outdated'   => substr($best['valid_from'], 0, 4) < substr($ymd, 0, 4),
		'entry'      => $best,
	);
}

/** Referenzwert aus dynamic_reference (z. B. CKW-Durchschnittspreis) in Rp./kWh fuer ein Datum */
function ckw_reference(array $tariffs, $key, $ymd)
{
	return isset($tariffs['dynamic_reference'][$key]) ? ckw_entry_for_date($tariffs['dynamic_reference'][$key], $ymd) : null;
}

/**
 * Energiepreis eines Produkts (Rp./kWh) fuer ein Datum.
 * Rueckgabe: array('rp' => float, 'valid_from' => 'YYYY-MM-DD', 'outdated' => bool) oder null
 */
function ckw_product_price(array $tariffs, $product, $ymd)
{
	return isset($tariffs['products'][$product]) ? ckw_entry_for_date($tariffs['products'][$product]['prices'], $ymd) : null;
}

/** Konzessionsabgabe (inkl. weiterer kommunaler Abgaben) einer Gemeinde in Rp./kWh */
function ckw_municipality_fee(array $tariffs, $name, $ymd)
{
	return isset($tariffs['municipalities'][$name]) ? ckw_entry_for_date($tariffs['municipalities'][$name], $ymd) : null;
}

function ckw_product_list(array $tariffs)
{
	$list = array();
	foreach ($tariffs['products'] as $key => $p) {
		$list[$key] = $p['name'];
	}
	$list['custom'] = 'Anderes Produkt / eigener Preis';
	return $list;
}

// ---------------------------------------------------------------------------
// CKW-API
// ---------------------------------------------------------------------------

function ckw_api_url($tariff, DateTimeImmutable $start, DateTimeImmutable $end)
{
	return CKW_API_URL . '?' . http_build_query(array(
		'tariff_name'     => $tariff,
		'start_timestamp' => $start->format('Y-m-d\TH:i:sP'),
		'end_timestamp'   => $end->format('Y-m-d\TH:i:sP'),
	));
}

/**
 * Holt alle Komponenten fuer heute 00:00 bis morgen 23:45 (end_timestamp ist inklusiv).
 * Rueckgabe: Liste der Rohslots oder null bei Fehler.
 */
function ckw_fetch_api($tariff, DateTimeImmutable $now, &$error, $log = null)
{
	$today = $now->setTime(0, 0, 0);
	$end = $today->modify('+2 days')->modify('-15 minutes');
	$url = ckw_api_url($tariff, $today, $end);
	ckw_log($log, 'DEB', "API: $url");

	for ($try = 1; $try <= 3; $try++) {
		$body = ckw_http_get($url, 20, $error);
		if ($body !== null) {
			$data = json_decode($body, true);
			if (is_array($data) && isset($data['prices']) && is_array($data['prices'])) {
				$error = null;
				return $data['prices'];
			}
			$error = 'Antwort ohne Preisdaten';
		}
		ckw_log($log, 'WARN', "API-Versuch $try fehlgeschlagen: $error");
		if ($try < 3) {
			sleep(3 * $try);
		}
	}
	return null;
}

/**
 * Wandelt die Rohslots in eine Map Unix-Start => Komponenten (CHF/kWh exkl. MwSt).
 */
function ckw_parse_slots(array $prices)
{
	$slots = array();
	foreach ($prices as $p) {
		if (!isset($p['start_timestamp'])) {
			continue;
		}
		try {
			$ts = (new DateTimeImmutable($p['start_timestamp']))->getTimestamp();
		} catch (Exception $e) {
			continue;
		}
		$get = function ($k) use ($p) {
			return (isset($p[$k][0]['value']) && is_numeric($p[$k][0]['value'])) ? (float)$p[$k][0]['value'] : null;
		};
		$slot = array(
			'grid_usage'  => $get('grid_usage'),
			'grid'        => $get('grid'),
			'electricity' => $get('electricity'),
			'integrated'  => $get('integrated'),
		);
		if ($slot['grid'] === null && $slot['grid_usage'] === null) {
			continue;
		}
		$slots[$ts] = $slot;
	}
	ksort($slots);
	return $slots;
}

// ---------------------------------------------------------------------------
// Berechnung
// ---------------------------------------------------------------------------

/**
 * Berechnet alle Komponenten pro 15-Min-Slot inkl. Stromprodukt, Zusatzkosten, MwSt und Einheit.
 * $meta sammelt Infos zur Preisquelle und Warnungen.
 */
function ckw_compute_slots(array $raw, array $cfg, array $tariffs, &$meta)
{
	$tz = new DateTimeZone(CKW_TZ);
	$meta = array('energy_source' => '', 'energy_valid_from' => '', 'warnings' => array());
	$factor = ($cfg['vat_enabled'] ? (1 + $cfg['vat_rate'] / 100) : 1) * ($cfg['unit'] === 'rp' ? 100 : 1);
	$extra = $cfg['extra_rp'] / 100;
	$priceCache = array();
	$feeCache = array();
	$out = array();

	foreach ($raw as $ts => $s) {
		$ymd = (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d');
		$grid = $s['grid'] !== null ? $s['grid'] : $s['grid_usage'];
		$gridUsage = $s['grid_usage'] !== null ? $s['grid_usage'] : $grid;

		// Energiepreis des Stromprodukts
		$energy = null;
		if ($cfg['product'] === 'custom') {
			$energy = $cfg['custom_energy_rp'] / 100;
			$meta['energy_source'] = 'Eigener Preis';
		} else {
			$fromApi = !empty($tariffs['products'][$cfg['product']]['from_api']);
			if ($fromApi && $s['electricity'] !== null) {
				$energy = $s['electricity'];
				$meta['energy_source'] = 'CKW-API';
			} else {
				if (!isset($priceCache[$ymd])) {
					$priceCache[$ymd] = ckw_product_price($tariffs, $cfg['product'], $ymd);
				}
				$pp = $priceCache[$ymd];
				if ($pp !== null) {
					$energy = $pp['rp'] / 100;
					$meta['energy_source'] = 'Preistabelle';
					$meta['energy_valid_from'] = $pp['valid_from'];
					if ($pp['outdated']) {
						$meta['warnings']['outdated'] = 'Für ' . substr($ymd, 0, 4) . ' fehlt der Energiepreis in der Preistabelle - verwende Preis gültig ab ' . $pp['valid_from'] . '.';
					}
				} elseif ($s['electricity'] !== null) {
					$energy = $s['electricity'];
					$meta['energy_source'] = 'CKW-API (Rückfall)';
					$meta['warnings']['noproduct'] = 'Stromprodukt "' . $cfg['product'] . '" nicht in der Preistabelle - verwende ClassicStrom.';
				}
			}
		}
		if ($energy === null) {
			$energy = 0.0;
			$meta['warnings']['noenergy'] = 'Kein Energiepreis verfügbar - Total enthält nur Netzkosten.';
		}

		// Konzessionsabgabe der gewaehlten Gemeinde (aus der CKW-Tarifdatei)
		$concession = 0.0;
		if ($cfg['municipality'] !== '') {
			if (!isset($feeCache[$ymd])) {
				$feeCache[$ymd] = ckw_municipality_fee($tariffs, $cfg['municipality'], $ymd);
			}
			$fee = $feeCache[$ymd];
			if ($fee !== null) {
				$concession = $fee['rp'] / 100;
				if (isset($fee['entry']['percent'])) {
					// Prozent vom Netznutzungsentgelt = Netz gesamt ohne Netzzuschlag (oeffentliche Abgabe),
					// je nach Gemeinde zusaetzlich ohne Stromreserve
					$nz = ckw_reference($tariffs, 'netzzuschlag', $ymd);
					$base = $grid - ($nz ? $nz['rp'] / 100 : 0.023);
					if (!empty($fee['entry']['excl_reserve'])) {
						$sr = ckw_reference($tariffs, 'stromreserve', $ymd);
						$base -= $sr ? $sr['rp'] / 100 : 0;
					}
					$concession += max(0, $base) * $fee['entry']['percent'] / 100;
				}
				if ($fee['outdated']) {
					$meta['warnings']['feeoutdated'] = 'Für ' . substr($ymd, 0, 4) . ' fehlt die Konzessionsabgabe von ' . $cfg['municipality'] . ' - verwende Wert gültig ab ' . $fee['valid_from'] . '.';
				}
			} elseif (!isset($tariffs['municipalities'][$cfg['municipality']])) {
				$meta['warnings']['nofee'] = 'Für die Gemeinde ' . $cfg['municipality'] . ' ist keine Konzessionsabgabe bekannt.';
			}
			// Vor dem ersten Eintrag (CKW publiziert Gemeindeabgaben erst ab 2027) gilt 0
		}

		$integrated = $s['integrated'] !== null ? $s['integrated'] : $grid + ($s['electricity'] !== null ? $s['electricity'] : 0);

		$vals = array(
			'total'      => $grid + $energy + $concession + $extra,
			'integrated' => $integrated,
			'grid'       => $grid,
			'gridusage'  => $gridUsage,
			'gridfix'    => $grid - $gridUsage,
			'energy'     => $energy,
			'concession' => $concession,
		);
		foreach ($vals as $k => $v) {
			$vals[$k] = $v * $factor;
		}
		$out[$ts] = $vals;
	}
	return $out;
}

/** Stundenmittel: Unix-Stundenbeginn => Komponenten (Mittel aller vorhandenen Viertelstunden) */
function ckw_hourly(array $slots)
{
	$sum = array();
	$cnt = array();
	foreach ($slots as $ts => $vals) {
		$h = $ts - ($ts % 3600);   // Schweizer Zeitzone hat volle Stunden-Offsets
		foreach ($vals as $k => $v) {
			$sum[$h][$k] = (isset($sum[$h][$k]) ? $sum[$h][$k] : 0) + $v;
		}
		$cnt[$h] = (isset($cnt[$h]) ? $cnt[$h] : 0) + 1;
	}
	$out = array();
	foreach ($sum as $h => $vals) {
		foreach ($vals as $k => $v) {
			$out[$h][$k] = $v / $cnt[$h];
		}
	}
	ksort($out);
	return $out;
}

/** Werte pro Uhrzeit-Stunde (0-23) eines Kalendertags; doppelte Stunde bei Zeitumstellung wird gemittelt. */
function ckw_day_series(array $hourly, DateTimeImmutable $day, $comp)
{
	$tz = new DateTimeZone(CKW_TZ);
	$ymd = $day->format('Y-m-d');
	$acc = array();
	foreach ($hourly as $h => $vals) {
		$local = (new DateTimeImmutable('@' . $h))->setTimezone($tz);
		if ($local->format('Y-m-d') !== $ymd) {
			continue;
		}
		$acc[(int)$local->format('G')][] = $vals[$comp];
	}
	$series = array();
	for ($i = 0; $i < 24; $i++) {
		$series[$i] = isset($acc[$i]) ? array_sum($acc[$i]) / count($acc[$i]) : null;
	}
	return $series;
}

/** Rollierende 24 Stunden ab laufender Stunde (relativ, wie Spotpreis-Optimierer +0..+23). */
function ckw_rel_series(array $hourly, $hourStart, $comp)
{
	$series = array();
	for ($i = 0; $i < 24; $i++) {
		$h = $hourStart + $i * 3600;
		$series[$i] = isset($hourly[$h]) ? $hourly[$h][$comp] : null;
	}
	return $series;
}

/** Fuellt fehlende Stunden, damit der Spotpreis-Optimierer sie nicht als guenstig einstuft. */
function ckw_fill_series(array $series, $mode, $fallback = null)
{
	$known = array_values(array_filter($series, function ($v) { return $v !== null; }));
	if (!$known) {
		// Ganze Reihe fehlt (z. B. morgen vor 12 Uhr) -> Rueckfallwert aus allen bekannten Stunden
		$v = ($mode === 'zero' || $fallback === null) ? 0.0 : $fallback;
		return array_fill(0, count($series), $v);
	}
	$max = max($known);
	$last = $known[0];
	foreach ($series as $i => $v) {
		if ($v !== null) {
			$last = $v;
			continue;
		}
		if ($mode === 'last') {
			$series[$i] = $last;
		} elseif ($mode === 'zero') {
			$series[$i] = 0.0;
		} else {
			$series[$i] = $max;
		}
	}
	return $series;
}

/** Guenstigster zusammenhaengender Block von $len Stunden in einer Reihe (nur echte Werte). */
function ckw_cheapest_block(array $series, $len)
{
	$best = null;
	$n = count($series);
	for ($s = 0; $s + $len <= $n; $s++) {
		$sum = 0;
		for ($i = $s; $i < $s + $len; $i++) {
			if ($series[$i] === null) {
				continue 2;
			}
			$sum += $series[$i];
		}
		$avg = $sum / $len;
		if ($best === null || $avg < $best['avg']) {
			$best = array('start' => $s, 'avg' => $avg);
		}
	}
	return $best;
}

/**
 * Fuellwerte aus dem CKW-Durchschnittspreis: fuer jede fehlende Stunde wird ein kuenstlicher Rohslot
 * (Netznutzung = CKW-Durchschnitt, fixe Zuschlaege und ClassicStrom vom juengsten echten Slot desselben
 * Jahres bzw. aus der Tabelle) gebaut und wie echte Daten durch ckw_compute_slots gerechnet.
 * $hours: Liste Unix-Stundenbeginn. Rueckgabe: array(ts => Komponenten) und Warnungen in $meta.
 */
function ckw_reference_fill(array $hours, array $raw, array $cfg, array $tariffs, &$meta)
{
	$meta = array('warnings' => array());
	if (!$hours) {
		return array();
	}
	$tz = new DateTimeZone(CKW_TZ);
	$latest = null;
	if ($raw) {
		$lastTs = max(array_keys($raw));
		$latest = array('year' => (new DateTimeImmutable('@' . $lastTs))->setTimezone($tz)->format('Y'), 'slot' => $raw[$lastTs]);
	}
	$synth = array();
	foreach ($hours as $h) {
		$ymd = (new DateTimeImmutable('@' . $h))->setTimezone($tz)->format('Y-m-d');
		$avg = ckw_reference($tariffs, $cfg['tariff'], $ymd);
		if ($avg === null) {
			return null;   // kein Durchschnittswert bekannt -> Aufrufer faellt auf Hoechstpreis zurueck
		}
		if ($avg['outdated']) {
			$meta['warnings']['avgoutdated'] = 'Für ' . substr($ymd, 0, 4) . ' ist noch kein CKW-Durchschnittspreis hinterlegt - verwende Wert gültig ab ' . $avg['valid_from'] . '.';
		}
		$sameYear = $latest && $latest['year'] === substr($ymd, 0, 4) && $latest['slot']['grid'] !== null && $latest['slot']['grid_usage'] !== null;
		if ($sameYear) {
			$fix = $latest['slot']['grid'] - $latest['slot']['grid_usage'];
		} else {
			$gf = ckw_reference($tariffs, 'grid_fix', $ymd);
			$fix = $gf ? $gf['rp'] / 100 : 0.0;
		}
		$elec = ($sameYear && $latest['slot']['electricity'] !== null) ? $latest['slot']['electricity'] : null;
		if ($elec === null) {
			$pp = ckw_product_price($tariffs, 'classic', $ymd);
			$elec = $pp ? $pp['rp'] / 100 : null;
		}
		$usage = $avg['rp'] / 100;
		$synth[$h] = array('grid_usage' => $usage, 'grid' => $usage + $fix, 'electricity' => $elec, 'integrated' => null);
	}
	$m = array();
	$slots = ckw_compute_slots($synth, $cfg, $tariffs, $m);
	$meta['warnings'] += $m['warnings'];
	return $slots;
}

/** Unix-Stundenbeginn der Uhrzeit-Stunde $hour an einem Kalendertag (null bei fehlender Stunde, Zeitumstellung) */
function ckw_day_hour_ts(DateTimeImmutable $day, $hour)
{
	$d = $day->setTime($hour, 0, 0);
	return (int)$d->format('G') === $hour ? $d->getTimestamp() : null;
}

/**
 * Kernfunktion: rechnet aus Rohdaten alle Ausgabewerte.
 * Rueckgabe: array mit 'now', 'series', 'stats', 'cheap', 'status' ...
 */
function ckw_calculate(array $raw, array $cfg, array $tariffs, DateTimeImmutable $now)
{
	global $CKW_COMPONENTS;
	$tz = new DateTimeZone(CKW_TZ);
	$now = $now->setTimezone($tz);
	$nowTs = $now->getTimestamp();
	$slotTs = $nowTs - ($nowTs % 900);
	$hourTs = $nowTs - ($nowTs % 3600);
	$today = $now->setTime(0, 0, 0);
	$tomorrow = $today->modify('+1 day');

	$meta = array();
	$slots = ckw_compute_slots($raw, $cfg, $tariffs, $meta);
	$hourly = ckw_hourly($slots);

	$res = array(
		'meta'   => $meta,
		'now'    => array(),
		'series' => array(),
		'stats'  => array(),
		'hourclock' => array(),
	);

	for ($i = 0; $i < 24; $i++) {
		$res['hourclock'][$i] = (int)(new DateTimeImmutable('@' . ($hourTs + $i * 3600)))->setTimezone($tz)->format('G');
	}

	foreach (array_keys($CKW_COMPONENTS) as $c) {
		$res['now'][$c] = isset($slots[$slotTs]) ? $slots[$slotTs][$c] : null;
		$rel = ckw_rel_series($hourly, $hourTs, $c);
		$res['series'][$c] = array(
			'rel' => $rel,
			'abs' => ckw_day_series($hourly, $today, $c),
			'tmr' => ckw_day_series($hourly, $tomorrow, $c),
		);
		// Kennzahlen ueber die naechsten 24 h (nur echte Werte)
		$known = array_filter($rel, function ($v) { return $v !== null; });
		if ($known) {
			$minOff = array_search(min($known), $rel, true);
			$maxOff = array_search(max($known), $rel, true);
			$res['stats'][$c] = array(
				'min' => $rel[$minOff], 'min_off' => $minOff, 'min_clock' => $res['hourclock'][$minOff],
				'max' => $rel[$maxOff], 'max_off' => $maxOff, 'max_clock' => $res['hourclock'][$maxOff],
				'avg' => array_sum($known) / count($known),
			);
		} else {
			$res['stats'][$c] = null;
		}
	}

	$relTotal = $res['series']['total']['rel'];
	$known = array_values(array_filter($relTotal, function ($v) { return $v !== null; }));
	$res['hours_avail'] = count($known);

	$block = ckw_cheapest_block($relTotal, $cfg['cheap_hours']);
	$res['cheap'] = $block === null ? null : array(
		'start_off'   => $block['start'],
		'start_clock' => $res['hourclock'][$block['start']],
		'avg'         => $block['avg'],
	);

	$res['rank_now'] = -1;
	$res['below_avg'] = 0;
	if ($relTotal[0] !== null && $known) {
		$sorted = $known;
		sort($sorted);
		$res['rank_now'] = array_search($relTotal[0], $sorted, true) + 1;
		$res['below_avg'] = $relTotal[0] < array_sum($known) / count($known) ? 1 : 0;
	}

	$res['data_valid'] = isset($slots[$slotTs]);

	// Fuellwerte (CKW-Durchschnittspreis) fuer alle Stunden ohne echte Daten vorberechnen
	$res['fill'] = null;
	if ($cfg['fill_mode'] === 'ckwavg') {
		$need = array();
		foreach ($res['series']['total']['rel'] as $i => $v) {
			if ($v === null) {
				$need['rel'][$i] = $hourTs + $i * 3600;
			}
		}
		foreach (array('abs' => $today, 'tmr' => $tomorrow) as $kind => $day) {
			foreach ($res['series']['total'][$kind] as $i => $v) {
				$ts = ckw_day_hour_ts($day, $i);
				if ($v === null && $ts !== null) {
					$need[$kind][$i] = $ts;
				}
			}
		}
		$all = array();
		foreach ($need as $list) {
			$all = array_merge($all, array_values($list));
		}
		$fm = array();
		$fillSlots = ckw_reference_fill(array_unique($all), $raw, $cfg, $tariffs, $fm);
		if ($fillSlots !== null) {
			$res['fill'] = array();
			foreach (array_keys($CKW_COMPONENTS) as $c) {
				foreach ($need as $kind => $list) {
					foreach ($list as $i => $ts) {
						$res['fill'][$c][$kind][$i] = $fillSlots[$ts][$c];
					}
				}
			}
			$res['meta']['warnings'] += $fm['warnings'];
		} else {
			$res['meta']['warnings']['noavg'] = 'Kein CKW-Durchschnittspreis bekannt - fehlende Stunden werden mit dem Höchstpreis gefüllt.';
		}
	}
	return $res;
}

// ---------------------------------------------------------------------------
// Ausgabewerte fuer Loxone
// ---------------------------------------------------------------------------

function ckw_local_time($ts, $format = 'd.m.Y H:i')
{
	return (new DateTimeImmutable('@' . (int)$ts))->setTimezone(new DateTimeZone(CKW_TZ))->format($format);
}

function ckw_fmt($v, $cfg)
{
	$dec = $cfg['unit'] === 'rp' ? 3 : 5;
	return number_format((float)$v, $dec, '.', '');
}

/**
 * Baut die Schluessel/Werte, die per MQTT (<topic>/<key>) und UDP (key=wert) gesendet werden.
 * Rueckgabe: array('values' => numerisch, 'texts' => nur MQTT)
 */
function ckw_build_outputs(array $res, array $cfg, array $status)
{
	global $CKW_COMPONENTS;
	$v = array();

	foreach (array_keys($CKW_COMPONENTS) as $c) {
		$v[$c . '_now'] = $res['now'][$c] !== null ? ckw_fmt($res['now'][$c], $cfg) : '-1';
	}

	foreach ($cfg['components'] as $c) {
		$all = array_filter(array_merge($res['series'][$c]['rel'], $res['series'][$c]['abs'], $res['series'][$c]['tmr']), function ($x) { return $x !== null; });
		$fallback = $all ? max($all) : null;
		$fill = function ($kind) use ($res, $c, $cfg, $fallback) {
			$series = $res['series'][$c][$kind];
			if ($cfg['fill_mode'] === 'ckwavg' && $res['fill'] !== null) {
				foreach ($series as $i => $x) {
					if ($x === null && isset($res['fill'][$c][$kind][$i])) {
						$series[$i] = $res['fill'][$c][$kind][$i];
					}
				}
			}
			return ckw_fill_series($series, $cfg['fill_mode'] === 'ckwavg' ? 'max' : $cfg['fill_mode'], $fallback);
		};
		$rel = $fill('rel');
		$v[$c . '_nexthour'] = ckw_fmt($rel[1], $cfg);
		if ($cfg['series_rel']) {
			foreach ($rel as $i => $x) {
				$v[sprintf('%s_rel%02d', $c, $i)] = ckw_fmt($x, $cfg);
			}
		}
		if ($cfg['series_abs']) {
			foreach ($fill('abs') as $i => $x) {
				$v[sprintf('%s_abs%02d', $c, $i)] = ckw_fmt($x, $cfg);
			}
		}
		if ($cfg['series_tmr']) {
			foreach ($fill('tmr') as $i => $x) {
				$v[sprintf('%s_tmr%02d', $c, $i)] = ckw_fmt($x, $cfg);
			}
		}
		$st = $res['stats'][$c];
		$v[$c . '_min']      = $st ? ckw_fmt($st['min'], $cfg) : '-1';
		$v[$c . '_minclock'] = $st ? (string)$st['min_clock'] : '-1';
		$v[$c . '_max']      = $st ? ckw_fmt($st['max'], $cfg) : '-1';
		$v[$c . '_maxclock'] = $st ? (string)$st['max_clock'] : '-1';
		$v[$c . '_avg']      = $st ? ckw_fmt($st['avg'], $cfg) : '-1';
	}

	$v['cheap_start_off']   = $res['cheap'] ? (string)$res['cheap']['start_off'] : '-1';
	$v['cheap_start_clock'] = $res['cheap'] ? (string)$res['cheap']['start_clock'] : '-1';
	$v['cheap_avg']         = $res['cheap'] ? ckw_fmt($res['cheap']['avg'], $cfg) : '-1';
	$v['rank_now']          = (string)$res['rank_now'];
	$v['below_avg']         = (string)$res['below_avg'];
	$v['hours_avail']       = (string)$res['hours_avail'];
	$v['filled_hours']      = (string)(24 - $res['hours_avail']);

	$v['online']          = ($status['api_ok'] && $res['data_valid']) ? '1' : '0';
	$v['api_ok']          = $status['api_ok'] ? '1' : '0';
	$v['data_valid']      = $res['data_valid'] ? '1' : '0';
	$v['data_age_min']    = (string)$status['data_age_min'];
	$v['last_update']     = (string)$status['last_update'];
	$v['last_update_lox'] = (string)($status['last_update'] - CKW_LOX_EPOCH_OFFSET);

	$texts = array(
		'last_update_text' => $status['last_update'] ? ckw_local_time($status['last_update']) : '',
		'price_source'     => $res['meta']['energy_source'] . ($res['meta']['energy_valid_from'] ? ' (gültig ab ' . $res['meta']['energy_valid_from'] . ')' : ''),
		'price_warning'    => implode(' ', $res['meta']['warnings']),
	);
	return array('values' => $v, 'texts' => $texts);
}

/** Kurzbeschreibung eines Ausgabewerts (Weboberflaeche, Loxone-Vorlage) */
function ckw_key_description($key, $unit = 'CHF/kWh')
{
	global $CKW_COMPONENTS;
	$short = array(
		'total' => 'Total', 'integrated' => 'CKW integrated', 'grid' => 'Netz gesamt',
		'gridusage' => 'Netznutzung dynamisch', 'gridfix' => 'Netz fixe Zuschläge', 'energy' => 'Energie',
		'concession' => 'Konzessionsabgabe',
	);
	$fixed = array(
		'cheap_start_off'   => 'Günstigstes Zeitfenster: Start in Stunden ab jetzt (-1 = zu wenig Daten)',
		'cheap_start_clock' => 'Günstigstes Zeitfenster: Startzeit (Stunde 0-23)',
		'cheap_avg'         => "Günstigstes Zeitfenster: Durchschnittspreis ($unit)",
		'rank_now'          => 'Rang der aktuellen Stunde in den nächsten 24 h (1 = günstigste)',
		'below_avg'         => '1 = aktuelle Stunde ist günstiger als der Durchschnitt',
		'hours_avail'       => 'Anzahl Stunden mit echten CKW-Preisen (von 24)',
		'filled_hours'      => 'Anzahl aufgefüllter Stunden (noch keine CKW-Preise)',
		'online'            => '1 = Abruf bei CKW ok und aktueller Preis vorhanden, 0 = Störung',
		'api_ok'            => '1 = letzter Aufruf der CKW-API erfolgreich',
		'data_valid'        => '1 = Preis für die aktuelle Viertelstunde vorhanden',
		'data_age_min'      => 'Alter des letzten erfolgreichen Abrufs in Minuten',
		'last_update'       => 'Letzter erfolgreicher Abruf (Unix-Zeit)',
		'last_update_lox'   => 'Letzter erfolgreicher Abruf (Loxone-Zeit, für Watchdog)',
		'last_update_text'  => 'Letzter erfolgreicher Abruf als Text',
		'price_source'      => 'Quelle des Energiepreises',
		'price_warning'     => 'Hinweis, z. B. fehlender Jahrespreis (leer = alles ok)',
	);
	if (isset($fixed[$key])) {
		return $fixed[$key];
	}
	if (!preg_match('/^([a-z]+)_([a-z]+?)(\d{2})?$/', $key, $m) || !isset($short[$m[1]])) {
		return '';
	}
	$c = $short[$m[1]];
	$n = isset($m[3]) ? (int)$m[3] : null;
	switch ($m[2]) {
		case 'now':      return "$c: Preis der aktuellen Viertelstunde ($unit)";
		case 'nexthour': return "$c: Durchschnittspreis der nächsten Stunde ($unit)";
		case 'rel':      return sprintf('%s: Stundenpreis in %d h (Spotpreis-Optimierer +%d, %s)', $c, $n, $n, $unit);
		case 'abs':      return sprintf('%s: Stundenpreis heute %02d:00-%02d:00 (%s)', $c, $n, ($n + 1) % 24, $unit);
		case 'tmr':      return sprintf('%s: Stundenpreis morgen %02d:00-%02d:00 (%s)', $c, $n, ($n + 1) % 24, $unit);
		case 'min':      return "$c: tiefster Stundenpreis der nächsten 24 h ($unit)";
		case 'max':      return "$c: höchster Stundenpreis der nächsten 24 h ($unit)";
		case 'avg':      return "$c: Durchschnitt der nächsten 24 h ($unit)";
		case 'minclock': return "$c: Uhrzeit (Stunde) des tiefsten Preises";
		case 'maxclock': return "$c: Uhrzeit (Stunde) des höchsten Preises";
	}
	return '';
}

/**
 * Prueft, dass kein Schluessel am Ende eines anderen steht - sonst wuerde die
 * Loxone-Befehlserkennung "key=\v" im UDP-Paket den falschen Wert treffen.
 */
function ckw_key_collisions(array $keys)
{
	$hits = array();
	foreach ($keys as $a) {
		foreach ($keys as $b) {
			if ($a !== $b && substr($b, -strlen($a)) === $a) {
				$hits[] = "$a / $b";
			}
		}
	}
	return $hits;
}

// ---------------------------------------------------------------------------
// Versand
// ---------------------------------------------------------------------------

/**
 * Publiziert retained an den in LoxBerry konfigurierten Broker.
 * Weg 1: direkte Verbindung (Zugangsdaten aus dem MQTT-Widget).
 * Weg 2 (Rueckfall): HTTP-Schnittstelle des LoxBerry-MQTT-Gateways auf localhost.
 * $messages: topic => payload. Leerer Payload loescht ein retained Topic.
 * $via erhaelt 'direkt' oder 'gateway'.
 */
function ckw_mqtt_send(array $messages, &$error, &$via = null)
{
	$error = null;
	$via = null;
	$directErr = null;
	if (ckw_mqtt_send_direct($messages, $directErr)) {
		$via = 'direkt';
		return true;
	}
	$gwErr = null;
	if (ckw_mqtt_send_gateway($messages, $gwErr)) {
		$via = 'gateway';
		$error = $directErr;   // zur Info: warum die direkte Verbindung nicht ging
		return true;
	}
	$error = "Direkt: $directErr / MQTT-Gateway: $gwErr";
	return false;
}

function ckw_mqtt_send_direct(array $messages, &$error)
{
	$error = null;
	$cred = mqtt_connectiondetails();
	if (empty($cred['brokerhost'])) {
		$error = 'keine Broker-Daten in der LoxBerry-Konfiguration (MQTT-Widget prüfen)';
		return false;
	}
	require_once 'phpMQTT/phpMQTT.php';
	$port = !empty($cred['tls']) ? $cred['tls_brokerport'] : $cred['brokerport'];
	$client = new Bluerhinos\phpMQTT($cred['brokerhost'], $port, 'ckwdyn_' . substr(uniqid(), -10));
	if (!empty($cred['tls'])) {
		if (empty($cred['tls_verify'])) {
			$client->set_ssl_options(array('verify_peer' => false, 'verify_peer_name' => false));
		} elseif (!empty($cred['tls_cafile']) && file_exists($cred['tls_cafile'])) {
			$client->set_ssl_options(array('verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $cred['tls_cafile']));
		}
	}
	$user = !empty($cred['brokeruser']) ? $cred['brokeruser'] : null;
	$pass = !empty($cred['brokerpass']) ? $cred['brokerpass'] : null;
	if (!@$client->connect(true, null, $user, $pass)) {
		$error = "Verbindung zu {$cred['brokerhost']}:$port fehlgeschlagen" . ($user ? " (Benutzer $user)" : ' (ohne Benutzer)');
		return false;
	}
	foreach ($messages as $topic => $payload) {
		$client->publish($topic, (string)$payload, 0, true);
	}
	$client->close();
	return true;
}

/** Rueckfall: JSON-POST an mqtt.php des MQTT-Gateways (lokal ohne Login, siehe LoxBerry-Wiki) */
function ckw_mqtt_send_gateway(array $messages, &$error)
{
	global $webserverport;
	$error = null;
	if (class_exists('LBSystem')) {
		LBSystem::read_generaljson();
	}
	$port = !empty($webserverport) ? (int)$webserverport : 80;
	$items = array();
	foreach ($messages as $topic => $payload) {
		$item = array('topic' => $topic, 'retain' => true);
		if ((string)$payload !== '') {
			$item['value'] = (string)$payload;   // ohne value wird das retained Topic geloescht
		}
		$items[] = $item;
	}
	$url = "http://localhost:$port/admin/system/tools/mqtt.php";
	$body = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 20,
		));
		$resp = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$cerr = curl_error($ch);
	} else {
		$ctx = stream_context_create(array('http' => array('method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 20, 'ignore_errors' => true)));
		$resp = @file_get_contents($url, false, $ctx);
		$headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ckw_legacy_headers(get_defined_vars());
		$code = (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) ? (int)$m[1] : 0;
		$cerr = $resp === false ? 'keine Antwort' : '';
	}
	if ($resp === false || $code !== 200) {
		$error = "$url antwortet nicht mit 200 (" . ($code ?: $cerr) . ')';
		return false;
	}
	return true;
}

/**
 * Baut die UDP-Pakete selbst ("praefix: key=wert key=wert ...", max. 220 Zeichen).
 * msudp_send() aus dem LoxBerry-SDK laesst das "=" weg (globale $udp_delimiter wird in der
 * Funktion nicht importiert) - deshalb wird nur der Rohstring-Modus des SDK verwendet.
 */
function ckw_udp_packets(array $values, $prefix)
{
	$head = $prefix !== '' ? "$prefix: " : '';
	$packets = array();
	$line = '';
	foreach ($values as $k => $v) {
		$pair = "$k=$v ";
		if ($line !== '' && strlen($head . $line . $pair) > 220) {
			$packets[] = rtrim($head . $line);
			$line = '';
		}
		$line .= $pair;
	}
	if ($line !== '') {
		$packets[] = rtrim($head . $line);
	}
	return $packets;
}

function ckw_udp_send(array $values, array $cfg, &$error)
{
	$error = null;
	foreach (ckw_udp_packets($values, $cfg['udp_prefix']) as $packet) {
		if (msudp_send($cfg['udp_msnr'], $cfg['udp_port'], '', $packet) !== 'OK') {
			$error = "UDP-Versand an Miniserver {$cfg['udp_msnr']} Port {$cfg['udp_port']} fehlgeschlagen.";
			return false;
		}
	}
	return true;
}

/** true fuer Werte, die ein Preis sind (Einheit <v.3>), sonst ganze Zahl / Status (<v>) */
function ckw_key_is_price($key)
{
	global $CKW_COMPONENTS;
	if ($key === 'cheap_avg') {
		return true;
	}
	// nur echte Komponenten (sonst wuerde z. B. rank_now als Preis gelten)
	return preg_match('/^([a-z]+)_(now|nexthour|min|max|avg|rel\d{2}|abs\d{2}|tmr\d{2})$/', $key, $m) === 1 && isset($CKW_COMPONENTS[$m[1]]);
}

/**
 * Loxone-Vorlage "Virtueller UDP-Eingang" mit Einheit pro Befehl.
 * (Eigene Ausgabe, da der LoxBerry-Template-Builder kein Unit-Attribut kennt.)
 */
function ckw_udp_template_xml(array $keys, $port, $unit = 'CHF/kWh')
{
	$e = function ($t) { return htmlspecialchars((string)$t, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
	$digital = array('online', 'api_ok', 'data_valid', 'below_avg');
	$x = '<?xml version="1.0" encoding="utf-8"?>' . "\r\n";
	$x .= '<VirtualInUdp Title="CKW Dynamischer Tarif" Comment="LoxBerry-Plugin ckwdynamic" Address="" Port="' . (int)$port . '">' . "\r\n";
	foreach ($keys as $k) {
		$x .= "\t" . '<VirtualInUdpCmd Title="' . $e('CKW ' . $k) . '" Comment="' . $e(ckw_key_description($k, $unit)) . '" Address=""'
			. ' Check="' . $e($k . '=\v') . '" Signed="true" Analog="' . (in_array($k, $digital, true) ? 'false' : 'true') . '"'
			. ' SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="-2147483647" MaxVal="2147483647"'
			. ' Unit="' . $e(ckw_key_is_price($k) ? '<v.3>' : '<v>') . '"/>' . "\r\n";
	}
	return $x . '</VirtualInUdp>' . "\r\n";
}

// ---------------------------------------------------------------------------
// Ablauf eines Abrufs (vom Cronjob und von der Weboberflaeche verwendet)
// ---------------------------------------------------------------------------

function ckw_log($log, $level, $msg)
{
	if (is_object($log) && is_callable(array($log, $level))) {
		$log->$level($msg);
	}
}

/**
 * Kompletter Lauf: Preistabelle, API (mit Cache-Rueckfall), Berechnung, Versand, Statusdatei.
 * Gibt true zurueck, wenn aktuelle Daten vorliegen und alle aktiven Ausgaben funktionierten.
 */
function ckw_run(array $cfg, $log = null, ?DateTimeImmutable $now = null)
{
	$now = $now ?: new DateTimeImmutable('now', new DateTimeZone(CKW_TZ));
	$ok = true;

	$tariffs = ckw_load_tariffs($cfg['tariffs_remote'], $log);

	// API abrufen, bei Fehler auf den letzten Abruf zurueckfallen
	$err = null;
	$prices = ckw_fetch_api($cfg['tariff'], $now, $err, $log);
	$cache = ckw_read_json(ckw_cache_file());
	$apiOk = $prices !== null;
	if ($apiOk) {
		$cache = array('fetched' => time(), 'tariff' => $cfg['tariff'], 'prices' => $prices);
		ckw_write_json(ckw_cache_file(), $cache);
		ckw_log($log, 'OK', count($prices) . ' Preis-Slots von CKW erhalten.');
	} else {
		ckw_log($log, 'ERR', "CKW-API nicht erreichbar: $err");
		if ($cache && $cache['tariff'] === $cfg['tariff']) {
			$prices = $cache['prices'];
			ckw_log($log, 'WARN', 'Verwende letzten erfolgreichen Abruf vom ' . ckw_local_time($cache['fetched']) . '.');
		} else {
			$prices = array();
		}
		$ok = false;
	}

	$lastUpdate = ($cache && $cache['tariff'] === $cfg['tariff']) ? (int)$cache['fetched'] : 0;
	$status = array(
		'api_ok'       => $apiOk,
		'last_update'  => $lastUpdate,
		'data_age_min' => $lastUpdate ? (int)floor((time() - $lastUpdate) / 60) : -1,
	);

	$res = ckw_calculate(ckw_parse_slots($prices), $cfg, $tariffs['data'], $now);
	foreach ($res['meta']['warnings'] as $w) {
		ckw_log($log, 'WARN', $w);
	}
	if (!$res['data_valid']) {
		ckw_log($log, 'ERR', 'Kein Preis für die aktuelle Viertelstunde vorhanden.');
		$ok = false;
	}
	$out = ckw_build_outputs($res, $cfg, $status);
	ckw_log($log, 'INF', 'Aktuell total: ' . $out['values']['total_now'] . ' ' . ($cfg['unit'] === 'rp' ? 'Rp.' : 'CHF') . '/kWh, Stunden mit Daten: ' . $res['hours_avail'] . '/24');

	// Versand
	$sent = array('mqtt' => null, 'udp' => null);
	$errors = array();
	$published = ckw_read_json(ckw_published_file());
	if ($cfg['mqtt_enabled']) {
		$base = $cfg['mqtt_topic'];
		$msgs = array();
		foreach ($out['values'] + $out['texts'] as $k => $val) {
			$msgs["$base/$k"] = $val;
		}
		$msgs["$base/data/json"] = json_encode(ckw_state_payload($res, $out, $cfg), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		// Nicht mehr verwendete retained Topics (z. B. nach Aenderung der Auswahl) loeschen
		if ($published && !empty($published['topics'])) {
			foreach ($published['topics'] as $t) {
				if (!isset($msgs[$t])) {
					$msgs[$t] = '';
				}
			}
		}
		$mqttErr = null;
		$via = null;
		$sent['mqtt'] = ckw_mqtt_send($msgs, $mqttErr, $via);
		if ($sent['mqtt']) {
			if ($via === 'gateway') {
				ckw_log($log, 'WARN', "Direkte MQTT-Verbindung nicht möglich ($mqttErr) - über das MQTT-Gateway gesendet.");
			}
			ckw_log($log, 'OK', count($msgs) . " MQTT-Topics unter $base/ publiziert ($via).");
			ckw_write_json(ckw_published_file(), array('topics' => array_keys(array_filter($msgs, function ($p) { return $p !== ''; }))));
		} else {
			ckw_log($log, 'ERR', "MQTT-Versand fehlgeschlagen - $mqttErr");
			$errors['mqtt'] = $mqttErr;
			$ok = false;
		}
	} elseif ($published && !empty($published['topics'])) {
		// MQTT wurde deaktiviert -> alte retained Werte entfernen
		$mqttErr = null;
		if (ckw_mqtt_send(array_fill_keys($published['topics'], ''), $mqttErr)) {
			ckw_write_json(ckw_published_file(), array('topics' => array()));
		}
	}
	if ($cfg['udp_enabled']) {
		$udpErr = null;
		$sent['udp'] = ckw_udp_send($out['values'], $cfg, $udpErr);
		if ($sent['udp']) {
			ckw_log($log, 'OK', count($out['values']) . " Werte per UDP an Miniserver {$cfg['udp_msnr']}:{$cfg['udp_port']} gesendet.");
		} else {
			ckw_log($log, 'ERR', $udpErr);
			$errors['udp'] = $udpErr;
			$ok = false;
		}
	}

	$state = ckw_state_payload($res, $out, $cfg);
	$state['run'] = array(
		'time'   => time(),
		'ok'     => $ok,
		'api_ok' => $apiOk,
		'error'  => $err,
		'sent'   => $sent,
		'errors' => $errors,
		'tariffs_source'  => $tariffs['source'],
		'tariffs_updated' => $tariffs['updated'],
	);
	ckw_write_json(ckw_state_file(), $state);
	return $ok;
}

/** Rundet Gleitkommazahlen rekursiv (vermeidet 0.030299999999999994 im JSON) */
function ckw_round_deep($v)
{
	if (is_array($v)) {
		return array_map('ckw_round_deep', $v);
	}
	return is_float($v) ? round($v, 6) : $v;
}

/** Strukturierte Daten fuer Weboberflaeche und <topic>/data/json */
function ckw_state_payload(array $res, array $out, array $cfg)
{
	return ckw_round_deep(array(
		'unit'       => $cfg['unit'] === 'rp' ? 'Rp./kWh' : 'CHF/kWh',
		'vat'        => $cfg['vat_enabled'] ? $cfg['vat_rate'] : 0,
		'tariff'     => $cfg['tariff'],
		'product'    => $cfg['product'],
		'now'        => $res['now'],
		'hourclock'  => $res['hourclock'],
		'series'     => $res['series'],
		'stats'      => $res['stats'],
		'cheap'      => $res['cheap'],
		'fill'       => $res['fill'],
		'fill_mode'  => $cfg['fill_mode'],
		'hours_avail'=> $res['hours_avail'],
		'meta'       => $res['meta'],
		'values'     => $out['values'],
		'texts'      => $out['texts'],
	));
}
