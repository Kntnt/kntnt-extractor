<?php
/**
 * Integration test: the Operate capability's activation lifecycle.
 *
 * Covers the plugin's dormancy on-switch (ADR-0001/0002): activation registers
 * and grants `kntnt_extractor_operate` to the administrator role, deactivation
 * is the off-switch that removes it, and reactivation re-runs the grant — the
 * only sanctioned recovery for a grant that has gone missing (self-healing).
 * Also pins that activation creates no account of its own.
 *
 * Binds to the capability slug and the plugin basename — the published
 * contract — never to an internal class, so the test tracks the acceptance
 * criteria rather than one implementation.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

// The (de)activation helpers live in an admin-only include; the suite needs
// them to drive the plugin's activation lifecycle.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';
$plugin = 'kntnt-extractor/kntnt-extractor.php';

// Activation (performed by the bootstrap) registers the Operate capability on
// the administrator role.
$role = get_role( 'administrator' );
kntnt_extractor_assert( $role !== null && $role->has_cap( $operate ), 'Activation grants the Operate capability to the administrator role' );

// The grant is effective: a real administrator user holds the capability.
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $admin->ID );
kntnt_extractor_assert( current_user_can( $operate ), 'An administrator holds the Operate capability after activation' );

// The plugin creates no account of its own — reactivation leaves the user count
// unchanged.
wp_set_current_user( 0 );
$before = count_users()['total_users'];
deactivate_plugins( $plugin );
activate_plugin( $plugin );
kntnt_extractor_assert( count_users()['total_users'] === $before, 'Reactivating the plugin creates no user account' );

// Deactivation is the off-switch: it removes the Operate grant from the role.
deactivate_plugins( $plugin );
kntnt_extractor_assert( ! get_role( 'administrator' )->has_cap( $operate ), 'Deactivation removes the Operate capability from the administrator role' );

// Reactivation re-runs the grant.
activate_plugin( $plugin );
kntnt_extractor_assert( get_role( 'administrator' )->has_cap( $operate ), 'Reactivation re-grants the Operate capability' );

// Self-healing: a grant lost by any means is restored by deactivate/reactivate,
// the only sanctioned recovery (ADR-0002).
get_role( 'administrator' )->remove_cap( $operate );
kntnt_extractor_assert( ! get_role( 'administrator' )->has_cap( $operate ), 'Precondition: the Operate grant can be lost' );
deactivate_plugins( $plugin );
activate_plugin( $plugin );
kntnt_extractor_assert( get_role( 'administrator' )->has_cap( $operate ), 'Reactivation restores a lost Operate grant (self-healing)' );

// Leave the suite state clean for later files: plugin active, no current user.
wp_set_current_user( 0 );
