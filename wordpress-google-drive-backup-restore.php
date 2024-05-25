<?php
/*
Plugin Name: WordPress Google Drive Backup and Restore
Description: Backup and restore your WordPress site to Google Drive.
Version: 1.0.0
Author: Your Name
*/

require_once __DIR__ . '/vendor/autoload.php';  // Composer autoloader for Google API Client

use Google\Client as Google_Client;
use Google\Service\Drive as Google_Service_Drive;

// Activation hook to initialize options
register_activation_hook(__FILE__, 'gdrive_backup_restore_plugin_activate');

function gdrive_backup_restore_plugin_activate() {
    add_option('gdrive_client_id', '');
    add_option('gdrive_client_secret', '');
    add_option('gdrive_access_token', '');
    add_option('gdrive_folder_id', '');
    add_option('gdrive_backup_prefix', '');
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'gdrive_backup_restore_plugin_deactivate');

function gdrive_backup_restore_plugin_deactivate() {
    // No action required on deactivation
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'gdrive_backup_restore_plugin_uninstall');

function gdrive_backup_restore_plugin_uninstall() {
    delete_option('gdrive_client_id');
    delete_option('gdrive_client_secret');
    delete_option('gdrive_access_token');
    delete_option('gdrive_folder_id');
    delete_option('gdrive_backup_prefix');
}

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=gdrive-backup-restore') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Add admin menu page
add_action('admin_menu', function () {
    add_options_page('Google Drive Backup & Restore', 'GDrive Backup & Restore', 'manage_options', 'gdrive-backup-restore', 'gdrive_backup_restore_settings_page');
});

// Settings page content and logic
function gdrive_backup_restore_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Handle settings form submission
    if (isset($_POST['gdrive_settings_submit'])) {
        check_admin_referer('gdrive_backup_restore_settings');
        update_option('gdrive_client_id', sanitize_text_field($_POST['gdrive_client_id']));
        update_option('gdrive_client_secret', sanitize_text_field($_POST['gdrive_client_secret']));
        update_option('gdrive_folder_id', sanitize_text_field($_POST['gdrive_folder_id']));
        update_option('gdrive_backup_prefix', sanitize_text_field($_POST['gdrive_backup_prefix']));
    }

    $client_id = get_option('gdrive_client_id');
    $client_secret = get_option('gdrive_client_secret');
    $folder_id = get_option('gdrive_folder_id');
    $backup_prefix = get_option('gdrive_backup_prefix');

    echo '<div class="wrap">';
    echo '<h2>Google Drive Backup & Restore Settings</h2>';
    echo '<form method="post" action="">';
    wp_nonce_field('gdrive_backup_restore_settings');
    echo '<table class="form-table">';
    echo '<tr><th scope="row"><label for="gdrive_client_id">Client ID</label></th>';
    echo '<td><input type="text" id="gdrive_client_id" name="gdrive_client_id" value="' . esc_attr($client_id) . '" class="regular-text" /></td></tr>';
    echo '<tr><th scope="row"><label for="gdrive_client_secret">Client Secret</label></th>';
    echo '<td><input type="text" id="gdrive_client_secret" name="gdrive_client_secret" value="' . esc_attr($client_secret) . '" class="regular-text" /></td></tr>';
    echo '<tr><th scope="row"><label for="gdrive_folder_id">Folder ID</label></th>';
    echo '<td><input type="text" id="gdrive_folder_id" name="gdrive_folder_id" value="' . esc_attr($folder_id) . '" class="regular-text" /></td></tr>';
    echo '<tr><th scope="row"><label for="gdrive_backup_prefix">Backup Prefix</label></th>';
    echo '<td><input type="text" id="gdrive_backup_prefix" name="gdrive_backup_prefix" value="' . esc_attr($backup_prefix) . '" class="regular-text" /></td></tr>';
    echo '</table>';
    submit_button('Save Settings', 'primary', 'gdrive_settings_submit');
    echo '</form>';

    // Authenticate and display backups
    if ($client_id && $client_secret && authenticate_google_drive()) {
        display_backups();

        // Display backup button
        echo '<form method="post" action="' . admin_url('options-general.php?page=gdrive-backup-restore&action=backup') . '">';
        wp_nonce_field('gdrive_backup_restore_backup');
        echo '<input type="submit" name="backup_submit" class="button button-primary" value="Backup Now">';
        echo '</form>';
    }

    echo '</div>';
}

// Add action to handle backup and delete requests
add_action('admin_init', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'gdrive-backup-restore') {
        if (isset($_GET['action'])) {
            if ($_GET['action'] === 'backup') {
                check_admin_referer('gdrive_backup_restore_backup');
                initiate_backup();
            } elseif ($_GET['action'] === 'delete') {
                check_admin_referer('gdrive_backup_restore_delete');
                if (isset($_POST['backup_files'])) {
                    delete_backups($_POST['backup_files']);
                }
            }
        }
    }
});

