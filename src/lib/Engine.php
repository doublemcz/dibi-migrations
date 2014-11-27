<?php
namespace Doublemcz\DibiMigrations;

use \DibiConnection;

class Engine
{
	/** @var DibiConnection */
	private $databaseConnection;
	/** @var string */
	private $databaseVersionTable = '_database_version';
	/** @var string */
	private $migrationsDirectory;

	/**
	 * @param DibiConnection $dibiConnection
	 * @param string $migrationsFolder
	 * @param string $tempDirectory
	 * @throws \RuntimeException
	 */
	public function __construct(DibiConnection $dibiConnection, $migrationsFolder, $tempDirectory)
	{
		if (!is_dir($tempDirectory) && !mkdir($tempDirectory, 0777, TRUE)) {
			throw new \RuntimeException(sprintf('Cannot create a temp dir: %s', $tempDirectory));
		}

		if (!is_dir($migrationsFolder)) {
			throw new \RuntimeException(sprintf('Migration folder does not exists: %s', $migrationsFolder));
		}

		$this->databaseConnection = $dibiConnection;
		$this->migrationsDirectory = $migrationsFolder;
		$this->tempDirectory = $tempDirectory;
	}

	/**
	 * @param DibiConnection $dibiConnection
	 * @param string $migrationsFolder
	 * @param string $tempDirectory
	 */
	public static function handle(DibiConnection $dibiConnection, $migrationsFolder, $tempDirectory)
	{
		$instance = new self($dibiConnection, $migrationsFolder, $tempDirectory);
		$instance->process();
	}

	/**
	 * @throws \Exception
	 * @return void
	 */
	public function process()
	{
		if ($this->isApplicationLocked()) {
			return;
		}

		$availableFiles = $this->getAvailableFiles($this->migrationsDirectory);
		if (!$this->isMigrationNeeded(count($availableFiles))) {
			return;
		}

		// Again ask for if locked. If migrations are used on every page load than
		// the counting of files can be long
		if ($this->isApplicationLocked()) {
			return;
		}

		$this->lockApplication();
		$this->handleMigrationTable();
		$filesToBeMigrated = $this->getNonMigratedFiles($availableFiles);
		// Order by date to get right migration order
		usort($filesToBeMigrated, array($this, 'sortFiles'));
		if (!empty($filesToBeMigrated)) {
			$this->migrateFiles($filesToBeMigrated);
		}

		$this->setTemporaryFileContent(count($availableFiles));
		$this->unlockApplication();

		return;
	}

	private function getNonMigratedFiles($availableFiles)
	{
		$databaseData = $this->fetchDatabaseData();

		if (!$databaseData) {
			return $availableFiles;
		}

		foreach ($databaseData as $key => $lastMigrationDateStamp) {
			$databaseData[$key] = \DateTime::createFromFormat('Y_m_d_H_i', $lastMigrationDateStamp);
		}

		$filesToBeMigrated = array();
		foreach ($availableFiles as $file) {
			$fileMigrationDate = \DateTime::createFromFormat('Y_m_d_H_i', $file['file']);
			if (!array_key_exists($file['user'], $databaseData) || $databaseData[$file['user']] < $fileMigrationDate) {
				$filesToBeMigrated[] = $file;
			}
		}

		return $filesToBeMigrated;
	}

	/**
	 * Fetch actual database version for each use
	 *
	 * @return \DibiRow[]
	 */
	private function fetchDatabaseData()
	{
		return $this->databaseConnection->select('user, version')
			->from($this->databaseVersionTable)
			->fetchPairs('user', 'version');
	}

	/**
	 * @param int $filesOnFileSystem
	 * @return bool
	 */
	private function isMigrationNeeded($filesOnFileSystem)
	{
		if ($filesOnFileSystem > 0 && !file_exists($this->getTemporaryDataFile())) {
			return TRUE;
		}

		return $this->getCountInTemporaryFile() < $filesOnFileSystem;
	}

	/**
	 * @return bool
	 */
	private function isApplicationLocked()
	{
		return TRUE === file_exists($this->getLockFilePath());
	}

