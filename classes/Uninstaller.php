<?php
/**
 * Uninstall cleanup: erasing every sensitive on-disk residue the plugin leaves.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * Removes everything the plugin ever wrote, on uninstall (issue #13, ADR-0006/0008).
 *
 * WordPress runs uninstall.php in a fresh request with the plugin not loaded, so
 * the guarded bootstrap there does the minimum — load the autoloader — and hands
 * off to this testable routine rather than carrying the logic itself, which the
 * `WP_UNINSTALL_PLUGIN` guard would otherwise put out of a test's reach. The two
 * subsystems that own on-disk residue erase their own: the {@see Audit_Log}
 * deletes its randomly-named log file and its directory and forgets the recorded
 * path (ADR-0006), and the {@see Job_Store} deletes every job and the working and
 * served-downloads directories whole (ADR-0008). Both resolve their location
 * through the same Config seam the running plugin does, so a `KNTNT_EXTRACTOR_WORK_DIR`
 * override is honoured and nothing is left outside the resolved directories. A
 * location that was never created is simply nothing to remove, so the routine is
 * safe on a partially-present install.
 *
 * @since 0.1.0
 */
final class Uninstaller {

	/**
	 * Erases every sensitive on-disk residue the plugin left behind.
	 *
	 * The single entry point uninstall.php delegates to. It removes the working and
	 * served-downloads directories (and every job within) and the audit log and its
	 * directory, leaving nothing sensitive under the uploads directory or an
	 * overridden work_dir. Ordering is immaterial — the two subsystems own disjoint
	 * directories — and each step is independently resilient to an absent directory.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function purge_all(): void {

		// Erase each subsystem's own residue through the Config seam the running
		// plugin resolves its locations through, so an overridden work_dir is honoured.
		$config = new Config();
		( new Job_Store( $config ) )->purge_all();
		( new Audit_Log() )->purge();

	}

}
