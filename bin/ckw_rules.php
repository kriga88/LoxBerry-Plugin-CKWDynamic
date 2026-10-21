<?php
/**
 * CKW Dynamischer Tarif - Schaltregeln.
 *
 * Jede Regel schaltet einen Ausgang (rule_<key>_on) und optional einen Analogwert (rule_<key>_value)
 * auf Basis der Viertelstundenpreise. Geplant wird jeweils ueber das Zeitfenster der Regel (z. B. 18:00-07:00),
 * bei jedem Lauf neu, damit neue Preise (Folgetag ab ca. 12 Uhr) sofort beruecksichtigt werden.
 *
 * Regeltypen:
 *   cheapest  - die guenstigsten N Stunden im Fenster (optional am Stueck, optional mit Obergrenze)
 *   runtime   - Mindest-Ein-Zeit mit max. Aus-Zeit am Stueck und Mindest-Laufblock, kostenminimal (DP)
 *   threshold - Ein ueber bzw. unter einer Preisgrenze, mit Mindestdauer gegen Takten
 *   window    - immer Ein im Zeitfenster (z. B. nur fuer einen Analogwert wie SG-Ready)
 *
 * Preise in der eingestellten Einheit (CHF/kWh oder Rp./kWh), wie alle anderen Ausgaenge.
 */

define('CKW_RULES_MAX', 20);
define('CKW_RULE_STEPS_MAX', 5);

$CKW_RULE_TYPES = array(
	'cheapest'  => 'Günstigste Zeiten',
	'runtime'   => 'Laufzeit',
	'threshold' => 'Preisschwelle',
	'window'    => 'Immer im Zeitfenster',
);

// Grenzarten. Preisband = von CKW genannte Spanne der dynamischen Netznutzung (tariffs.json: <tarif>_min/_max),
// umgerechnet aufs Total wie eingestellt - bleibt gleich, auch wenn die Tagespreise schwanken.
$CKW_RULE_LIMIT_MODES = array(
	'none'     => 'keine',
	'abs'      => 'fester Preis',
	'band_min' => '% Preisband ab Minimum',
	'band_max' => '% Preisband ab Maximum',
	'rank'     => 'Anteil der Viertelstunden',
	'fixed'    => 'Fixtarif +/-',
);

// Bezug der Analogwert-Stufen (von-bis)
$CKW_RULE_STEP_MODES = array(
	'rank'     => 'Anteil der Viertelstunden (%)',
	'band_min' => '% Preisband ab Minimum',
	'abs'      => 'fester Preis',
);

function ckw_rules_state_file()   { return ckw_ram_dir() . '/rules_state.json'; }
function ckw_rules_state_backup() { return ckw_data_dir() . '/rules_state.json'; }

// ---------------------------------------------------------------------------
// Konfiguration
// ---------------------------------------------------------------------------

function ckw_rule_defaults()
{
	return array(
		'key'           => '',
		'name'          => '',
		'enabled'       => true,
		'type'          => 'cheapest',
		'comp'          => 'total',
		'win_from'      => '00:00',
		'win_to'        => '00:00',
		'hours'         => 3.0,
		'block'         => false,
		'limit_mode'    => 'none',
		'limit_value'   => 0.0,
		'priority'      => 'comfort',
		'max_off_h'     => 0.0,
		'min_block_min' => 0,
		'direction'     => 'above',
		'min_dur_min'   => 0,
		'nodata'        => 'plan',
		'power_kw'      => 0.0,
		'analog'        => 'none',
		'steps_mode'    => 'rank',
		'steps'         => array(),
		'else_value'    => 0.0,
		'off_value'     => 0.0,
		'lin_p1'        => 0.0,
		'lin_v1'        => 100.0,
		'lin_p2'        => 0.0,
		'lin_v2'        => 0.0,
		'hold_min'      => 0,
	);
}

/** Kurzname fuer die Loxone-Schluessel: a-z und 0-9, max. 20 Zeichen */
function ckw_rule_slug($name)
{
	$s = strtolower(strtr((string)$name, array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'à' => 'a')));
	$s = preg_replace('/[^a-z0-9]/', '', $s);
	return substr($s, 0, 20);
}

function ckw_rule_time($v, $default)
{
	if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim((string)$v), $m) || (int)$m[1] > 24 || (int)$m[2] > 59) {
		return $default;
	}
	$min = ((int)$m[1] * 60 + (int)$m[2]) % 1440;
	$min -= $min % 15;   // Viertelstundenraster
	return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/** Prueft eine Regel; Fehler werden mit dem Regelnamen an $errors angehaengt */
