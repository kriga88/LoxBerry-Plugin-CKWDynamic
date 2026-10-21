<?php
/**
 * CKW Dynamischer Tarif - Verbrauchsstatistik.
 *
 * Pro Lauf (alle 15 Minuten) wird der Zaehlerstand Bezug gelesen. Die Differenz zum letzten Stand ist der
 * Verbrauch der abgelaufenen Viertelstunde(n) und wird mit dem Preis genau dieser Viertelstunde bewertet.
 *
 * Schreiblast: Neue Werte sammeln sich in einem Puffer in der RAM-Disk und werden einmal pro Stunde in
 * einer Transaktion in die SQLite-Datenbank (Datenordner, SD-Karte) geschrieben. Faellt der Strom aus,
 * geht hoechstens die Feinaufloesung der laufenden Stunde verloren - die kWh erfasst die naechste
 * Zaehlerdifferenz (gleichmaessig verteilt, als "geschaetzt" markiert).
 *
 * Alle Preise in CHF/kWh wie eingestellt (inkl. MwSt, falls aktiviert), Kosten in CHF.
 */

define('CKW_SLOT', 900);

function ckw_stats_db_file()     { return ckw_data_dir() . '/statistics.sqlite'; }
function ckw_stats_buffer_file() { return ckw_ram_dir() . '/stats_buffer.json'; }

/** Oeffnet (und erstellt bei Bedarf) die Statistik-Datenbank. */
function ckw_stats_db()
{
	static $db = null;
	if ($db !== null) {
		return $db;
	}
	if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
		throw new Exception('PHP-Erweiterung pdo_sqlite fehlt (Paket php-sqlite3).');
	}
	$db = new PDO('sqlite:' . ckw_stats_db_file());
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec('PRAGMA synchronous = NORMAL');
	$db->exec('CREATE TABLE IF NOT EXISTS prices (ts INTEGER PRIMARY KEY, total REAL, fixed REAL)');
	$db->exec('CREATE TABLE IF NOT EXISTS readings (ts INTEGER PRIMARY KEY, meter REAL NOT NULL)');
	$db->exec('CREATE TABLE IF NOT EXISTS usage (ts INTEGER PRIMARY KEY, kwh REAL NOT NULL, cost REAL, cost_fixed REAL, estimated INTEGER NOT NULL DEFAULT 0)');
	return $db;
}

function ckw_stats_enabled(array $cfg)
{
	return $cfg['meter_source'] !== 'off';
}

// ---------------------------------------------------------------------------
// Zaehlerstand lesen
// ---------------------------------------------------------------------------

/** Erste Zahl aus einem Text ("12345.6kWh", "12'345,6") als float, sonst null */
function ckw_stats_parse_number($v)
{
	if ($v === null || $v === false) {
		return null;
	}
	$v = str_replace(array("'", ' '), '', (string)$v);
	if (!preg_match('/-?\d+(?:[.,]\d+)?/', $v, $m)) {
		return null;
	}
	return (float)str_replace(',', '.', $m[0]);
}

/**
 * Liest den Zaehlerstand Bezug in kWh (Miniserver oder MQTT). Rueckgabe float oder null + $error.
 */
function ckw_stats_read_meter(array $cfg, &$error)
{
	$error = null;
	$raw = null;
	if ($cfg['meter_source'] === 'miniserver') {
		if ($cfg['meter_name'] === '') {
			$error = 'Kein Name für den Zählerstand am Miniserver eingetragen.';
			return null;
		}
		$raw = @mshttp_get($cfg['meter_msnr'], $cfg['meter_name']);
		if ($raw === null || $raw === false || $raw === '') {
			$error = "Miniserver {$cfg['meter_msnr']} liefert keinen Wert für \"{$cfg['meter_name']}\" (Name prüfen, Benutzerrechte des LoxBerry-Benutzers am Baustein?).";
			return null;
		}
	} elseif ($cfg['meter_source'] === 'mqtt') {
		if ($cfg['meter_topic'] === '') {
			$error = 'Kein MQTT-Topic für den Zählerstand eingetragen.';
			return null;
		}
		$raw = @mqtt_get($cfg['meter_topic'], 2000);
		if ($raw === null || $raw === false || $raw === '') {
			$error = "Kein Wert auf dem MQTT-Topic \"{$cfg['meter_topic']}\" (Loxone muss den Zählerstand mit retain senden).";
			return null;
		}
	} else {
		$error = 'Statistik ist ausgeschaltet.';
		return null;
	}
	$v = ckw_stats_parse_number($raw);
	if ($v === null) {
		$error = 'Wert "' . substr((string)$raw, 0, 40) . '" ist keine Zahl.';
		return null;
	}
	if ($cfg['meter_unit'] === 'wh') {
		$v /= 1000;
	}
	return $v;
}

