<?php
/**
 * Import source acquisition and storage.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Infrastructure;

use InvalidArgumentException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use RuntimeException;

/**
 * Materializes upload, HTTPS, and SFTP sources into protected local snapshots.
 */
final class SourceManager {
	private const MAX_BYTES        = 1073741824;
	public const MAX_PREVIEW_BYTES = 8388608;

	public function __construct( private SecretBox $secrets ) {}

	/**
	 * Store an administrator-uploaded CSV or XML source.
	 *
	 * @param array<string,mixed> $file A $_FILES entry.
	 */
	public function store_upload( array $file ): string {
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			throw new InvalidArgumentException( 'The source upload failed.' );
		}
		$size = (int) ( $file['size'] ?? 0 );
		if ( $size < 1 || $size > $this->max_bytes() ) {
			throw new InvalidArgumentException( 'The source file is empty or exceeds the configured size limit.' );
		}
		$extension = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'xml' ), true ) ) {
			throw new InvalidArgumentException( 'Only CSV and XML files are accepted.' );
		}

		$storage = Installer::storage_dir();
		if ( ! wp_mkdir_p( $storage ) ) {
			throw new RuntimeException( 'The protected upload directory could not be created.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$upload              = $file;
		$upload['name']      = gmdate( 'Ymd-His' ) . '-' . wp_generate_uuid4() . '.' . $extension;
		$upload_dir_override = static function ( array $directories ) use ( $storage ): array {
			$directories['path']    = $storage;
			$directories['basedir'] = $storage;
			$directories['subdir']  = '';
			$directories['url']     = '';
			$directories['baseurl'] = '';
			$directories['error']   = false;
			return $directories;
		};

		add_filter( 'upload_dir', $upload_dir_override );
		try {
			$result = wp_handle_upload(
				$upload,
				array(
					'test_form' => false,
					'mimes'     => array(
						'csv' => 'text/csv',
						'xml' => 'text/xml',
					),
				)
			);
		} finally {
			remove_filter( 'upload_dir', $upload_dir_override );
		}

		if ( ! is_array( $result ) || ! empty( $result['error'] ) || empty( $result['file'] ) ) {
			throw new RuntimeException( 'The uploaded source could not be stored.' );
		}

		$destination = Installer::resolve_storage_file( (string) $result['file'] );
		if ( null === $destination ) {
			wp_delete_file( (string) $result['file'] );
			throw new RuntimeException( 'The uploaded source was stored outside the protected directory.' );
		}

		$this->assert_size( $destination );
		return $destination;
	}

	/**
	 * Create an immutable source copy for a job.
	 *
	 * @param array<string,mixed> $config Preset configuration.
	 */
	public function materialize( array $config ): string {
		$source = is_array( $config['source'] ?? null ) ? $config['source'] : array();
		return match ( $source['type'] ?? 'upload' ) {
			'upload' => $this->copy_upload( (string) ( $source['upload_path'] ?? '' ) ),
			'https' => $this->download_https( $source ),
			'sftp' => $this->download_sftp( $source ),
			default => throw new InvalidArgumentException( 'Unsupported source type.' ),
		};
	}

	/**
	 * Materialize only the bounded prefix required for source previews.
	 *
	 * @param array<string,mixed> $config Preset configuration.
	 * @return array{path:string,truncated:bool,hash_scope:string,size:int,hash:string}
	 */
	public function preview( array $config ): array {
		$source = is_array( $config['source'] ?? null ) ? $config['source'] : array();
		return match ( $source['type'] ?? 'upload' ) {
			'upload' => $this->preview_upload( (string) ( $source['upload_path'] ?? '' ) ),
			'https' => $this->preview_https( $source ),
			'sftp' => $this->preview_sftp( $source ),
			default => throw new InvalidArgumentException( 'Unsupported source type.' ),
		};
	}

	/**
	 * Copy a stored upload so each job processes an immutable snapshot.
	 */
	private function copy_upload( string $path ): string {
		$path = Installer::resolve_storage_file( $path );
		if ( null === $path || ! is_readable( $path ) ) {
			throw new InvalidArgumentException( 'No valid uploaded source is configured.' );
		}
		$destination = $this->new_path( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) );
		if ( ! copy( $path, $destination ) ) {
			throw new RuntimeException( 'Unable to copy the uploaded source.' );
		}
		$this->assert_size( $destination );
		return $destination;
	}

	/**
	 * Copy at most the preview ceiling from a stored upload.
	 *
	 * @return array{path:string,truncated:bool,hash_scope:string,size:int,hash:string}
	 */
	private function preview_upload( string $path ): array {
		$path = Installer::resolve_storage_file( $path );
		if ( null === $path || ! is_readable( $path ) ) {
			throw new InvalidArgumentException( 'No valid uploaded source is configured.' );
		}
		$size = filesize( $path );
		if ( false === $size || $size < 1 ) {
			throw new InvalidArgumentException( 'The source file is empty.' );
		}
		$destination = $this->new_path( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) );
		$this->copy_prefix( $path, $destination, self::MAX_PREVIEW_BYTES );
		return $this->preview_result( $destination, $size > self::MAX_PREVIEW_BYTES, $size );
	}

	/**
	 * Download an HTTPS source with a strict preview response limit.
	 *
	 * @param array<string,mixed> $source Source configuration.
	 * @return array{path:string,truncated:bool,hash_scope:string,size:int,hash:string}
	 */
	private function preview_https( array $source ): array {
		$url = (string) ( $source['url'] ?? '' );
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! wp_http_validate_url( $url ) ) {
			throw new InvalidArgumentException( 'A public HTTPS source URL is required.' );
		}
		$destination = $this->new_path( $this->extension( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$response    = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 30,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $destination,
				'limit_response_size' => self::MAX_PREVIEW_BYTES + 1,
				'headers'             => $this->https_headers( $source ),
				'user-agent'          => 'TDS-Product-Importer/' . TDS_IMPORTER_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $destination );
			throw new RuntimeException( 'HTTPS source preview failed: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$size   = is_file( $destination ) ? filesize( $destination ) : false;
		if ( $status < 200 || $status >= 300 || false === $size || $size < 1 ) {
			wp_delete_file( $destination );
			throw new RuntimeException( 'HTTPS source returned status ' . $status . '.' );
		}
		$content_length = (int) wp_remote_retrieve_header( $response, 'content-length' );
		$truncated      = $size > self::MAX_PREVIEW_BYTES || $content_length > self::MAX_PREVIEW_BYTES;
		if ( $size > self::MAX_PREVIEW_BYTES ) {
			$this->truncate( $destination, self::MAX_PREVIEW_BYTES );
		}
		$source_size = $content_length > 0 ? max( $content_length, $size ) : null;
		return $this->preview_result( $destination, $truncated, $source_size );
	}

	/**
	 * Download a bounded SFTP prefix for previewing.
	 *
	 * @param array<string,mixed> $source Source configuration.
	 * @return array{path:string,truncated:bool,hash_scope:string,size:int,hash:string}
	 */
	private function preview_sftp( array $source ): array {
		$sftp        = $this->sftp_connection( $source );
		$remote_path = (string) ( $source['remote_path'] ?? '' );
		$size        = $sftp->filesize( $remote_path );
		if ( ! is_int( $size ) || $size < 1 || $size > $this->max_bytes() ) {
			throw new RuntimeException( 'SFTP source size is invalid or exceeds the limit.' );
		}
		$destination = $this->new_path( $this->extension( $remote_path ) );
		$length      = min( $size, self::MAX_PREVIEW_BYTES );
		if ( ! $sftp->get( $remote_path, $destination, 0, $length ) ) {
			wp_delete_file( $destination );
			throw new RuntimeException( 'SFTP source preview failed.' );
		}
		return $this->preview_result( $destination, $size > self::MAX_PREVIEW_BYTES, $size );
	}

	/**
	 * Download an HTTPS source through the SSRF-safe WordPress client.
	 *
	 * @param array<string,mixed> $source Source configuration.
	 */
	private function download_https( array $source ): string {
		$url = (string) ( $source['url'] ?? '' );
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! wp_http_validate_url( $url ) ) {
			throw new InvalidArgumentException( 'A public HTTPS source URL is required.' );
		}
		$extension   = $this->extension( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$destination = $this->new_path( $extension );
		$max_bytes   = $this->max_bytes();
		$response    = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $destination,
				// Read one sentinel byte so an oversized response cannot look complete.
				'limit_response_size' => $max_bytes + 1,
				'headers'             => $this->https_headers( $source ),
				'user-agent'          => 'TDS-Product-Importer/' . TDS_IMPORTER_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $destination );
			throw new RuntimeException( 'HTTPS source download failed: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 || ! is_readable( $destination ) ) {
			wp_delete_file( $destination );
			throw new RuntimeException( 'HTTPS source returned status ' . $status . '.' );
		}
		$content_length = (int) wp_remote_retrieve_header( $response, 'content-length' );
		$size           = filesize( $destination );
		if ( $content_length > $max_bytes || false === $size || $size > $max_bytes ) {
			wp_delete_file( $destination );
			throw new RuntimeException( 'HTTPS source exceeds the configured size limit.' );
		}
		$this->assert_size( $destination );
		return $destination;
	}

	/**
	 * Download through an authenticated, host-key-pinned SFTP connection.
	 *
	 * @param array<string,mixed> $source Source configuration.
	 */
	private function download_sftp( array $source ): string {
		$remote_path = (string) ( $source['remote_path'] ?? '' );
		$sftp        = $this->sftp_connection( $source );
		$size        = $sftp->filesize( $remote_path );
		if ( ! is_int( $size ) || $size < 1 || $size > $this->max_bytes() ) {
			throw new RuntimeException( 'SFTP source size is invalid or exceeds the limit.' );
		}
		$destination = $this->new_path( $this->extension( $remote_path ) );
		if ( ! $sftp->get( $remote_path, $destination ) ) {
			wp_delete_file( $destination );
			throw new RuntimeException( 'SFTP source download failed.' );
		}
		$this->assert_size( $destination );
		return $destination;
	}

	/**
	 * Build optional HTTP authentication headers.
	 *
	 * @param array<string,mixed> $source Source configuration.
	 * @return array<string,string>
	 */
	private function https_headers( array $source ): array {
		$headers = array();
		if ( ! empty( $source['basic_username'] ) ) {
			$password                 = $this->decrypt( (string) ( $source['basic_password'] ?? '' ) );
			$headers['Authorization'] = 'Basic ' . base64_encode( (string) $source['basic_username'] . ':' . $password );
		}
		return $headers;
	}

	/**
	 * Open and authenticate a host-key-pinned SFTP connection.
	 *
	 * @param array<string,mixed> $source Source configuration.
	 */
	private function sftp_connection( array $source ): SFTP {
		if ( ! class_exists( SFTP::class ) ) {
			throw new RuntimeException( 'SFTP support is unavailable. Install Composer production dependencies.' );
		}
		$host        = (string) ( $source['host'] ?? '' );
		$remote_path = (string) ( $source['remote_path'] ?? '' );
		$fingerprint = (string) ( $source['fingerprint'] ?? '' );
		if ( '' === $host || '' === $remote_path || '' === $fingerprint ) {
			throw new InvalidArgumentException( 'SFTP host, path, and host-key fingerprint are required.' );
		}
		$sftp       = new SFTP( $host, (int) ( $source['port'] ?? 22 ), 30 );
		$server_key = $sftp->getServerPublicHostKey();
		if ( ! is_string( $server_key ) ) {
			throw new RuntimeException( 'Unable to obtain the SFTP host key.' );
		}
		if ( ! $this->valid_fingerprint( $server_key, $fingerprint ) ) {
			throw new RuntimeException( 'SFTP host-key fingerprint mismatch.' );
		}

		$username = (string) ( $source['username'] ?? '' );
		$private  = $this->decrypt( (string) ( $source['private_key'] ?? '' ) );
		$password = $this->decrypt( (string) ( $source['password'] ?? '' ) );
		$key      = '' === $private ? null : ( '' === $password ? PublicKeyLoader::loadPrivateKey( $private ) : PublicKeyLoader::loadPrivateKey( $private, $password ) );
		$login    = null !== $key
			? $sftp->login( $username, $key )
			: $sftp->login( $username, $password );
		if ( ! $login ) {
			throw new RuntimeException( 'SFTP authentication failed.' );
		}
		return $sftp;
	}

	/**
	 * Stream a bounded local source prefix into protected temporary storage.
	 */
	private function copy_prefix( string $source, string $destination, int $limit ): void {
		$input  = fopen( $source, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$output = fopen( $destination, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $input || false === $output ) {
			if ( is_resource( $input ) ) {
				fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( is_resource( $output ) ) {
				fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			wp_delete_file( $destination );
			throw new RuntimeException( 'Unable to create a source preview.' );
		}
		$remaining = $limit;
		$failed    = false;
		while ( $remaining > 0 && ! feof( $input ) ) {
			$data = fread( $input, min( 65536, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $data ) {
				$failed = true;
				break;
			}
			$written = fwrite( $output, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === $written || strlen( $data ) !== $written ) {
				$failed = true;
				break;
			}
			$remaining -= $written;
		}
		fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( $failed ) {
			wp_delete_file( $destination );
			throw new RuntimeException( 'Unable to read the source preview.' );
		}
	}

	/**
	 * Build preview metadata after a bounded transfer.
	 *
	 * @return array{path:string,truncated:bool,hash_scope:string,size:int,hash:string}
	 */
	private function preview_result( string $path, bool $truncated, ?int $source_size = null ): array {
		$preview_size = filesize( $path );
		if ( false === $preview_size || $preview_size < 1 ) {
			wp_delete_file( $path );
			throw new RuntimeException( 'The source preview is empty.' );
		}
		return array(
			'path'       => $path,
			'truncated'  => $truncated,
			'hash_scope' => $truncated ? 'prefix' : 'full',
			'size'       => $source_size ?? $preview_size,
			'hash'       => (string) hash_file( 'sha256', $path ),
		);
	}

	/**
	 * Truncate a temporary preview to its documented byte ceiling.
	 */
	private function truncate( string $path, int $bytes ): void {
		$handle = fopen( $path, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle || ! ftruncate( $handle, $bytes ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			wp_delete_file( $path );
			throw new RuntimeException( 'Unable to bound the source preview.' );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Decrypt an optional credential.
	 */
	private function decrypt( string $value ): string {
		return '' === $value ? '' : $this->secrets->decrypt( $value );
	}

	/**
	 * Allocate an unpredictable path in protected storage.
	 */
	private function new_path( string $extension ): string {
		wp_mkdir_p( Installer::storage_dir() );
		return trailingslashit( Installer::storage_dir() ) . gmdate( 'Ymd-His' ) . '-' . wp_generate_uuid4() . '.' . $extension;
	}

	/**
	 * Permit operators to reduce, but not exceed, the one GiB hard ceiling.
	 */
	private function max_bytes(): int {
		return max( 1048576, min( self::MAX_BYTES, (int) apply_filters( 'tds_importer_max_source_bytes', self::MAX_BYTES ) ) );
	}

	/**
	 * Normalize the source extension.
	 */
	private function extension( string $path ): string {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $extension, array( 'csv', 'xml' ), true ) ? $extension : 'data';
	}

	/**
	 * Verify size after transfer.
	 */
	private function assert_size( string $path ): void {
		$size = filesize( $path );
		if ( false === $size || $size < 1 || $size > $this->max_bytes() ) {
			wp_delete_file( $path );
			throw new RuntimeException( 'Downloaded source size is invalid.' );
		}
	}

	/**
	 * Verify standard OpenSSH SHA256/MD5 fingerprints and legacy hex hashes.
	 */
	private function valid_fingerprint( string $public_key, string $expected ): bool {
		$parts    = preg_split( '/\s+/', trim( $public_key ) ) ?: array();
		$blob     = count( $parts ) >= 2 ? base64_decode( $parts[1], true ) : false;
		$blob     = false === $blob ? $public_key : $blob;
		$md5      = md5( $blob );
		$valid    = array(
			'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' ),
			implode( ':', str_split( $md5, 2 ) ),
			$md5,
			hash( 'sha256', $public_key ),
		);
		$expected = trim( $expected );
		foreach ( $valid as $candidate ) {
			$matches = str_starts_with( $candidate, 'SHA256:' )
				? hash_equals( $candidate, $expected )
				: hash_equals( strtolower( $candidate ), strtolower( $expected ) );
			if ( $matches ) {
				return true;
			}
		}
		return false;
	}
}
