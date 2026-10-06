<?php
/**
 * CKW Dynamischer Tarif - Abruf der Preise und Versand an Loxone.
 * Wird per Cronjob alle 15 Minuten sowie aus der Weboberflaeche aufgerufen.
 *
 * Aufruf: php fetch.php [-v]   (-v = Log zusaetzlich auf der Konsole)
 */

// Im Cron ist $LBHOMEDIR nicht gesetzt - SDK-Pfad explizit ergaenzen
set_include_path(get_include_path() . PATH_SEPARATOR . 'REPLACELBHOMEDIR/libs/phplib');

require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once "loxberry_log.php";
require_once __DIR__ . "/ckw_lib.php";

$verbose = in_array('-v', $argv, true);

// Parallele Laeufe (Cron + "Jetzt abrufen") verhindern
$lock = fopen(ckw_data_dir() . '/fetch.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "Abruf laeuft bereits.\n");
	exit(0);
}

$log = LBLog::newLog(array(
	'name'     => 'Abruf',
	'filename' => "$lbplogdir/fetch.log",
	'append'   => 1,
	'addtime'  => 1,
	'stderr'   => $verbose ? 1 : null,
));
$log->LOGSTART('CKW Preisabruf');

$ckwcfg = ckw_load_config();
$log->DEB('Konfiguration: ' . json_encode($ckwcfg));

try {
	$ok = ckw_run($ckwcfg, $log);
} catch (Throwable $e) {
	$log->CRIT('Unerwarteter Fehler: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
	$ok = false;
}

$log->LOGEND($ok ? 'Abruf erfolgreich' : 'Abruf mit Fehlern beendet');
flock($lock, LOCK_UN);
exit($ok ? 0 : 1);
