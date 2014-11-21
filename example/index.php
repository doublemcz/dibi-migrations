<?php
define('MAINTENANCE_FILE', __DIR__ . '/@maintenance.php');
if (FALSE !== file_exists(__DIR__ . '/maintenance.php')) {
	require __DIR__ . '/maintenance.php';
	exit;
}

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

$engine = new doublemcz\DibiMigrations\Engine($dibiConnection, $migrationsPath, $tempDirectory, MAINTENANCE_FILE);
$engine->process();

echo "Migrations has been applied";