// ---------------------------------------------------------------------------
// Puffer (RAM-Disk)
// ---------------------------------------------------------------------------

function ckw_stats_buffer_load()
{
	$b = ckw_read_json(ckw_stats_buffer_file());
	if (!$b) {
		$b = array();
	}
	return $b + array('last_ts' => null, 'last_meter' => null, 'flushed_hour' => null, 'usage' => array(), 'readings' => array(), 'prices' => array());
}

function ckw_stats_buffer_save(array $b)
{
	ckw_write_json(ckw_stats_buffer_file(), $b);
}

/** Letzter bekannter Zaehlerstand: Puffer, sonst Datenbank (z. B. nach einem Neustart) */
function ckw_stats_last_reading(array $b)
{
	if ($b['last_ts'] !== null) {
		return array((int)$b['last_ts'], (float)$b['last_meter']);
	}
	$row = ckw_stats_db()->query('SELECT ts, meter FROM readings ORDER BY ts DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
	return $row ? array((int)$row['ts'], (float)$row['meter']) : array(null, null);
}

/** Preis eines Slots: Puffer, sonst Datenbank. Rueckgabe array(total, fixed) mit null fuer unbekannt */
function ckw_stats_slot_price(array $b, $ts)
{
	if (isset($b['prices'][$ts])) {
		return $b['prices'][$ts];
	}
	$st = ckw_stats_db()->prepare('SELECT total, fixed FROM prices WHERE ts = ?');
	$st->execute(array($ts));
	$row = $st->fetch(PDO::FETCH_ASSOC);
	return $row ? array($row['total'] !== null ? (float)$row['total'] : null, $row['fixed'] !== null ? (float)$row['fixed'] : null) : array(null, null);
}

/** Schreibt alle abgeschlossenen Eintraege des Puffers in einer Transaktion in die Datenbank. */
function ckw_stats_flush(array &$b, $untilTs = null)
{
	$untilTs = $untilTs === null ? PHP_INT_MAX : $untilTs;
	$db = ckw_stats_db();
	$db->beginTransaction();
	$pu = $db->prepare('INSERT OR REPLACE INTO usage (ts, kwh, cost, cost_fixed, estimated) VALUES (?, ?, ?, ?, ?)');
	$pr = $db->prepare('INSERT OR REPLACE INTO readings (ts, meter) VALUES (?, ?)');
	$pp = $db->prepare('INSERT OR REPLACE INTO prices (ts, total, fixed) VALUES (?, ?, ?)');
	$n = 0;
	foreach ($b['usage'] as $ts => $u) {
		if ($ts < $untilTs) {
			$pu->execute(array((int)$ts, $u[0], $u[1], $u[2], $u[3]));
			unset($b['usage'][$ts]);
			$n++;
		}
	}
	foreach ($b['readings'] as $ts => $m) {
		if ($ts <= $untilTs) {
			$pr->execute(array((int)$ts, $m));
			unset($b['readings'][$ts]);
		}
	}
	// Preise vergangener Viertelstunden archivieren (kuenftige bleiben im Puffer, sie koennen sich noch aendern)
	foreach ($b['prices'] as $ts => $p) {
		if ($ts < $untilTs) {
			$pp->execute(array((int)$ts, $p[0], $p[1]));
			unset($b['prices'][$ts]);
		}
	}
	$db->commit();
	return $n;
}

// ---------------------------------------------------------------------------
// Lauf
// ---------------------------------------------------------------------------

/**
 * Wird von ckw_run() alle 15 Minuten aufgerufen.
 * $slotPrices: Unix-Slotbeginn => array(total CHF/kWh, fixed CHF/kWh oder null) aller bekannten Slots.
 * Rueckgabe: array('meter_ok' => bool, 'error' => string|null, 'new_kwh' => float)
 */
function ckw_stats_run(array $cfg, array $slotPrices, $nowTs, $log = null)
{
	$info = array('meter_ok' => false, 'meter' => null, 'error' => null, 'new_kwh' => 0.0);
	$b = ckw_stats_buffer_load();

	// Preise merken - nur ab der zuletzt geschriebenen Stunde, aeltere liegen bereits in der Datenbank
	// (erster Lauf: bis 2 Tage zurueck, damit die Tageskurve sofort Preise zeigt)
	$minTs = $b['flushed_hour'] !== null ? (int)$b['flushed_hour'] : $nowTs - 2 * 86400;
	foreach ($slotPrices as $ts => $p) {
		if ($ts >= $minTs) {
			$b['prices'][$ts] = $p;
		}
	}

	$err = null;
	$meter = ckw_stats_read_meter($cfg, $err);
	if ($meter === null) {
		$info['error'] = $err;
		ckw_log($log, 'WARN', "Statistik: Zählerstand nicht gelesen - $err");
	} else {
		$info['meter_ok'] = true;
		$info['meter'] = $meter;
		// Ablesezeitpunkt auf die naechste Viertelstundengrenze runden (Cron startet wenige Sekunden danach).
		// Laeufe weit weg von der Grenze (manueller Abruf in der Weboberflaeche) werden nicht verbucht.
		$ts = (int)(round($nowTs / CKW_SLOT) * CKW_SLOT);
		list($lastTs, $lastMeter) = ckw_stats_last_reading($b);
		if (abs($nowTs - $ts) > 180) {
			ckw_log($log, 'DEB', 'Statistik: Lauf ausserhalb des Viertelstundenrasters - Zählerstand nicht verbucht.');
		} elseif ($lastTs === null || $ts > $lastTs) {
			if ($lastTs !== null) {
				$diff = $meter - $lastMeter;
				$slots = (int)(($ts - $lastTs) / CKW_SLOT);
				if ($diff < -0.0005) {
					ckw_log($log, 'WARN', sprintf('Statistik: Zählerstand gesunken (%.3f → %.3f kWh) - Zählertausch/Reset? Viertelstunde verworfen.', $lastMeter, $meter));
				} elseif ($diff > $cfg['meter_max_kwh'] * $slots) {
					ckw_log($log, 'WARN', sprintf('Statistik: unplausibler Verbrauch %.3f kWh in %d Viertelstunde(n) - verworfen.', $diff, $slots));
				} else {
					$diff = max(0.0, $diff);
					$per = $diff / $slots;
					for ($s = $lastTs; $s < $ts; $s += CKW_SLOT) {
						list($total, $fixed) = ckw_stats_slot_price($b, $s);
						$b['usage'][$s] = array(
							round($per, 6),
							$total !== null ? round($per * $total, 6) : null,
							$fixed !== null ? round($per * $fixed, 6) : null,
							$slots > 1 ? 1 : 0,
						);
					}
					$info['new_kwh'] = $diff;
					if ($slots > 1) {
						ckw_log($log, 'INF', "Statistik: Lücke von $slots Viertelstunden - Verbrauch gleichmässig verteilt (geschätzt).");
					}
				}
			}
			$b['readings'][$ts] = $meter;
			$b['last_ts'] = $ts;
			$b['last_meter'] = $meter;
		}
	}

	// Stuendlich in die Datenbank schreiben (erster Lauf einer neuen Stunde)
	$hour = (int)($nowTs - ($nowTs % 3600));
	if ($b['flushed_hour'] === null || $hour > $b['flushed_hour']) {
		try {
			$n = ckw_stats_flush($b, $hour);
			$b['flushed_hour'] = $hour;
			ckw_log($log, 'DEB', "Statistik: $n Viertelstunden in die Datenbank geschrieben.");
		} catch (Exception $e) {
			ckw_log($log, 'ERR', 'Statistik: Datenbank nicht beschreibbar - ' . $e->getMessage());
		}
	}
	ckw_stats_buffer_save($b);
	return $info;
}

/**
 * Slot-Preise fuer die Statistik in CHF/kWh (unabhaengig von der Anzeigeeinheit, MwSt wie eingestellt):
 * Unix-Slotbeginn => array(total, fixed). fixed = null, wenn fuer das Jahr kein Fixtarif bekannt ist.
 */
function ckw_stats_slot_prices(array $raw, array $cfg, array $tariffs)
{
	$cfg['unit'] = 'chf';
	$m = array();
	$slots = ckw_compute_slots($raw, $cfg, $tariffs, $m);
	$out = array();
	foreach ($slots as $ts => $v) {
		$fx = ckw_fixed_price($raw, $cfg, $tariffs, $ts);
		$out[$ts] = array(round($v['total'], 6), ($fx !== null && !$fx['outdated']) ? round($fx['values']['total'], 6) : null);
	}
	return $out;
}

/** Puffer sofort vollstaendig schreiben (vor Plugin-Update / Deinstallation) */
function ckw_stats_flush_all($log = null)
{
	$b = ckw_stats_buffer_load();
	$n = ckw_stats_flush($b, PHP_INT_MAX - 1);
	ckw_stats_buffer_save($b);
	ckw_log($log, 'OK', "Statistik: $n Viertelstunden gesichert.");
	return $n;
}

// ---------------------------------------------------------------------------
// Auswertung (Datenbank + Puffer)
// ---------------------------------------------------------------------------

/**
 * Viertelstunden im Zeitraum [from, to): ts => array(kwh, cost, cost_fixed, estimated, price_total, price_fixed)
 * Slots ohne Verbrauch, aber mit Preis, sind mit kwh = null enthalten (fuer die Preiskurve).
 */
function ckw_stats_slots($from, $to)
{
	$out = array();
	$db = ckw_stats_db();
	$st = $db->prepare('SELECT ts, total, fixed FROM prices WHERE ts >= ? AND ts < ?');
	$st->execute(array($from, $to));
	foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
		$out[(int)$r[0]] = array(null, null, null, 0, $r[1] !== null ? (float)$r[1] : null, $r[2] !== null ? (float)$r[2] : null);
	}
	$st = $db->prepare('SELECT ts, kwh, cost, cost_fixed, estimated FROM usage WHERE ts >= ? AND ts < ?');
	$st->execute(array($from, $to));
	foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
		$p = isset($out[(int)$r[0]]) ? $out[(int)$r[0]] : array(null, null, null, 0, null, null);
		$out[(int)$r[0]] = array((float)$r[1], $r[2] !== null ? (float)$r[2] : null, $r[3] !== null ? (float)$r[3] : null, (int)$r[4], $p[4], $p[5]);
	}
	// noch nicht geschriebene Werte aus dem Puffer
	$b = ckw_stats_buffer_load();
	foreach ($b['prices'] as $ts => $p) {
		if ($ts >= $from && $ts < $to) {
			$cur = isset($out[$ts]) ? $out[$ts] : array(null, null, null, 0, null, null);
			$out[$ts] = array($cur[0], $cur[1], $cur[2], $cur[3], $p[0], $p[1]);
		}
	}
	foreach ($b['usage'] as $ts => $u) {
		if ($ts >= $from && $ts < $to) {
			$cur = isset($out[$ts]) ? $out[$ts] : array(null, null, null, 0, null, null);
			$out[$ts] = array($u[0], $u[1], $u[2], $u[3], $cur[4], $cur[5]);
		}
	}
	ksort($out);
	return $out;
}