function ckw_sanitize_rule(array $in, array &$errors)
{
	global $CKW_RULE_TYPES, $CKW_RULE_LIMIT_MODES, $CKW_COMPONENTS;
	$d = ckw_rule_defaults();
	$c = array_merge($d, $in);
	$r = array();
	$r['name'] = trim(preg_replace('/[\x00-\x1f<>]/', '', (string)$c['name']));
	$r['name'] = function_exists('mb_substr') ? mb_substr($r['name'], 0, 40) : substr($r['name'], 0, 40);
	$label = $r['name'] !== '' ? "Regel \"{$r['name']}\"" : 'Regel';
	if ($r['name'] === '') {
		$errors[] = 'Regel: Name fehlt.';
	}
	$r['key'] = ckw_rule_slug($c['key'] !== '' ? $c['key'] : $r['name']);
	if ($r['key'] === '') {
		$r['key'] = 'regel';
	}
	$r['enabled'] = ckw_to_bool($c['enabled']);
	$r['type'] = isset($CKW_RULE_TYPES[$c['type']]) ? $c['type'] : $d['type'];
	$r['comp'] = isset($CKW_COMPONENTS[$c['comp']]) ? $c['comp'] : $d['comp'];
	$r['win_from'] = ckw_rule_time($c['win_from'], $d['win_from']);
	$r['win_to'] = ckw_rule_time($c['win_to'], $d['win_to']);

	$num = function ($k, $min, $max, $text) use (&$c, $d, &$errors, $label) {
		$v = ckw_to_float($c[$k], null);
		if ($v === null || $v < $min || $v > $max) {
			$errors[] = "$label: $text";
			return $d[$k];
		}
		return $v;
	};
	$r['hours'] = $num('hours', 0.25, 24, 'Stunden zwischen 0.25 und 24.');
	$r['block'] = ckw_to_bool($c['block']);
	// Migration aus 1.3.0-beta.1 (% zum Mittel, Drittel)
	if ($c['limit_mode'] === 'third') {
		$c['limit_mode'] = 'rank';
		$c['limit_value'] = ckw_to_float($c['limit_value'], 2) <= 1 ? 33 : 67;
	} elseif ($c['limit_mode'] === 'avg_pct') {
		$c['limit_mode'] = 'rank';
		$c['limit_value'] = max(0, min(100, 50 + ckw_to_float($c['limit_value'], 0)));
	}
	$r['limit_mode'] = isset($CKW_RULE_LIMIT_MODES[$c['limit_mode']]) ? $c['limit_mode'] : 'none';
	if (in_array($r['limit_mode'], array('band_min', 'band_max', 'rank'), true)) {
		$r['limit_value'] = $num('limit_value', 0, 100, 'Grenze in Prozent: 0 bis 100.');
	} else {
		$r['limit_value'] = $num('limit_value', -1000, 1000, 'Grenzwert ungültig.');
	}
	$r['priority'] = in_array($c['priority'], array('comfort', 'price'), true) ? $c['priority'] : $d['priority'];
	$r['max_off_h'] = $num('max_off_h', 0, 24, 'Max. Aus-Zeit zwischen 0 und 24 Stunden.');
	$r['min_block_min'] = (int)$num('min_block_min', 0, 480, 'Mindest-Laufblock zwischen 0 und 480 Minuten.');
	$r['direction'] = in_array($c['direction'], array('above', 'below'), true) ? $c['direction'] : $d['direction'];
	$r['min_dur_min'] = (int)$num('min_dur_min', 0, 480, 'Mindestdauer zwischen 0 und 480 Minuten.');
	$r['nodata'] = in_array($c['nodata'], array('plan', 'off', 'on'), true) ? $c['nodata'] : $d['nodata'];
	$r['power_kw'] = $num('power_kw', 0, 1000, 'Leistung zwischen 0 und 1000 kW.');

	$r['analog'] = in_array($c['analog'], array('none', 'steps', 'linear'), true) ? $c['analog'] : 'none';
	$steps = is_array($c['steps']) ? array_values($c['steps']) : array();
	// Migration aus 1.3.0-beta.1: Stufen "Preis bis" -> von-bis (Bezug Anteil der Viertelstunden)
	if ($steps && isset($steps[0]['mode']) && !isset($steps[0]['from'])) {
		$old = $steps;
		$steps = array();
		$prev = 0.0;
		foreach ($old as $st) {
			if (!isset($st['mode']) || $st['mode'] === 'none' || !isset($st['value'], $st['out']) || $st['value'] === '') {
				continue;
			}
			$to = $st['mode'] === 'third' ? ((float)$st['value'] <= 1 ? 33.0 : 67.0)
				: ($st['mode'] === 'avg_pct' ? max(0, min(100, 50 + (float)$st['value'])) : (float)$st['value']);
			$steps[] = array('from' => $prev, 'to' => $to, 'out' => $st['out']);
			$prev = $to;
		}
		$c['steps_mode'] = 'rank';
	}
	global $CKW_RULE_STEP_MODES;
	$r['steps_mode'] = isset($CKW_RULE_STEP_MODES[$c['steps_mode']]) ? $c['steps_mode'] : $d['steps_mode'];
	$pct = $r['steps_mode'] !== 'abs';
	$r['steps'] = array();
	foreach ($steps as $si => $st) {
		if (!is_array($st)) {
			continue;
		}
		$raw = array(isset($st['from']) ? $st['from'] : '', isset($st['to']) ? $st['to'] : '', isset($st['out']) ? $st['out'] : '');
		if (trim(implode('', array_map('strval', $raw))) === '') {
			continue;   // leere Zeile
		}
		$f = ckw_to_float($raw[0], null);
		$t = ckw_to_float($raw[1], null);
		$o = ckw_to_float($raw[2], null);
		$row = $si + 1;
		if ($f === null || $t === null || $o === null) {
			$errors[] = "$label: Stufe $row unvollständig (von, bis und Wert angeben).";
			continue;
		}
		if ($pct && ($f < 0 || $t > 100)) {
			$errors[] = "$label: Stufe $row: von/bis zwischen 0 und 100 %.";
			continue;
		}
		if ($f >= $t) {
			$errors[] = "$label: Stufe $row: „von“ muss kleiner als „bis“ sein.";
			continue;
		}
		if (abs($o) > 100000 || abs($f) > 1000 || abs($t) > 1000) {
			$errors[] = "$label: Stufe $row ungültig.";
			continue;
		}
		$r['steps'][] = array('from' => $f, 'to' => $t, 'out' => $o);
		if (count($r['steps']) >= CKW_RULE_STEPS_MAX) {
			break;
		}
	}
	foreach (array('else_value', 'off_value', 'lin_p1', 'lin_v1', 'lin_p2', 'lin_v2') as $k) {
		$r[$k] = $num($k, -100000, 100000, 'Analogwert ungültig.');
	}
	$r['hold_min'] = (int)$num('hold_min', 0, 480, 'Haltezeit zwischen 0 und 480 Minuten.');
	if ($r['analog'] === 'steps' && !$r['steps']) {
		$errors[] = "$label: Für den Analogwert in Stufen mindestens eine Stufe angeben.";
	}
	return $r;
}

