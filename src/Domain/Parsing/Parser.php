<?php
/**
 * Streaming parser contract.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Parsing;

use Generator;

/**
 * Parses source records without loading the complete source into memory.
 */
interface Parser {
	/**
	 * Yield normalized source rows.
	 *
	 * @param array<string,mixed> $config Preset configuration.
	 * @return Generator<int,array<string,mixed>>
	 */
	public function records( string $path, array $config ): Generator;
}