// Authenticate Google Drive
function authenticate_google_drive() {
    $client_id = get_option('gdrive_client_id');
    $client_secret = get_option('gdrive_client_secret');

    $client = new Google_Client();
    $client->setClientId($client_id);
    $client->setClientSecret($client_secret);
    $client->setRedirectUri(admin_url('options-general.php?page=gdrive-backup-restore'));
    $client->addScope(Google_Service_Drive::DRIVE);

    if (isset($_GET['code'])) {
        $client->authenticate($_GET['code']);
        update_option('gdrive_access_token', $client->getAccessToken());
        return true;
    }

    $access_token = get_option('gdrive_access_token');
    if ($access_token) {
        $client->setAccessToken($access_token);
        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            update_option('gdrive_access_token', $client->getAccessToken());
        }
        return true;
    }

    $auth_url = $client->createAuthUrl();
    echo '<a href="' . $auth_url . '" class="button button-primary">Connect to Google Drive</a>';
    return false;
}

// Display backups
function display_backups() {
    $client = new Google_Client();
    $client->setAccessToken(get_option('gdrive_access_token'));
    $drive_service = new Google_Service_Drive($client);

    $folder_id = get_option('gdrive_folder_id');
    $backup_prefix = get_option('gdrive_backup_prefix');

    $files = $drive_service->files->listFiles(array(
        'q' => "'" . $folder_id . "' in parents",
    ));

    if ($files->getFiles()) {
        echo '<h2>Backups</h2>';
        echo '<form method="post" action="' . admin_url('options-general.php?page=gdrive-backup-restore&action=delete') . '">';
        wp_nonce_field('gdrive_backup_restore_delete');
        echo '<table class="widefat">';
        echo '<thead><tr><th><input type="checkbox" id="select_all"></th><th>File Name</th></tr></thead>';
        echo '<tbody>';
        foreach ($files->getFiles() as $file) {
            if (strpos($file->getName(), $backup_prefix) === 0) {
                echo '<tr>';
                echo '<td><input type="checkbox" name="backup_files[]" value="' . $file->getId() . '"></td>';
                echo '<td>' . $file->getName() . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody>';
        echo '</table>';
        echo '<input type="submit" class="button button-secondary" value="Delete Selected">';
        echo '</form>';

        // Select all script
        echo '<script>
            document.getElementById("select_all").onclick = function() {
                var checkboxes = document.getElementsByName("backup_files[]");
                for (var checkbox of checkboxes) {
                    checkbox.checked = this.checked;
                }
            };
        </script>';
    } else {
        echo '<p>No backups found.</p>';
    }
}

// Initiate backup
function initiate_backup() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    global $wpdb;

    // Create database backup
    $backup_dir = WP_CONTENT_DIR . '/backups';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    $db_backup_file = $backup_dir . '/db_backup_' . date('YmdHis') . '.sql';
    $backup_command = sprintf('mysqldump --no-tablespaces --user=%s --password=%s --host=%s %s > %s', DB_USER, DB_PASSWORD, DB_HOST, DB_NAME, $db_backup_file);
    system($backup_command);

    // Create files backup
    $files_backup_file = $backup_dir . '/files_backup_' . date('YmdHis') . '.zip';
    $exclude_dirs = array('wp-content/uploads', 'wp-content/cache', 'wp-content/backups');
    $exclude_string = '';
    foreach ($exclude_dirs as $dir) {
        $exclude_string .= ' --exclude=' . ABSPATH . $dir;
    }
    $zip_command = sprintf('zip -r %s %s %s', $files_backup_file, ABSPATH, $exclude_string);
    system($zip_command);

    // Combine backups into a single archive
    $backup_prefix = get_option('gdrive_backup_prefix');
    $backup_file_name = $backup_prefix . '_' . date('YmdHis') . '.zip';
    $final_backup_file = $backup_dir . '/' . $backup_file_name;
    $zip_command = sprintf('zip -j %s %s %s', $final_backup_file, $db_backup_file, $files_backup_file);
    system($zip_command);

    // Upload to Google Drive
    $client = new Google_Client();
    $client->setAccessToken(get_option('gdrive_access_token'));
    $drive_service = new Google_Service_Drive($client);

    $folder_id = get_option('gdrive_folder_id');
    $file_metadata = new Google_Service_Drive_DriveFile(array(
        'name' => $backup_file_name,
        'parents' => array($folder_id),
    ));
    $content = file_get_contents($final_backup_file);
    $file = $drive_service->files->create($file_metadata, array(
        'data' => $content,
        'mimeType' => 'application/zip',
        'uploadType' => 'multipart',
    ));

    // Clean up local backup files
    unlink($db_backup_file);
    unlink($files_backup_file);
    unlink($final_backup_file);

    wp_redirect(admin_url('options-general.php?page=gdrive-backup-restore'));
    exit;
}

// Delete backups
function delete_backups($file_ids) {
    $client = new Google_Client();
    $client->setAccessToken(get_option('gdrive_access_token'));
    $drive_service = new Google_Service_Drive($client);

    foreach ($file_ids as $file_id) {
        try {
            $drive_service->files->delete($file_id);
        } catch (Exception $e) {
            error_log('Error deleting file: ' . $e->getMessage());
        }
    }

    wp_redirect(admin_url('options-general.php?page=gdrive-backup-restore'));
    exit;
}
