<?php

namespace Wikimedia\Composer;

/**
 * Reads an installed.json file and provides accessors to get what is
 * installed
 *
 * @since 1.27
 */
class ComposerInstalled {
	/**
	 * @var array[]
	 */
	private $contents;

	/**
	 * @param string $location
	 */
	public function __construct( $location ) {
		$this->contents = json_decode( file_get_contents( $location ), true );
	}

	/**
	 * Dependencies currently installed according to installed.json
	 *
	 * @return array[]
	 */
	public function getInstalledDependencies() {
		$contents = $this->contents['packages'];

		$deps = [];
		foreach ( $contents as $installed ) {
			$deps[$installed['name']] = [
				'version' => ComposerJson::normalizeVersion( $installed['version'] ),
				'type' => $installed['type'],
				'licenses' => $installed['license'] ?? [],
				'authors' => $installed['authors'] ?? [],
				'description' => $installed['description'] ?? '',
			];
		}

		ksort( $deps );
		return $deps;
	}
}
