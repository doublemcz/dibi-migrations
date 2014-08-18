<?php
namespace doublemcz\DibiMigrations;

use DibiConnection;

class Engine
{
	/** @var DibiConnection */
	private $databaseConnection;
	/** @var string */
	private $databaseVersionTable = '_database_version';
	/** @var string */
	private $migrationsDirectory;
	/** @var string|null */
	private $maintenanceFile = NULL;

	/**
	 * @param DibiConnection $dibiConnection
	 * @param string $migrationsFolder
	 * @param string $tempDirectory
	 * @param string|null $maintenanceFile
	 * @throws \RuntimeException
	 */
	public function __construct(DibiConnection $dibiConnection, $migrationsFolder, $tempDirectory, $maintenanceFile = NULL)
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
		$this->maintenanceFile = $maintenanceFile;
	}

	public static function handle(DibiConnection $dibiConnection, $migrationsFolder, $tempDirectory)
	{
		$instance = new self($dibiConnection, $migrationsFolder, $tempDirectory);
		$instance->process();
	}

	/**
	 * @throws \Exception
	 */
	public function process()
	{
		if ($this->isApplicationLocked()) {
			return FALSE;
		}

		$folder = $this->getMigrationFolder();
		$availableFiles = $this->getAvailableFiles($folder);
		$filesCount = count($availableFiles);

		if (!$this->isMigrationNeeded($filesCount)) {
			return FALSE;
		}

		$this->handleMigrationTable();
		$databaseData = $this->fetchDatabaseData();
		$filesToBeMigrated = $this->getNonMigratedFiles($databaseData, $availableFiles);
		// Order by date to get right migration order
		usort($filesToBeMigrated, array($this, 'sortFiles'));
		if (!empty($filesToBeMigrated)) {
			$this->migrateFiles($filesToBeMigrated);
		}

		$this->setTemporaryFileContent($filesCount);

		return TRUE;
	}

	private function getNonMigratedFiles($databaseData, $availableFiles)
	{
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
	 * @param int $countOfUserFiles
	 * @return bool
	 * @throws \RuntimeException
	 */
	private function isMigrationNeeded($countOfUserFiles)
	{
		if (!file_exists($this->getTemporaryDataFile())) {
			return TRUE;
		}

		$lastMigrationCount = $this->getCountInTemporaryFile();
		if ($countOfUserFiles < $lastMigrationCount) {
			throw new \RuntimeException('Count in temp data file is higher than real migration files. A programmer removed one or more migration files.');
		}

		if ($lastMigrationCount < $countOfUserFiles) {
			return TRUE;
		}

		return FALSE;
	}

	private function isApplicationLocked()
	{
		// Lock application if is not locked
		if (is_file($this->maintenanceFile)) {
			return TRUE;
		}

		return FALSE;
	}

	/**
	 * On windows we have to copy the file. On linux we can create symlink - it is faster and more secure
	 */
	private function lockApplication()
	{
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			copy($this->getLockFilePath(), $this->getLockFilePath(TRUE));
		} else {
			symlink($this->getLockFilePath(), $this->getLockFilePath(TRUE));
		}
	}

	private function unlockApplication()
	{
		unlink($this->getLockFilePath(TRUE));
	}

	private function getLockFilePath($isLocked = FALSE)
	{
		// TODO is locked
		return FALSE;
		///return $isLocked ? (WWW_DIR . '/maintenance.php') : (WWW_DIR . '/@maintenance.php');
	}

	private function migrateFiles($filesToBeMigrated)
	{
		if ($this->isApplicationLocked()) {
			return FALSE;
		}

		$this->lockApplication();

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

		$this->unlockApplication();
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
		return $this->tempDirectory . '/migrations.dat';
	}

	/**
	 * @return string
	 */
	private function getMigrationFolder()
	{
		return $this->migrationsDirectory;
	}
}