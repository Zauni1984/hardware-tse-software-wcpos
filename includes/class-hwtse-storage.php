<?php
defined( 'ABSPATH' ) || exit;

/**
 * Protected file storage below wp-content/uploads/hwtse/ for TSE exports (TAR),
 * DSFinV-K archives and the Epson ePOS SDK file.
 */
class HWTSE_Storage {

	public static function dir( string $sub = '' ): string {
		$upload = wp_upload_dir();
		$base   = trailingslashit( $upload['basedir'] ) . 'hwtse';
		$dir    = '' !== $sub ? $base . '/' . $sub : $base;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( 'sdk' !== $sub && ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	public static function url( string $sub, string $file ): string {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . 'hwtse/' . $sub . '/' . rawurlencode( $file );
	}

	/** Files of a directory, newest first. */
	public static function files( string $sub ): array {
		$dir   = self::dir( $sub );
		$files = array();
		foreach ( (array) glob( $dir . '/*' ) as $path ) {
			$name = basename( (string) $path );
			if ( in_array( $name, array( '.htaccess', 'index.html' ), true ) || ! is_file( $path ) ) {
				continue;
			}
			$files[] = array(
				'name'  => $name,
				'bytes' => (int) filesize( $path ),
				'time'  => (int) filemtime( $path ),
			);
		}
		usort( $files, static fn( $a, $b ) => $b['time'] <=> $a['time'] );
		return $files;
	}

	/** Absolute path of a stored file, refusing anything that is not a plain name in the directory. */
	public static function path( string $sub, string $name ): ?string {
		$name = basename( $name );
		if ( '' === $name || str_starts_with( $name, '.' ) ) {
			return null;
		}
		$path = self::dir( $sub ) . '/' . $name;
		return is_file( $path ) ? $path : null;
	}

	/** Stream a stored file to the browser (admin download). */
	public static function send( string $sub, string $name ): void {
		$path = self::path( $sub, $name );
		if ( ! $path ) {
			wp_die( 'Datei nicht gefunden.', '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