/** Summen einer Slot-Liste */
function ckw_stats_sum(array $slots)
{
	$s = array('kwh' => 0.0, 'cost' => 0.0, 'cost_fixed' => 0.0, 'kwh_priced' => 0.0, 'kwh_fixed' => 0.0, 'estimated' => 0);
	foreach ($slots as $u) {
		if ($u[0] === null) {
			continue;
		}
		$s['kwh'] += $u[0];
		if ($u[1] !== null) {
			$s['cost'] += $u[1];
			$s['kwh_priced'] += $u[0];
		}
		if ($u[2] !== null) {
			$s['cost_fixed'] += $u[2];
			$s['kwh_fixed'] += $u[0];
		}
		$s['estimated'] += $u[3];
	}
	$s['avg_price'] = $s['kwh_priced'] > 0 ? $s['cost'] / $s['kwh_priced'] : null;
	// Ersparnis nur ueber Viertelstunden, fuer die beide Preise bekannt sind
	$both = 0.0;
	$bothFixed = 0.0;
	foreach ($slots as $u) {
		if ($u[0] !== null && $u[1] !== null && $u[2] !== null) {
			$both += $u[1];
			$bothFixed += $u[2];
		}
	}
	$s['saving'] = $s['kwh_fixed'] > 0 ? $bothFixed - $both : null;
	return $s;
}

