<?php
if (!is_file(__DIR__ . '/vendor/autoload.php')) {
	die("You need to run composer install!");
}

require __DIR__ . '/vendor/autoload.php';
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