	/**
	 * Create a file in temp directory
	 */
	private function lockApplication()
	{
		if (FALSE === touch($this->getLockFilePath())) {
			throw new \RuntimeException('Cannot lock application for database migration');
		}
	}

	/**
	 * Removes the file in temp directory
	 */
	private function unlockApplication()
	{
		unlink($this->getLockFilePath());
	}

	/**
	 * @param array $filesToBeMigrated
	 * @throws \Exception
	 */
	private function migrateFiles($filesToBeMigrated)
	{
		foreach ($filesToBeMigrated as $file) {
			$filename = $file['file'] . '.sql';
			$filePath = $this->getMigrationFolder() . '/' . $file['user'] . '/' . $filename;
			if (TRUE === file_exists($filePath)) {
				$this->databaseConnection->loadFile($filePath);
				$this->setLastMigration($file['user'], $file['file']);
			} else {
				throw new \Exception(sprintf('The migration file %s does not exist.', $filePath));
			}
		}
	}

	/**
	 * Set last migrated version for user
	 * @param $user
	 * @param $version
	 */
	public function setLastMigration($user, $version)
	{
		$column = $this->databaseConnection
			->select('count(1)')
			->from($this->databaseVersionTable)
			->where('user = %s', $user)
			->fetchSingle();

		$data = array(
			'version' => $version,
			'date' => date("Y-m-d H:i:s")
		);

		if (!$column) {
			$data['user'] = $user;
			$this->databaseConnection->insert($this->databaseVersionTable, $data)->execute();
		} else {
			$this->databaseConnection->update($this->databaseVersionTable, $data)->where(array('user' => $user))->execute();
		}
	}

	/**
	 * @param string $folder
	 * @return array
	 */
	private function getAvailableFiles($folder)
	{
		$files = array();
		/** @var \DirectoryIterator $userFolder */
		foreach (new \DirectoryIterator($folder) as $userFolder) {
			if (!$userFolder->isDir()) {
				continue;
			}

			$userId = $userFolder->getBasename();
			/** @var \DirectoryIterator $file */
			foreach (new \DirectoryIterator($userFolder->getPathName()) as $file) {
				$baseName = $file->getBasename();
				if (pathinfo($baseName, PATHINFO_EXTENSION) != 'sql') {
					continue;
				}

				$files[] = array(
					'file' => pathinfo($baseName, PATHINFO_FILENAME),
					'user' => $userId,
				);
			}
		}

		return $files;
	}

	/**
	 * @param string $fileItem
	 * @param string $fileItem2
	 * @return int
	 */
	private function sortFiles($fileItem, $fileItem2)
	{
		return strnatcmp($fileItem['file'], $fileItem2['file']);
	}

	/**
	 * Creates table used for database migration if needed
	 *
	 * @return void
	 */
	private function handleMigrationTable()
	{
		if ($this->getCountInTemporaryFile()) {
			return;
		}

		$this->databaseConnection->query('
			CREATE TABLE IF NOT EXISTS ' . $this->databaseVersionTable . ' (
			   user VARCHAR(20) NOT NULL PRIMARY KEY,
				version CHAR(16) NOT NULL,
				date DATETIME NOT NULL
			);
		');
	}

	/**
	 * @param $directoryName
	 */
	public function setMigrationsDirectory($directoryName)
	{
		$this->migrationsDirectory = $directoryName;
	}

	/**
	 * @return int
	 */
	private function getCountInTemporaryFile()
	{
		return (int)@file_get_contents($this->getTemporaryDataFile());
	}

	/**
	 * @param string $data
	 * @return int|FALSE
	 */
	private function setTemporaryFileContent($data)
	{
		return file_put_contents($this->getTemporaryDataFile(), $data);
	}

	/**
	 * @return string
	 */
	private function getTemporaryDataFile()
	{
		return $this->tempDirectory . '/db-migration.dat';
	}

	/**
	 * @return string
	 */
	private function getMigrationFolder()
	{
		return $this->migrationsDirectory;
	}

	/**
	 * @return string
	 */
	private function getLockFilePath()
	{
		return $this->tempDirectory . '/db-migration.lock';
	}
}