/**
 * Summen eines Zeitraums direkt per SQL (schnell, auch fuer ein ganzes Jahr) plus Puffer.
 * Gleiches Ergebnis wie ckw_stats_sum(ckw_stats_slots($from, $to)).
 */
function ckw_stats_sum_range($from, $to, $buffer = null)
{
	$st = ckw_stats_db()->prepare('SELECT
		COALESCE(SUM(kwh), 0),
		COALESCE(SUM(cost), 0),
		COALESCE(SUM(CASE WHEN cost IS NOT NULL THEN kwh END), 0),
		COALESCE(SUM(cost_fixed), 0),
		COALESCE(SUM(CASE WHEN cost_fixed IS NOT NULL THEN kwh END), 0),
		COALESCE(SUM(estimated), 0),
		COALESCE(SUM(CASE WHEN cost IS NOT NULL AND cost_fixed IS NOT NULL THEN cost END), 0),
		COALESCE(SUM(CASE WHEN cost IS NOT NULL AND cost_fixed IS NOT NULL THEN cost_fixed END), 0)
		FROM usage WHERE ts >= ? AND ts < ?');
	$st->execute(array($from, $to));
	$r = array_map('floatval', $st->fetch(PDO::FETCH_NUM));
	$b = $buffer !== null ? $buffer : ckw_stats_buffer_load();
	foreach ($b['usage'] as $ts => $u) {
		if ($ts >= $from && $ts < $to) {
			$r[0] += $u[0];
			if ($u[1] !== null) { $r[1] += $u[1]; $r[2] += $u[0]; }
			if ($u[2] !== null) { $r[3] += $u[2]; $r[4] += $u[0]; }
			$r[5] += $u[3];
			if ($u[1] !== null && $u[2] !== null) { $r[6] += $u[1]; $r[7] += $u[2]; }
		}
	}
	return array(
		'kwh' => $r[0], 'cost' => $r[1], 'kwh_priced' => $r[2], 'cost_fixed' => $r[3], 'kwh_fixed' => $r[4],
		'estimated' => (int)$r[5],
		'avg_price' => $r[2] > 0 ? $r[1] / $r[2] : null,
		'saving' => $r[4] > 0 ? $r[7] - $r[6] : null,
	);
}

/** Lokale Zeitraeume (Europe/Zurich): array(from, to) als Unix-Zeit */
function ckw_stats_period($type, $ref)
{
	$tz = new DateTimeZone(CKW_TZ);
	$d = (new DateTimeImmutable('@' . (int)$ref))->setTimezone($tz);
	if ($type === 'day') {
		$a = $d->setTime(0, 0, 0);
		$z = $a->modify('+1 day');
	} elseif ($type === 'month') {
		$a = $d->modify('first day of this month')->setTime(0, 0, 0);
		$z = $a->modify('+1 month');
	} else {
		$a = $d->setDate((int)$d->format('Y'), 1, 1)->setTime(0, 0, 0);
		$z = $a->modify('+1 year');
	}
	return array($a->getTimestamp(), $z->getTimestamp());
}

/**
 * Verbrauch nach Preisstufe: pro Tag werden die Preise des Tages in Drittel geteilt (guenstig/mittel/teuer).
 * Zusaetzlich unter/ueber Fixtarif. Rueckgabe kWh und Kosten je Stufe.
 */
function ckw_stats_tiers(array $slots)
{
	$tz = new DateTimeZone(CKW_TZ);
	$byDay = array();
	foreach ($slots as $ts => $u) {
		$byDay[(new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d')][$ts] = $u;
	}
	$t = array();
	foreach (array('cheap', 'mid', 'expensive', 'below_fixed', 'above_fixed') as $k) {
		$t[$k] = array('kwh' => 0.0, 'cost' => 0.0);
	}
	foreach ($byDay as $day) {
		$prices = array();
		foreach ($day as $u) {
			if ($u[4] !== null) {
				$prices[] = $u[4];
			}
		}
		if (!$prices) {
			continue;
		}
		sort($prices);
		$n = count($prices);
		$p1 = $prices[(int)floor(($n - 1) / 3)];
		$p2 = $prices[(int)floor(2 * ($n - 1) / 3)];
		foreach ($day as $u) {
			if ($u[0] === null || $u[4] === null) {
				continue;
			}
			$k = $u[4] <= $p1 ? 'cheap' : ($u[4] <= $p2 ? 'mid' : 'expensive');
			$t[$k]['kwh'] += $u[0];
			$t[$k]['cost'] += $u[1] !== null ? $u[1] : 0;
			if ($u[5] !== null) {
				$f = $u[4] < $u[5] ? 'below_fixed' : 'above_fixed';
				$t[$f]['kwh'] += $u[0];
				$t[$f]['cost'] += $u[1] !== null ? $u[1] : 0;
			}
		}
	}
	return $t;
}

/** Ausgaenge nach Loxone: heute, Monat, Jahr */
function ckw_stats_outputs(array $cfg, $nowTs, array $info)
{
	$v = array();
	$b = ckw_stats_buffer_load();
	foreach (array('today' => 'day', 'month' => 'month', 'year' => 'year') as $suffix => $type) {
		list($a, $z) = ckw_stats_period($type, $nowTs);
		$s = ckw_stats_sum_range($a, $z, $b);
		$v["stat_kwh_$suffix"]      = number_format($s['kwh'], 3, '.', '');
		$v["stat_cost_$suffix"]     = number_format($s['cost'], 2, '.', '');
		$v["stat_avgprice_$suffix"] = $s['avg_price'] !== null ? ckw_fmt($cfg['unit'] === 'rp' ? $s['avg_price'] * 100 : $s['avg_price'], $cfg) : '-1';
		$v["stat_saving_$suffix"]   = $s['saving'] !== null ? number_format($s['saving'], 2, '.', '') : '-1';
	}
	$v['stat_meter_ok'] = $info['meter_ok'] ? '1' : '0';
	return $v;
}

/** CSV-Export (Semikolon, Excel-tauglich) der Viertelstunden eines Zeitraums */
function ckw_stats_csv($from, $to)
{
	$tz = new DateTimeZone(CKW_TZ);
	$lines = array('Zeit;kWh;Preis CHF/kWh;Kosten CHF;Preis Fixtarif CHF/kWh;Kosten Fixtarif CHF;geschätzt');
	$f = function ($x, $d) { return $x === null ? '' : number_format($x, $d, '.', ''); };
	foreach (ckw_stats_slots($from, $to) as $ts => $u) {
		if ($u[0] === null) {
			continue;
		}
		$lines[] = implode(';', array(
			(new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i'),
			$f($u[0], 4), $f($u[4], 5), $f($u[1], 5), $f($u[5], 5), $f($u[2], 5), $u[3] ? 'ja' : '',
		));
	}
	return implode("\r\n", $lines) . "\r\n";
}