/** Prueft die Regelliste und macht die Kurznamen eindeutig */
function ckw_sanitize_rules($rules, array &$errors)
{
	$out = array();
	$keys = array();
	foreach (is_array($rules) ? $rules : array() as $in) {
		if (!is_array($in)) {
			continue;
		}
		if (count($out) >= CKW_RULES_MAX) {
			$errors[] = 'Maximal ' . CKW_RULES_MAX . ' Regeln.';
			break;
		}
		$r = ckw_sanitize_rule($in, $errors);
		$base = $r['key'];
		for ($i = 2; isset($keys[$r['key']]); $i++) {
			$r['key'] = substr($base, 0, 18) . $i;
		}
		$keys[$r['key']] = true;
		$out[] = $r;
	}
	return $out;
}

/** Vorlagen fuer neue Regeln */
function ckw_rule_templates()
{
	$d = ckw_rule_defaults();
	return array(
		'auto' => array('label' => 'Auto laden', 'rule' => array_merge($d, array(
			'name' => 'Auto laden', 'type' => 'cheapest', 'win_from' => '18:00', 'win_to' => '07:00', 'hours' => 4.0))),
		'waschen' => array('label' => 'Waschmaschine', 'rule' => array_merge($d, array(
			'name' => 'Waschmaschine', 'type' => 'cheapest', 'win_from' => '08:00', 'win_to' => '22:00', 'hours' => 2.0, 'block' => true,
			'limit_mode' => 'rank', 'limit_value' => 50.0))),
		'wp' => array('label' => 'Wärmepumpe', 'rule' => array_merge($d, array(
			'name' => 'Wärmepumpe', 'type' => 'runtime', 'hours' => 8.0, 'max_off_h' => 3.0, 'min_block_min' => 30,
			'priority' => 'comfort', 'limit_mode' => 'rank', 'limit_value' => 67.0,
			'analog' => 'steps', 'steps_mode' => 'rank', 'steps' => array(
				array('from' => 0.0, 'to' => 25.0, 'out' => 100.0),
				array('from' => 25.0, 'to' => 60.0, 'out' => 70.0),
			), 'else_value' => 40.0, 'off_value' => 0.0, 'hold_min' => 60))),
		'sgready' => array('label' => 'SG-Ready', 'rule' => array_merge($d, array(
			'name' => 'SG Ready', 'type' => 'window', 'analog' => 'steps', 'steps_mode' => 'rank', 'steps' => array(
				array('from' => 0.0, 'to' => 10.0, 'out' => 4.0),
				array('from' => 10.0, 'to' => 35.0, 'out' => 3.0),
				array('from' => 35.0, 'to' => 80.0, 'out' => 2.0),
			), 'else_value' => 1.0, 'off_value' => 2.0, 'hold_min' => 30))),
		'sperre' => array('label' => 'Sperrzeit', 'rule' => array_merge($d, array(
			'name' => 'Sperrzeit', 'type' => 'threshold', 'direction' => 'above', 'limit_mode' => 'rank', 'limit_value' => 80.0, 'min_dur_min' => 30))),
		'boiler' => array('label' => 'Boiler', 'rule' => array_merge($d, array(
			'name' => 'Boiler', 'type' => 'cheapest', 'hours' => 3.0))),
		'leer' => array('label' => 'Leer', 'rule' => array_merge($d, array('name' => 'Neue Regel'))),
	);
}

// ---------------------------------------------------------------------------
// Zeitfenster und Preise
// ---------------------------------------------------------------------------

/**
 * Fenster-Instanz, die $ts enthaelt, sonst die naechste: array(start, end, drin)
 * Gleiche Zeiten (00:00-00:00) = ganzer Tag; Ende <= Start = ueber Mitternacht.
 */
function ckw_rule_instance(array $r, $ts)
{
	$tz = new DateTimeZone(CKW_TZ);
	list($fh, $fm) = array_map('intval', explode(':', $r['win_from']));
	list($th, $tm) = array_map('intval', explode(':', $r['win_to']));
	$cross = ($th * 60 + $tm) <= ($fh * 60 + $fm);
	$day = (new DateTimeImmutable('@' . (int)$ts))->setTimezone($tz)->setTime(0, 0, 0);
	$next = null;
	foreach (array(-1, 0, 1) as $off) {
		$d = $day->modify(($off >= 0 ? '+' : '') . $off . ' day');
		$s = $d->setTime($fh, $fm)->getTimestamp();
		$e = $d->modify($cross ? '+1 day' : '+0 day')->setTime($th, $tm)->getTimestamp();
		if ($ts >= $s && $ts < $e) {
			return array($s, $e, true);
		}
		if ($s > $ts && $next === null) {
			$next = array($s, $e, false);
		}
	}
	return $next;
}

/**
 * Preistabelle fuer die Regeln: ts => array('p' => Komponenten, 'real' => bool, 'fixed' => Fixtarif-Total|null)
 * Fehlende Viertelstunden werden mit dem CKW-Durchschnittspreis gefuellt (wie die Reihen fuer Loxone),
 * ohne Durchschnittswert mit dem hoechsten bekannten Preis.
 */
