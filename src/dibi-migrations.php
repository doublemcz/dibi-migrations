<?php
require_once __DIR__ . '/lib/Engine.php';

if (class_exists('Nette\DI\Container')) {
	require_once __DIR__ . '/lib/DibiMigrationsExtension22.php';
}