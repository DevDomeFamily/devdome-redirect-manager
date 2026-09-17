<?php
/**
 * One-time copy of the suite-shared connection state to its devdcorev1_* home.
 *
 * Earlier releases (shared core <= 1.3.x) kept the site identity under generic
 * devdome_* option names. The current core reads devdcorev1_* only, so without this
 * copy an upgraded live site would look disconnected. COPY, NEVER MOVE: other
 * DevDome plugins still on the old core keep reading the legacy rows, so those are
 * left in place until every suite plugin is on the current core.
 */

defined('ABSPATH') || exit;

function devdredi_migrate_core_ids() {
    if (get_option('devdredi_core_ids_migrated')) {
        return;
    }
    $shared = array(
        'devdome_site_id'       => 'devdcorev1_site_id',
        'devdome_site_token'    => 'devdcorev1_site_token',
        'devdome_account_id'    => 'devdcorev1_account_id',
        'devdome_account_email' => 'devdcorev1_account_email',
        'devdome_connected_at'  => 'devdcorev1_connected_at',
    );
    // get_option($key, null) is null ONLY when the row is absent, so falsy stored
    // values still migrate and an already-present target is never overwritten.
    foreach ($shared as $old => $new) {
        if (null !== get_option($new, null)) {
            continue; // already migrated, or another suite plugin got there first
        }
        $val = get_option($old, null);
        if (null !== $val && !add_option($new, $val) && null === get_option($new, null)) {
            return; // the copy did not land: retried next load, never marked migrated
        }
    }
    update_option('devdredi_core_ids_migrated', 1);
}
add_action('plugins_loaded', 'devdredi_migrate_core_ids', -99);