function ckw_rules_price_table(array $raw, array $cfg, array $tariffs, $from, $to)
{
	$m = array();
	$slots = ckw_compute_slots($raw, $cfg, $tariffs, $m);
	$hours = array();
	for ($t = $from; $t < $to; $t += 900) {
		if (!isset($slots[$t])) {
			$hours[$t - ($t % 3600)] = true;
		}
	}
	$fill = $hours ? ckw_reference_fill(array_keys($hours), $raw, $cfg, $tariffs, $m) : array();
	$max = null;
	foreach ($slots as $s) {
		$max = $max === null ? $s : array_map('max', $max, $s);
	}
	if ($max !== null) {
		$max = array_combine(array_keys(reset($slots)), $max);
	}
	$fixedDay = array();
	$bandDay = array();
	$latest = ckw_latest_slot($raw);
	$tz = new DateTimeZone(CKW_TZ);
	$out = array();
	for ($t = $from; $t < $to; $t += 900) {
		$ymd = (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d');
		if (!array_key_exists($ymd, $fixedDay)) {
			$fx = ckw_fixed_price($raw, $cfg, $tariffs, $t);
			$fixedDay[$ymd] = ($fx !== null && !$fx['outdated']) ? $fx['values']['total'] : null;
			$bandDay[$ymd] = ckw_rules_band($cfg, $tariffs, $latest, $t);
		}
		$base = array('fixed' => $fixedDay[$ymd], 'band' => $bandDay[$ymd]);
		if (isset($slots[$t])) {
			$out[$t] = array('p' => $slots[$t], 'real' => true) + $base;
		} else {
			$h = $t - ($t % 3600);
			$p = ($fill !== null && isset($fill[$h])) ? $fill[$h] : $max;
			$out[$t] = array('p' => $p, 'real' => false) + $base;
		}
	}
	return $out;
}

/**
 * Preisband (min, max) als Komponenten in der eingestellten Einheit fuer den Tag von $ts:
 * dynamische Netznutzung = Band-Min bzw. Band-Max laut CKW, uebrige Anteile wie eingestellt.
 * null, wenn fuer den Tarif kein Band in der Preistabelle steht.
 */
function ckw_rules_band(array $cfg, array $tariffs, $latest, $ts)
{
	$ymd = (new DateTimeImmutable('@' . (int)$ts))->setTimezone(new DateTimeZone(CKW_TZ))->format('Y-m-d');
	$lo = ckw_reference($tariffs, $cfg['tariff'] . '_min', $ymd);
	$hi = ckw_reference($tariffs, $cfg['tariff'] . '_max', $ymd);
	if ($lo === null || $hi === null) {
		return null;
	}
	$m = array();
	$slots = ckw_compute_slots(array(
		$ts       => ckw_synth_slot($tariffs, $latest, $ymd, $lo['rp'] / 100),
		$ts + 900 => ckw_synth_slot($tariffs, $latest, $ymd, $hi['rp'] / 100),
	), $cfg, $tariffs, $m);
	return array($slots[$ts], $slots[$ts + 900]);
}

/**
 * Grenzpreis aus Modus/Wert.
 * $winPrices: Preise des Fensters (fuer "Anteil der Viertelstunden"), $band: array(min, max) des Preisbands oder null,
 * $fixed: Fixtarif-Preis oder null.
 */
function ckw_rule_limit($mode, $value, array $winPrices, $fixed, $band = null)
{
	switch ($mode) {
		case 'abs':
			return (float)$value;
		case 'band_min':
			return $band === null ? null : $band[0] + ($band[1] - $band[0]) * $value / 100;
		case 'band_max':
			return $band === null ? null : $band[1] - ($band[1] - $band[0]) * $value / 100;
		case 'rank':
			if (!$winPrices) {
				return null;
			}
			$p = $winPrices;
			sort($p);
			return $p[(int)floor((count($p) - 1) * max(0, min(100, $value)) / 100)];
		case 'fixed':
			return $fixed === null ? null : $fixed + $value;
	}
	return null;
}

// ---------------------------------------------------------------------------
// Planung
// ---------------------------------------------------------------------------

/**
 * Plant die restlichen Viertelstunden eines Fensters.
 * $p: Preise der Slots (Index 0 = aktuelle bzw. erste Viertelstunde), $lim: Obergrenze/Schwelle oder null.
 * $h: Vorgeschichte array('done' => Ein-Slots bereits im Fenster, 'run_on', 'run_off', 'known' => bool)
 * Rueckgabe: Liste von bool (Ein/Aus) je Slot.
 */
function ckw_rule_schedule(array $r, array $p, $lim, array $h)
{
	$n = count($p);
	$on = array_fill(0, $n, false);
	if ($n === 0) {
		return $on;
	}
	switch ($r['type']) {
		case 'window':
			return array_fill(0, $n, true);

		case 'threshold':
			$min = (int)ceil($r['min_dur_min'] / 15);
			$cur = $h['run_on'] > 0;
			$held = $h['known'] ? ($cur ? $h['run_on'] : $h['run_off']) : PHP_INT_MAX;
			for ($i = 0; $i < $n; $i++) {
				$want = $lim !== null && ($r['direction'] === 'above' ? $p[$i] > $lim : $p[$i] < $lim);
				if ($want !== $cur && $held >= $min) {
					$cur = $want;
					$held = 0;
				}
				$on[$i] = $cur;
				$held++;
			}
			return $on;

		case 'cheapest':
			$total = (int)round($r['hours'] * 4);
			$need = min($n, max(0, $total - $h['done']));
			if ($need === 0) {
				return $on;
			}
			if ($r['block']) {
				// laufenden Block zu Ende fuehren
				if ($h['done'] > 0 && $h['run_on'] > 0) {
					for ($i = 0; $i < $need; $i++) {
						$on[$i] = true;
					}
					return $on;
				}
				$best = null;
				$bestSum = null;
				for ($s = 0; $s + $need <= $n; $s++) {
					$sum = 0.0;
					$ok = true;
					for ($i = $s; $i < $s + $need; $i++) {
						if ($lim !== null && $p[$i] > $lim) {
							$ok = false;
							break;
						}
						$sum += $p[$i];
					}
					if ($ok && ($bestSum === null || $sum < $bestSum - 1e-12)) {
						$best = $s;
						$bestSum = $sum;
					}
				}
				if ($best !== null) {
					for ($i = $best; $i < $best + $need; $i++) {
						$on[$i] = true;
					}
				}
				return $on;
			}
			$idx = array();
			for ($i = 0; $i < $n; $i++) {
				if ($lim === null || $p[$i] <= $lim) {
					$idx[] = $i;
				}
			}
			usort($idx, function ($a, $b) use ($p) {
				return $p[$a] == $p[$b] ? $a - $b : ($p[$a] < $p[$b] ? -1 : 1);
			});
			foreach (array_slice($idx, 0, $need) as $i) {
				$on[$i] = true;
			}
			return $on;

		case 'runtime':
			return ckw_rule_runtime_dp($r, $p, $lim, $h);
	}
	return $on;
}

/**
 * Laufzeit-Regel als dynamische Programmierung ueber die restlichen Slots.
 * Zustand: s > 0 = Ein seit s Slots (gedeckelt beim Mindestblock), s < 0 = Aus seit -s Slots
 * (gedeckelt bei max. Aus-Zeit + 1), c = erreichte Ein-Slots (gedeckelt beim Bedarf).
 * Vorrang Komfort: Mindestzeit und max. Aus-Zeit hart, Obergrenze weich.
 * Vorrang Preis: Obergrenze (fast) hart, Mindestzeit und max. Aus-Zeit weich.
 * Der Mindestblock gilt immer (Kompressorschutz).
 */
function ckw_rule_runtime_dp(array $r, array $p, $lim, array $h)
{
	$n = count($p);
	$need = max(0, (int)round($r['hours'] * 4) - $h['done']);
	$M = $r['max_off_h'] > 0 ? (int)round($r['max_off_h'] * 4) : 0;
	$B = max(1, (int)ceil($r['min_block_min'] / 15));
	$offCap = $M > 0 ? $M + 1 : 1;
	$comfort = $r['priority'] === 'comfort';
	$OVER = $comfort ? 1e5 : 1e9;      // Preis ueber der Obergrenze
	$VIOL = 1e7;                       // Preis-Vorrang: je fehlender Slot bzw. je Slot zu lange aus
	$UNMET = $comfort ? 1e12 : $VIOL;

	if ($h['run_on'] > 0) {
		$s0 = min($h['run_on'], $B);
	} else {
		$s0 = -min(max(1, $h['known'] ? $h['run_off'] : 1), $offCap);
	}
	$key = function ($s, $c) use ($need) { return (($s + 1000) * 1000) + $c; };
	$cur = array($key($s0, 0) => array(0.0, $s0, 0));
	$parent = array();
	for ($i = 0; $i < $n; $i++) {
		$next = array();
		$price = $p[$i] + (($lim !== null && $p[$i] > $lim) ? $OVER : 0);
		foreach ($cur as $k => $st) {
			list($cost, $s, $c) = $st;
			// Ein
			$ns = $s > 0 ? min($s + 1, $B) : 1;
			$nc = min($c + 1, $need);
			$nk = $key($ns, $nc);
			$v = $cost + $price;
			if (!isset($next[$nk]) || $v < $next[$nk][0]) {
				$next[$nk] = array($v, $ns, $nc);
				$parent[$i][$nk] = array($k, true);
			}
			// Aus (nur wenn kein Mindestblock laeuft)
			if ($s < 0 || $s >= $B) {
				$len = $s < 0 ? -$s + 1 : 1;
				$v = $cost;
				if ($M > 0 && $len > $M) {
					if ($comfort) {
						continue;
					}
					$v += $VIOL;
				}
				$ns = -min($len, $offCap);
				$nk = $key($ns, $c);
				if (!isset($next[$nk]) || $v < $next[$nk][0]) {
					$next[$nk] = array($v, $ns, $c);
					$parent[$i][$nk] = array($k, false);
				}
			}
		}
		$cur = $next;
	}
	$bestK = null;
	$best = null;
	foreach ($cur as $k => $st) {
		$v = $st[0] + ($need - $st[2]) * $UNMET;
		if ($best === null || $v < $best - 1e-9) {
			$best = $v;
			$bestK = $k;
		}
	}
	$on = array_fill(0, $n, false);
	for ($i = $n - 1, $k = $bestK; $i >= 0; $i--) {
		list($k, $isOn) = $parent[$i][$k];
		$on[$i] = $isOn;
	}
	return $on;
}

/** Preisgrenzen (von, bis) der Analogwert-Stufen */
function ckw_rule_step_limits(array $r, array $basis, $fixed, $band)
{
	$out = array();
	foreach ($r['steps'] as $i => $s) {
		$a = ckw_rule_limit($r['steps_mode'], $s['from'], $basis, $fixed, $band);
		$b = ckw_rule_limit($r['steps_mode'], $s['to'], $basis, $fixed, $band);
		$out[$i] = array($a, $b);
	}
	return $out;
}

/** Analogwert fuer einen Preis (ohne Hysterese) */
function ckw_rule_map_value(array $r, $price, array $stepLimits)
{
	if ($r['analog'] === 'steps') {
		// erste Stufe mit von <= Preis <= bis (Grenzpreis gehoert zur oberen Zeile)
		foreach ($r['steps'] as $i => $st) {
			if ($stepLimits[$i][0] !== null && $stepLimits[$i][1] !== null
				&& $price >= $stepLimits[$i][0] - 1e-9 && $price <= $stepLimits[$i][1] + 1e-9) {
				return $st['out'];
			}
		}
		return $r['else_value'];
	}
	if ($r['analog'] === 'linear') {
		if ($r['lin_p1'] == $r['lin_p2']) {
			return $r['lin_v1'];
		}
		$t = ($price - $r['lin_p1']) / ($r['lin_p2'] - $r['lin_p1']);
		$t = max(0.0, min(1.0, $t));
		return $r['lin_v1'] + $t * ($r['lin_v2'] - $r['lin_v1']);
	}
	return null;
}

/**
 * Plan einer Regel ab der aktuellen Viertelstunde fuer das laufende (bzw. naechste) und das folgende Fenster.
 * $st: Zustand der Regel (done, run_on, run_off, known, val, val_held), bereits auf die aktuelle Viertelstunde fortgeschrieben.
 * Rueckgabe: array('slots' => ts => array(price, on, value, real, limit), 'inst' => array(start, end, drin), 'limit' => ...)
 */
function ckw_rule_plan(array $r, array $table, $slotTs, array $st)
{
	$comp = $r['comp'];
	$inst = ckw_rule_instance($r, $slotTs);
	$plan = array('slots' => array(), 'inst' => $inst, 'limit' => null, 'windows' => array());
	$hist = array('done' => $inst[2] ? $st['done'] : 0, 'run_on' => $st['run_on'], 'run_off' => $st['run_off'], 'known' => $st['known']);
	$windows = array($inst);
	$second = ckw_rule_instance($r, $inst[1]);
	if ($second && $second[0] >= $inst[1]) {
		$windows[] = $second;
	}
	$val = $st['val'];
	$held = $st['val_held'];
	$prevOn = $st['on'];
	$holdSlots = (int)ceil($r['hold_min'] / 15);
	foreach ($windows as $wi => $w) {
		// Preise des Fensters (fuer relative Grenzen ganzes Fenster, bevorzugt echte Preise)
		$all = array();
		$real = array();
		for ($t = $w[0]; $t < $w[1]; $t += 900) {
			if (isset($table[$t]) && $table[$t]['p'] !== null) {
				$all[] = $table[$t]['p'][$comp];
				if ($table[$t]['real']) {
					$real[] = $table[$t]['p'][$comp];
				}
			}
		}
		$basis = count($real) >= 4 ? $real : $all;
		$fixed = isset($table[$w[0]]) ? $table[$w[0]]['fixed'] : null;
		$band = (isset($table[$w[0]]) && $table[$w[0]]['band'] !== null) ? array($table[$w[0]]['band'][0][$comp], $table[$w[0]]['band'][1][$comp]) : null;
		$lim = ckw_rule_limit($r['limit_mode'], $r['limit_value'], $basis, $fixed, $band);
		$stepLim = ckw_rule_step_limits($r, $basis, $fixed, $band);
		if ($wi === 0) {
			$plan['limit'] = $lim;
		}
		// restliche Slots
		$ts = array();
		$p = array();
		for ($t = max($w[0], $slotTs); $t < $w[1]; $t += 900) {
			if (isset($table[$t]) && $table[$t]['p'] !== null) {
				$ts[] = $t;
				$p[] = $table[$t]['p'][$comp];
			}
		}
		$on = ckw_rule_schedule($r, $p, $lim, $hist);
		foreach ($ts as $i => $t) {
			$value = null;
			if ($r['analog'] !== 'none') {
				// Hysterese: ein neuer Wert gilt erst, wenn der bisherige mindestens die Haltezeit anlag.
				// Beim Ein- und Ausschalten wechselt der Wert sofort.
				$want = $on[$i] ? ckw_rule_map_value($r, $p[$i], $stepLim) : $r['off_value'];
				if ($val === null || $on[$i] !== $prevOn || ($want != $val && $held >= $holdSlots)) {
					if ($val === null || $want != $val) {
						$held = 0;
					}
					$val = $want;
				}
				$held++;
				$value = $val;
			}
			$prevOn = $on[$i];
			$plan['slots'][$t] = array($p[$i], $on[$i], $value, $table[$t]['real'], $lim);
		}
		$plan['windows'][] = array($w[0], $w[1], $lim);
		// Vorgeschichte fuer das Folgefenster: Ende des aktuellen Plans
		$runOn = 0;
		$runOff = 0;
		for ($i = count($on) - 1; $i >= 0 && $on[$i]; $i--) {
			$runOn++;
		}
		for ($i = count($on) - 1; $i >= 0 && !$on[$i]; $i--) {
			$runOff++;
		}
		// zwischen zwei Fenstern ist der Ausgang aus
		$gap = isset($windows[$wi + 1]) ? (int)(($windows[$wi + 1][0] - $w[1]) / 900) : 0;
		if ($gap > 0) {
			$hist = array('done' => 0, 'run_on' => 0, 'run_off' => ($runOn > 0 ? 0 : $runOff) + $gap, 'known' => true);
		} else {
			$hist = array('done' => 0, 'run_on' => $runOn, 'run_off' => $runOff, 'known' => true);
		}
		if ($gap > 0 && $r['analog'] !== 'none') {
			$val = $r['off_value'];
			$prevOn = false;
		}
	}
	return $plan;
}

// ---------------------------------------------------------------------------
// Zustand und Lauf
// ---------------------------------------------------------------------------

function ckw_rules_state_load()
{
	$s = ckw_read_json(ckw_rules_state_file());
	if (!$s) {
		$s = ckw_read_json(ckw_rules_state_backup());   // nach Neustart (RAM-Disk leer)
	}
	return is_array($s) ? $s : array();
}

/** Schreibt den Zustand in die RAM-Disk, einmal pro Stunde zusaetzlich auf die SD-Karte */
function ckw_rules_state_save(array $s, $nowTs)
{
	ckw_write_json(ckw_rules_state_file(), $s);
	$hour = (int)($nowTs - ($nowTs % 3600));
	$b = ckw_read_json(ckw_rules_state_backup());
	if (!$b || !isset($b['_hour']) || $b['_hour'] < $hour) {
		$s['_hour'] = $hour;
		ckw_write_json(ckw_rules_state_backup(), $s);
	}
}

/** Schreibt den Zustand einer Regel auf die aktuelle Viertelstunde fort (abgelaufene Slots verbuchen) */
function ckw_rule_state_advance(array $r, $st, $slotTs)
{
	$st = (is_array($st) ? $st : array()) + array('slot' => null, 'on' => false, 'inst' => null, 'done' => 0,
		'run_on' => 0, 'run_off' => 0, 'known' => false, 'val' => null, 'val_held' => 0);
	$inst = ckw_rule_instance($r, $slotTs);
	if ($st['slot'] !== null && $slotTs > $st['slot']) {
		$gap = (int)(($slotTs - $st['slot']) / 900);
		$counted = 0;
		if ($st['on'] && $inst[2]) {
			for ($t = $st['slot']; $t < $slotTs; $t += 900) {
				if ($t >= $inst[0] && $t < $inst[1]) {
					$counted++;
				}
			}
		}
		$st['done'] = ($st['inst'] === $inst[0] ? $st['done'] : 0) + $counted;
		if ($st['on']) {
			$st['run_on'] += $gap;
			$st['run_off'] = 0;
		} else {
			$st['run_off'] += $gap;
			$st['run_on'] = 0;
		}
		$st['val_held'] += $gap;
		$st['known'] = true;
	} elseif ($st['inst'] !== $inst[0] && $st['slot'] !== $slotTs) {
		$st['done'] = 0;
	}
	$st['inst'] = $inst[0];
	return $st;
}

/**
 * Wird von ckw_run() aufgerufen. Rueckgabe: array('values' => Loxone-Werte, 'rules' => Zusammenfassung je Regel)
 */
function ckw_rules_run(array $cfg, array $table, $nowTs, $log = null)
{
	$slotTs = $nowTs - ($nowTs % 900);
	$state = ckw_rules_state_load();
	$newState = array();
	$values = array();
	$summary = array();
	$pf = $cfg['unit'] === 'rp' ? 100 : 1;
	foreach ($cfg['rules'] as $r) {
		if (!$r['enabled']) {
			continue;
		}
		$old = isset($state[$r['key']]) ? $state[$r['key']] : null;
		$st = ckw_rule_state_advance($r, $old, $slotTs);
		// beim erneuten Lauf in derselben Viertelstunde (Weboberflaeche) den Wert vor dieser Viertelstunde verwenden
		if ($old && isset($old['slot']) && $old['slot'] === $slotTs && isset($old['val_before'])) {
			$st['val'] = $old['val_before'];
			$st['val_held'] = $old['val_held_before'];
		}
		$plan = ckw_rule_plan($r, $table, $slotTs, $st);
		$cur = isset($plan['slots'][$slotTs]) && $plan['inst'][2] ? $plan['slots'][$slotTs] : null;
		$on = $cur ? $cur[1] : false;
		$real = isset($table[$slotTs]) ? $table[$slotTs]['real'] : false;
		if (!$real && $r['nodata'] !== 'plan') {
			$on = $r['nodata'] === 'on';
		}
		$value = null;
		if ($r['analog'] !== 'none') {
			$value = $cur && $on ? $cur[2] : $r['off_value'];
		}
		$st['val_before'] = $st['val'];
		$st['val_held_before'] = $st['val_held'];
		if ($value !== null && $value != $st['val']) {
			$st['val_held'] = 0;
		}
		$st['val'] = $value;
		$st['slot'] = $slotTs;
		$st['on'] = $on;
		$newState[$r['key']] = $st;

		$nextTs = null;
		$left = 0;
		$cost = 0.0;
		$firstWin = $plan['windows'] ? $plan['windows'][0] : null;
		foreach ($plan['slots'] as $t => $s) {
			if ($s[1]) {
				if ($nextTs === null && ($t > $slotTs || $on)) {
					$nextTs = $t;
				}
				if ($firstWin && $t < $firstWin[1]) {
					$left++;
					$cost += $s[0];
				}
			}
		}
		$k = 'rule_' . $r['key'];
		$values[$k . '_on'] = $on ? '1' : '0';
		if ($r['analog'] !== 'none') {
			$values[$k . '_value'] = number_format((float)$value, 2, '.', '');
			$nh = $slotTs + 3600;
			$values[$k . '_value_next'] = isset($plan['slots'][$nh]) && $plan['slots'][$nh][2] !== null ? number_format($plan['slots'][$nh][2], 2, '.', '') : '-1';
		}
		$values[$k . '_next_min'] = $nextTs === null ? '-1' : (string)max(0, (int)ceil(($nextTs - $nowTs) / 60));
		$values[$k . '_next_clock'] = $nextTs === null ? '-1' : (string)(int)ckw_local_time($nextTs, 'Hi');
		$values[$k . '_left_min'] = (string)($left * 15);
		$values[$k . '_done_min'] = (string)($plan['inst'][2] ? $st['done'] * 15 : 0);
		if ($r['power_kw'] > 0) {
			$values[$k . '_cost'] = number_format($cost / $pf * $r['power_kw'] * 0.25, 2, '.', '');
		}
		$summary[$r['key']] = array(
			'name' => $r['name'], 'on' => $on, 'value' => $value, 'next' => $nextTs, 'left_min' => $left * 15,
			'done_min' => $plan['inst'][2] ? $st['done'] * 15 : 0, 'limit' => $plan['limit'], 'inst' => $plan['inst'], 'real' => $real,
		);
		ckw_log($log, 'DEB', "Regel {$r['name']}: " . ($on ? 'Ein' : 'Aus') . ($value !== null ? ", Wert $value" : '') . ", geplant noch " . ($left * 15) . ' min');
	}
	ckw_rules_state_save($newState, $nowTs);
	return array('values' => $values, 'rules' => $summary);
}

/** Vorschau fuer die Weboberflaeche (ohne Zustand zu veraendern) */
function ckw_rule_preview(array $r, array $table, $nowTs)
{
	$slotTs = $nowTs - ($nowTs % 900);
	$state = ckw_rules_state_load();
	$st = ckw_rule_state_advance($r, isset($state[$r['key']]) ? $state[$r['key']] : null, $slotTs);
	if (isset($state[$r['key']]['slot']) && $state[$r['key']]['slot'] === $slotTs && isset($state[$r['key']]['val_before'])) {
		$st['val'] = $state[$r['key']]['val_before'];
		$st['val_held'] = $state[$r['key']]['val_held_before'];
	}
	return ckw_rule_plan($r, $table, $slotTs, $st);
}

/**
 * Bezugswerte eines Fensters fuer die Weboberflaeche: Preisband (min, max), sortierte Fensterpreise
 * (bevorzugt echte), Fixtarif. Preise in der eingestellten Einheit.
 */
function ckw_rule_reference(array $r, array $table, $from, $to)
{
	$comp = $r['comp'];
	$all = array();
	$real = array();
	for ($t = $from; $t < $to; $t += 900) {
		if (isset($table[$t]) && $table[$t]['p'] !== null) {
			$all[] = $table[$t]['p'][$comp];
			if ($table[$t]['real']) {
				$real[] = $table[$t]['p'][$comp];
			}
		}
	}
	$basis = count($real) >= 4 ? $real : $all;
	sort($basis);
	$first = isset($table[$from]) ? $table[$from] : ($table ? reset($table) : null);
	$band = ($first && $first['band'] !== null) ? array($first['band'][0][$comp], $first['band'][1][$comp]) : null;
	return array(
		'band'   => $band,
		'prices' => array_map(function ($x) { return round($x, 5); }, $basis),
		'fixed'  => $first ? $first['fixed'] : null,
	);
}

/** Preistabelle fuer die Weboberflaeche aus dem letzten Abruf (kein Netzzugriff) */
function ckw_rules_ui_table(array $cfg, $nowTs)
{
	$cache = ckw_read_json(ckw_cache_file());
	$raw = ($cache && isset($cache['prices']) && $cache['tariff'] === $cfg['tariff']) ? ckw_parse_slots($cache['prices']) : array();
	$tariffs = ckw_load_tariffs($cfg['tariffs_remote'], null, false);
	$day = (new DateTimeImmutable('@' . (int)$nowTs))->setTimezone(new DateTimeZone(CKW_TZ))->setTime(0, 0, 0);
	return ckw_rules_price_table($raw, $cfg, $tariffs['data'], $day->modify('-1 day')->getTimestamp(), $day->modify('+3 day')->getTimestamp());
}

/** Daten fuer das Vorschau-Diagramm einer Regel (laufendes bzw. naechstes und folgendes Zeitfenster) */
function ckw_rule_preview_data(array $r, array $cfg, $nowTs)
{
	$table = ckw_rules_ui_table($cfg, $nowTs);
	$plan = ckw_rule_preview($r, $table, $nowTs);
	$d = array('labels' => array(), 'price' => array(), 'on' => array(), 'value' => array(), 'limit' => array(), 'real' => array());
	$onMin = 0;
	$cost = 0.0;
	foreach ($plan['slots'] as $ts => $s) {
		$d['labels'][] = ckw_rule_day_label($ts) . ckw_local_time($ts, ' H:i');
		$d['price'][] = round($s[0], 5);
		$d['on'][] = $s[1] ? 1 : 0;
		$d['value'][] = $s[2] !== null ? round($s[2], 3) : null;
		$d['limit'][] = $s[4] !== null ? round($s[4], 5) : null;
		$d['real'][] = $s[3] ? 1 : 0;
		if ($s[1] && $plan['windows'] && $ts < $plan['windows'][0][1]) {
			$onMin += 15;
			$cost += $s[0];
		}
	}
	$w = $plan['windows'] ? $plan['windows'][0] : null;
	// Bezugswerte fuer die Regler im Editor: Preisband, Preise des Fensters (sortiert), Fixtarif
	$ref = ckw_rule_reference($r, $table, $w ? $w[0] : $nowTs, $w ? $w[1] : $nowTs + 86400);
	$d['ref'] = $ref;
	$d['summary'] = array(
		'window'   => $w ? ckw_rule_day_label($w[0]) . ckw_local_time($w[0], ' d.m. H:i') . ' – ' . ckw_rule_day_label($w[1]) . ckw_local_time($w[1], ' d.m. H:i') : '',
		'in'       => $plan['inst'][2],
		'on_min'   => $onMin,
		'limit'    => $plan['limit'],
		'cost_chf' => $r['power_kw'] > 0 ? $cost / ($cfg['unit'] === 'rp' ? 100 : 1) * $r['power_kw'] * 0.25 : null,
	);
	return $d;
}

function ckw_rule_day_label($ts)
{
	$d = array('So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa');
	return $d[(int)ckw_local_time($ts, 'w')];
}
