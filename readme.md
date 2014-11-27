# Dibi Migrations
You can install this over composer

#### Installation
```
composer require doublemcz/dibi-migrations
```

#### Basic usage
Include following code into your script where you would like to migrate database
```
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

$engine = new doublemcz\DibiMigrations\Engine($dibiConnection, $migrationsPath, $tempDirectory);
$engine->process();
```

#### Usage in Nette 2.2
You can use Nette Extension to initialize Engine and run migrations automatically. Into your config.neon add following line
```
extensions:
 dibiMigrations: Doublemcz\DibiMigrations\DibiMigrationsNetteExtension22
```

#### Creating migrations
Migration folder consist of identificators of people who develop new code. You must use something unique for each developer.
The structure of migration dir should looks as follows:
```
 app =>
  doublem =>
    2014_12_24_18_00.sql
    2014_12_31_23_00.sql
  foglcz =>
    2015_01_01_17_05.sql
```

You can see two migrations from user **doublem**. The first one was created at 18:00 on 24.12.2014. Another user **foglcz** created migration on 1.1.2015 at 17:05.

It is really necessary to keep valid dates because if you do a deploy into production the Engine sorts the migrations and deploy them in right order.

Temp dir is needed to save temporary migration folder status file. It holds the count of migrations and lock file. Migration status folder (db-migration.dat) has count of files. Migration lock file (db-migration.lock) is created while an upgrade is running.

I recommend you to look into ./example/index.php.

At the end I would like to thank to David Grudl who is founder of dibi library.
