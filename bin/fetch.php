<?php
/**
 * CKW Dynamischer Tarif - Abruf der Preise und Versand an Loxone.
 * Wird per Cronjob alle 15 Minuten sowie aus der Weboberflaeche aufgerufen.
 *
 * Aufruf: php fetch.php [-v] [--uninstall] [--flush-stats]
 *   -v             Meldungen zusaetzlich auf der Konsole
 *   --uninstall    retained MQTT-Topics des Plugins loeschen (vom Deinstallationsskript)
 *   --flush-stats  Statistik-Puffer (RAM-Disk) sofort in die Datenbank schreiben (vor einem Update)
 * Exit-Code: 0 = ok, 1 = Fehler, 2 = es laeuft bereits ein Abruf
 */

// SDK mit absolutem Pfad laden - nie eine gleichnamige Datei aus dem aktuellen Arbeitsordner.
// Der SDK-Pfad steht zuerst im Include-Pfad, damit auch die SDK-internen require_once dort suchen
// (im Cron ist $LBHOMEDIR nicht gesetzt).
set_include_path('REPLACELBHOMEDIR/libs/phplib' . PATH_SEPARATOR . get_include_path());

require_once 'REPLACELBHOMEDIR/libs/phplib/loxberry_system.php';
require_once 'REPLACELBHOMEDIR/libs/phplib/loxberry_io.php';
require_once 'REPLACELBHOMEDIR/libs/phplib/loxberry_log.php';
require_once __DIR__ . '/ckw_lib.php';

$verbose = in_array('-v', $argv, true);
$buffer = new CkwLogBuffer($verbose);
$finished = false;

/**
 * Ins LoxBerry-Log nur schreiben, wenn bei der eingestellten Stufe etwas anfaellt
 * (Stufe "Fehler": nur fehlerhafte Laeufe, Stufe "Info"/"Debug": jeder Lauf)
 */
function ckw_fetch_write_log(CkwLogBuffer $buffer, $ok)
{
	global $lbplogdir;
	if (!$buffer->relevant((int)LBSystem::pluginloglevel())) {
		return;
	}
	$log = LBLog::newLog(array(
		'name'     => 'Abruf',
		'filename' => "$lbplogdir/fetch.log",
		'append'   => 1,
		'addtime'  => 1,
	));
	$log->LOGSTART('CKW Preisabruf');
	$buffer->replay($log);
	$log->LOGEND($ok ? 'Abruf erfolgreich' : 'Abruf mit Fehlern beendet');
}

// Fatale PHP-Fehler (z. B. Speicher- oder Zeitlimit) beenden das Skript an jedem catch vorbei.
// Dann wenigstens den Grund ins Log schreiben (die Cron-Ausgabe geht nach /dev/null).
register_shutdown_function(function () use ($buffer, &$finished) {
	$e = error_get_last();
	if ($finished || !$e || !in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
		return;
	}
	$buffer->CRIT('Abbruch durch PHP-Fehler: ' . $e['message'] . ' in ' . basename($e['file']) . ':' . $e['line']);
	ckw_fetch_write_log($buffer, false);
});

if (in_array('--uninstall', $argv, true)) {
	$finished = true;
	exit(ckw_mqtt_cleanup($buffer) ? 0 : 1);
}

// Parallele Laeufe (Cron + "Jetzt abrufen") verhindern
$lockFile = ckw_data_dir() . '/fetch.lock';
$lock = @fopen($lockFile, 'c');
if (!$lock) {
	// nicht "laeuft bereits" melden: sonst faellt ein Rechteproblem (z. B. Lock-Datei von root) nie auf
	$buffer->CRIT("Lock-Datei $lockFile nicht beschreibbar (Dateirechte prüfen) - Abruf abgebrochen.");
	ckw_fetch_write_log($buffer, false);
	$finished = true;
	fwrite(STDERR, "Lock-Datei nicht beschreibbar.\n");
	exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
	$finished = true;
	fwrite(STDERR, "Abruf laeuft bereits.\n");
	exit(2);
}

if (in_array('--flush-stats', $argv, true)) {
	try {
		ckw_stats_flush_all($buffer);
		$rc = 0;
	} catch (Throwable $e) {
		fwrite(STDERR, 'Statistik nicht gesichert: ' . $e->getMessage() . "\n");
		$rc = 1;
	}
	flock($lock, LOCK_UN);
	$finished = true;
	exit($rc);
}

$ckwcfg = ckw_load_config();
$buffer->DEB('Konfiguration: ' . json_encode($ckwcfg, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

try {
	$ok = ckw_run($ckwcfg, $buffer);
} catch (Throwable $e) {
	$buffer->CRIT('Unerwarteter Fehler: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
	$ok = false;
}

ckw_fetch_write_log($buffer, $ok);
$finished = true;
flock($lock, LOCK_UN);
exit($ok ? 0 : 1);
