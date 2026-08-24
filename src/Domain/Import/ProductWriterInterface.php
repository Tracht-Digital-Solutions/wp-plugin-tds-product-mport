<?php
/**
 * Product writer contract used by the resumable job engine.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Import;

interface ProductWriterInterface {
	public function reset_caches(): void;

	/**
	 * @param array<string,mixed> $fields Mapped values.
	 * @param array<string,mixed> $item   Staged item.
	 * @param array<string,mixed> $preset Preset.
	 * @return array{product_id:int,result:string}
	 */
	public function write( array $fields, array $item, array $preset, int $job_id ): array;

	/**
	 * @param array<string,mixed> $fields Mapped values.
	 */
	public function apply_relationships( int $product_id, array $fields, int $preset_id, int $job_id ): void;
}
