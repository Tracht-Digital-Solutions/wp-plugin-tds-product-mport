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
	private const MAX_BYTES = 1073741824;

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
		$destination = $this->new_path( $extension );
		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) || ! move_uploaded_file( (string) $file['tmp_name'], $destination ) ) {
			throw new RuntimeException( 'The uploaded source could not be stored.' );
		}
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
	 * Copy a stored upload so each job processes an immutable snapshot.
	 */
	private function copy_upload( string $path ): string {
		$root = wp_normalize_path( Installer::storage_dir() );
		$path = wp_normalize_path( $path );
		if ( ! str_starts_with( $path, $root . '/' ) || ! is_readable( $path ) ) {
			throw new InvalidArgumentException( 'No valid uploaded source is configured.' );
		}
		$destination = $this->new_path( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) );
		if ( ! copy( $path, $destination ) ) {
			throw new RuntimeException( 'Unable to copy the uploaded source.' );
		}
		return $destination;
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
		$headers     = array();
		if ( ! empty( $source['basic_username'] ) ) {
			$password                 = $this->decrypt( (string) ( $source['basic_password'] ?? '' ) );
			$headers['Authorization'] = 'Basic ' . base64_encode( (string) $source['basic_username'] . ':' . $password );
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $destination,
				'limit_response_size' => $this->max_bytes(),
				'headers'             => $headers,
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
		$this->assert_size( $destination );
		return $destination;
	}

	/**
	 * Download through an authenticated, host-key-pinned SFTP connection.
	 *
	 * @param array<string,mixed> $source Source configuration.
	 */
	private function download_sftp( array $source ): string {
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
		$size = $sftp->filesize( $remote_path );
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
