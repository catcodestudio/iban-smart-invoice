<?php
/**
 * Виконується при повному видаленні плагіна з WP.
 * Free version: тільки options, БД-таблиці (wp_isipay_payments) належать Pro
 * add-on і чистяться його uninstall.php.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('isipay_settings');
delete_option('isipay_db_version');
