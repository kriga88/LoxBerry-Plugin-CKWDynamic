<?php
/**
 * CKW Dynamischer Tarif - Abruf der Preise und Versand an Loxone.
 * Wird per Cronjob alle 15 Minuten sowie aus der Weboberflaeche aufgerufen.
 *
 * Aufruf: php fetch.php [-v] [--uninstall]
 *   -v           Meldungen zusaetzlich auf der Konsole
 *   --uninstall  retained MQTT-Topics des Plugins loeschen (vom Deinstallationsskript)
 * Exit-Code: 0 = ok, 1 = Fehler, 2 = es laeuft bereits ein Abruf
 */

// Im Cron ist $LBHOMEDIR nicht gesetzt - SDK-Pfad explizit ergaenzen
set_include_path(get_include_path() . PATH_SEPARATOR . 'REPLACELBHOMEDIR/libs/phplib');

require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once "loxberry_log.php";
require_once __DIR__ . "/ckw_lib.php";

$verbose = in_array('-v', $argv, true);
$buffer = new CkwLogBuffer($verbose);

if (in_array('--uninstall', $argv, true)) {
	exit(ckw_mqtt_cleanup($buffer) ? 0 : 1);
}

// Parallele Laeufe (Cron + "Jetzt abrufen") verhindern
$lock = fopen(ckw_data_dir() . '/fetch.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "Abruf laeuft bereits.\n");
	exit(2);
}

$ckwcfg = ckw_load_config();
$buffer->DEB('Konfiguration: ' . json_encode($ckwcfg));

try {
	$ok = ckw_run($ckwcfg, $buffer);
} catch (Throwable $e) {
	$buffer->CRIT('Unerwarteter Fehler: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
	$ok = false;
}

// Ins LoxBerry-Log nur schreiben, wenn bei der eingestellten Stufe etwas anfaellt
// (Stufe "Fehler": nur fehlerhafte Laeufe, Stufe "Info"/"Debug": jeder Lauf)
$loglevel = (int)LBSystem::pluginloglevel();
if ($buffer->relevant($loglevel)) {
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

flock($lock, LOCK_UN);
exit($ok ? 0 : 1);
