<?php
require __DIR__ . '/composer/vendor/autoload.php';
require __DIR__ . '/../src/dibi-migrations.php';

Tracy\Debugger::enable();

$config = array(
	'driver' => 'mysql',
	'host' => 'localhost',
	'user' => 'root',
	'password' => '',
	'database' => 'dibi-migrations',
);
$dibiConnection = new DibiConnection($config);
$migrationsPath = __DIR__ . '/migrations';
$tempDirectory = __DIR__ . '/temp';
$maintenanceFile = __DIR__ . '/@maintenance.php';
$engine = new doublemcz\DibiMigrations\Engine($dibiConnection, $migrationsPath, $tempDirectory, $maintenanceFile);
$engine->process();