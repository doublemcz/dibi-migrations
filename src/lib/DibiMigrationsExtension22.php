<?php
namespace Doublemcz\DibiMigrations;

use Nette;

class DibiMigrationsNetteExtension22 extends Nette\DI\CompilerExtension
{
	/**
	 * Default parameters
	 *
	 * @var array
	 */
	public $defaults = array(
		'connection' => '@dibi.connection',
		'migrationsDir' => '%appDir%/migrations',
		'tempDir' => '%tempDir%',
		'runAutomatically' => TRUE,
	);

	/**
	 * Processes configuration data. Intended to be overridden by descendant.
	 * @return void
	 */
	public function loadConfiguration()
	{
		$config = $this->getConfig($this->defaults);
		$builder = $this->getContainerBuilder();
		$service = $builder->addDefinition($this->prefix('dibiMigrations'))
			->setClass(
				'Doublemcz\DibiMigrations\Engine',
				array(
					$config['connection'],
					$config['migrationsDir'],
					$config['tempDir']
				)
			);

		if ($config['runAutomatically']) {
			$service->addSetup('process');
		}
	}

}