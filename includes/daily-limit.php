<?php
/**
 * Daily Redirect Limit (1.5.4), per rule, off by default. Once today's limit is reached the rule stops
 * redirecting until midnight (site time); further visitors get the rule's bypass behaviour. The limit
 * for the day is picked between the two values (the same value twice = a fixed limit).
 *
 * Every day has its OWN two rows (limit and count, named with the date), created with INSERT IGNORE, so
 * nothing is ever reset: there is no moment at which a new day can wipe a slot another request just took.
 * A slot is taken with one conditional UPDATE at the moment the engine decides to redirect, whatever the
 * redirect type, so the public counter pixel never touches the limit. A Visitor Check redirect takes its slot
 * later, when its pass is redeemed, so a page load that never continues costs nothing and there is nothing
 * to give back. A database error never opens the limit: it counts as "reached".
 */

defined('ABSPATH') || exit;

/** One statement on the plugin's settings table. Returns the affected rows, or false on a database error. */
function devdredi_daily_limit_sql($sql, array $args)
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdredi_settings';
    // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- plugin's own settings table; table name from $wpdb->prefix; values prepared; counters must never be cached.
    $n = $wpdb->query($wpdb->prepare(str_replace('{table}', $table, $sql), $args));
    // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
    return $n === false ? false : (int) $n;
}

/** Read one whole number from the settings table: the number, null when the row is missing, false on a database error. */
function devdredi_daily_limit_read($name)
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdredi_settings';
    // phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- plugin's own settings table; table name from $wpdb->prefix; value prepared; counters must never be cached.
    $v = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $table WHERE setting_name = %s", $name));
    // phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
    if ($v === null) {
        return $wpdb->last_error !== '' ? false : null;
    }
    return (int) $v;
}

/** The rule's range: array(min, max); null when the limit is off; false when the rule's limit settings could not be read. */
function devdredi_daily_limit_range()
{
    $before = !empty($GLOBALS['devdredi_read_failed']);
    unset($GLOBALS['devdredi_read_failed']);
    $on  = devdredi_get_bool_setting('daily_limit_enabled', 0);
    $max = max(0, (int) devdredi_get_setting('daily_limit_max', 0));
    $min = max(0, (int) devdredi_get_setting('daily_limit_min', 0));
    $failed = !empty($GLOBALS['devdredi_read_failed']);
    if ($before || $failed) {
        $GLOBALS['devdredi_read_failed'] = true; // keep what the caller already knew, add what happened here
    }
    if ($failed) {
        return false; // a failed read is not "off": the limit stays shut
    }
    if (!$on || $max <= 0) {
        return null;
    }
    return array(max(1, min($min, $max)), $max);
}

/** Names of today's two rows for the current rule. */
function devdredi_daily_limit_rows()
{
    static $day = null; // fixed for the whole request: a request that crosses midnight keeps working on one day
    if ($day === null) {
        $day = current_time('Ymd');
    }
    return array(devdredi_scoped_name('daily_limit_cap_' . $day), devdredi_scoped_name('daily_limit_used_' . $day));
}

/**
 * Today's limit for the current rule: array(cap, used), null when the limit is off, false on a database error.
 * Creates today's rows on the first request of the day (first writer wins) and drops the rows of earlier days.
 */
function devdredi_daily_limit_today()
{
    $range = devdredi_daily_limit_range();
    if ($range === null || $range === false) {
        return $range;
    }
    list($cap_row, $used_row) = devdredi_daily_limit_rows();
    $cap = devdredi_daily_limit_read($cap_row);
    if ($cap === false) {
        return false;
    }
    if ($cap === null) {
        $pick = ($range[0] >= $range[1]) ? $range[1] : wp_rand($range[0], $range[1]);
        $made = devdredi_daily_limit_sql('INSERT IGNORE INTO {table} (setting_name, setting_value) VALUES (%s, %s)', array($cap_row, (string) $pick));
        if ($made === false || devdredi_daily_limit_sql('INSERT IGNORE INTO {table} (setting_name, setting_value) VALUES (%s, %s)', array($used_row, '0')) === false) {
            return false;
        }
        if ($made === 1) {
            // A new day began: rows of EARLIER days are no longer needed. The names end in the date, so "older" is a plain
            // string comparison; a slow request that still belongs to yesterday can never delete a newer day's rows.
            $like = devdredi_scoped_name('daily_limit_');
            global $wpdb;
            devdredi_daily_limit_sql('DELETE FROM {table} WHERE (setting_name LIKE %s AND setting_name < %s) OR (setting_name LIKE %s AND setting_name < %s)',
                array($wpdb->esc_like($like . 'cap_') . '%', $cap_row, $wpdb->esc_like($like . 'used_') . '%', $used_row));
        }
        $cap = devdredi_daily_limit_read($cap_row);
        if ($cap === false || $cap === null) {
            return false;
        }
    }
    $used = devdredi_daily_limit_read($used_row);
    if ($used === false) {
        return false;
    }
    if ($used === null) { // the limit row exists but the count row does not (a write failed earlier today): create it
        if (devdredi_daily_limit_sql('INSERT IGNORE INTO {table} (setting_name, setting_value) VALUES (%s, %s)', array($used_row, '0')) === false) {
            return false;
        }
        $used = devdredi_daily_limit_read($used_row);
        if ($used === false || $used === null) {
            return false;
        }
    }
    return array('cap' => (int) $cap, 'used' => (int) $used);
}

/** True when the current rule must not redirect any more today (limit reached, or its state cannot be read). */
function devdredi_daily_limit_reached()
{
    $dl = devdredi_daily_limit_today();
    if ($dl === null) {
        return false;
    }
    return $dl === false || $dl['used'] >= $dl['cap']; // false = the settings or the count could not be read: shut
}

/** Take one slot for a redirect that is happening now. True = go on (or no limit); false = no slot left, or a database error. */
function devdredi_daily_limit_take()
{
    $dl = devdredi_daily_limit_today();
    if ($dl === null) {
        return true;
    }
    if ($dl === false) {
        return false;
    }
    list(, $used_row) = devdredi_daily_limit_rows();
    return devdredi_daily_limit_sql('UPDATE {table} SET setting_value = CAST(setting_value AS UNSIGNED) + 1 WHERE setting_name = %s AND CAST(setting_value AS UNSIGNED) < %d', array($used_row, $dl['cap'])) === 1;
}

/**
 * The range was changed (settings screen or an agent): pick today's limit again from the new range. Today's
 * count is kept, so a changed range never hands out a fresh day. Same numbers = nothing happens.
 */
function devdredi_daily_limit_range_changed($old_min, $old_max, $new_min, $new_max)
{
    if ((int) $old_min === (int) $new_min && (int) $old_max === (int) $new_max) {
        return;
    }
    list($cap_row) = devdredi_daily_limit_rows();
    if ((int) $new_max <= 0) {
        $done = devdredi_daily_limit_sql('DELETE FROM {table} WHERE setting_name = %s', array($cap_row));
    } else {
        $lo = max(1, min((int) $new_min, (int) $new_max));
        $pick = ($lo >= (int) $new_max) ? (int) $new_max : wp_rand($lo, (int) $new_max);
        $done = devdredi_daily_limit_sql('INSERT INTO {table} (setting_name, setting_value) VALUES (%s, %s) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', array($cap_row, (string) $pick));
        if ($done !== false && devdredi_daily_limit_read($cap_row) !== $pick) {
            $done = false; // read back: a limit that did not change is not a saved limit
        }
    }
    if ($done === false) {
        $GLOBALS['devdredi_write_failed'] = true; // the settings screen and the abilities report this instead of "saved"
    }
}
