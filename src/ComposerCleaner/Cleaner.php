<?php

/**
 * Victor The Cleaner for Composer.
 *
 * Copyright (c) 2015 David Grudl (https://davidgrudl.com)
 */

namespace DG\ComposerCleaner;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use FilesystemIterator;
use stdClass;


class Cleaner
{
	/** @var IOInterface */
	private $io;

	/** @var Filesystem */
	private $fileSystem;

	/** @var int */
	private $removedCount = 0;

	/** @var array */
	private static $allowedComposerTypes = [NULL, 'library', 'composer-plugin'];


	public function __construct(IOInterface $io, Filesystem $fileSystem)
	{
		$this->io = $io;
		$this->fileSystem = $fileSystem;
	}


	/**
	 * @return void
	 */
	public function clean($vendorDir, array $ignorePaths = [])
	{
		foreach (new FileSystemIterator($vendorDir) as $packageVendor) {
			if (!$packageVendor->isDir()) {
				continue;
			}
			foreach (new FileSystemIterator($packageVendor) as $packageName) {
				if (!$packageName->isDir()) {
					continue;
				}
				$name = $packageVendor->getFileName() . '/' . $packageName->getFileName();
				$ignore = isset($ignorePaths[$name]) ? (array) $ignorePaths[$name] : [];
				$this->io->write("Package $name", TRUE, IOInterface::VERBOSE);
				$this->processPackage((string) $packageName, $ignore);
			}
		}
		$this->io->write("Removed $this->removedCount files or directories.");
	}


	/**
	 * @return void
	 */
	private function processPackage($packageDir, array $ignorePaths)
	{
		$data = $this->loadComposerJson($packageDir);
		$type = isset($data->type) ? $data->type : NULL;
		if (!$data || !in_array($type, self::$allowedComposerTypes, TRUE)) {
			return;
		}

		$paths = array_fill_keys($ignorePaths, TRUE);
		foreach ($this->getSources($data) as $source) {
			$dir = strstr(ltrim(ltrim($source, '.'), '/') . '/', '/', TRUE);
			$paths[$dir] = TRUE;
		}

		if (!$paths || isset($paths[''])) {
			return;
		}

		$paths['composer.json'] = TRUE;

		foreach (new FileSystemIterator($packageDir) as $path) {
			$fileName = $path->getFileName();
			if (!isset($paths[$fileName]) && strncasecmp($fileName, 'license', 7)) {
				$this->io->write("Removing $path", TRUE, IOInterface::VERBOSE);
				$this->fileSystem->remove($path);
				$this->removedCount++;
			}
		}
	}


	/**
	 * @return string[]
	 */
	private function getSources(stdClass $data)
	{
		if (empty($data->autoload)) {
			return [];
		}

		$sources = isset($data->bin) ? (array) $data->bin : [];

		foreach ($data->autoload as $type => $items) {
			if ($type === 'psr-0') {
				foreach ($items as $namespace => $paths) {
					foreach ((array) $paths as $path) {
						$sources[] = $path . strtr($namespace, '\\_', '//');
					}
				}

			} elseif ($type === 'psr-4') {
				foreach ($items as $namespace => $paths) {
					$sources = array_merge($sources, (array) $paths);
				}

			} elseif ($type === 'classmap' || $type === 'files') {
				$sources = array_merge($sources, (array) $items);

			} elseif ($type === 'exclude-from-classmap') {
				// ignore

			} else {
				$this->io->writeError("unknown autoload type $type");
				return [];
			}
		}

		return $sources;
	}


	/**
	 * @return stdClass|NULL
	 */
	public function loadComposerJson($dir)
	{
		$file = $dir . '/composer.json';
		if (!is_file($file)) {
			$this->io->writeError("File $file not found.", TRUE, IOInterface::VERBOSE);
			return;
		}
		$data = json_decode(file_get_contents($file));
		if (!$data instanceof stdClass) {
			$this->io->writeError("Invalid $file.");
			return;
		}
		return $data;
	}

}
