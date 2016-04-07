<?php
/**
 * @file
 * Drush commands and hook implementations.
 */

/**
 * Implements hook_drush_sql_sync_sanitize.
 *
 * Clears user_import_waitlist table.
 */
function user_import_drush_sql_sync_sanitize($site) {
  $wrap_table_name = (bool) drush_get_option('db-prefix');
  $table = 'user_import_waitlist';
  if ($wrap_table_name) {
    $table = "{{$table}}";
  }
  $sanitize_query = "TRUNCATE table {$table};";
  drush_sql_register_post_sync_op('user-import', dt('Truncate !table table', array('!table' => $table)), $sanitize_query);
}
