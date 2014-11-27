# Dibi Migrations
You can install this over composer

```
composer require doublemcz/dibi-migrations
```

In your migrate script or bootstrap include following code
```
\doublemcz\DibiMigrations\Engine::handle($dibiConnection, $migrationsDir, $tempDir);
```

The First parameter is DibiConnection instance. It is obvious what it does :)
The Second parameter is migrations dir. Migrations folder consist of identificators of people who develop new code. You must use something unique for each developer.
The structure of migration dir should looks as follows:
```
 app =>
  doublem =>
    2014_12_24_18_00.sql
    2014_12_31_23_00.sql
  foglcz =>
    2015_01_01_17_05.sql
```

You can see two migrations from user martinm. The first one was created at 18:00 on 24.12.2014. Another user pavelp created migration on 1.1.2015 at 17:05.

It is really necessary to keep valid dates because if you do a deploy into production the Engine sorts the migrations and deploy them in a row in right order.

Temp dir is needed to save temporary migration folder status file. It holds the count of migrations and lock file whe an upgrade is running.

I recommend you to look into ./example/index.php.

At the end I would like to thank to David Grudl who is founder of dibi library.
