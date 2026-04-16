<?php
/**
 * Plugin Name: GitHub Sync
 * Description: Synchronize and update WordPress plugins directly from GitHub repositories.
 * Version: 1.0.0
 * Author: MasterA10
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GitHub_Sync {

    private $option_name = 'github_sync_repos';
    private $log_option_name = 'github_sync_logs';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
        add_action( 'admin_post_github_sync_action', array( $this, 'handle_sync_action' ) );
        add_action( 'github_sync_cron_event', array( $this, 'run_auto_sync' ) );
        add_action( 'admin_notices', array( $this, 'display_notices' ) );
    }

    public function add_cron_schedules( $schedules ) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => __( 'Every Minute' ),
        );
        return $schedules;
    }

    public function add_admin_menu() {
        add_options_page(
            'GitHub Sync',
            'GitHub Sync',
            'manage_options',
            'github-sync',
            array( $this, 'render_admin_page' )
        );
    }

    public function register_settings() {
        register_setting( 'github_sync_group', $this->option_name );
    }

    public function render_admin_page() {
        $repos = get_option( $this->option_name, array() );
        $logs  = get_option( $this->log_option_name, array() );
        ?>
        <div class="wrap">
            <h1>GitHub Sync</h1>
            
            <div class="card" style="max-width: 100%; margin-top: 20px; padding: 20px;">
                <h2>Add New Repository</h2>
                <form method="post" action="admin-post.php">
                    <input type="hidden" name="action" value="github_sync_action">
                    <input type="hidden" name="sync_task" value="add_repo">
                    <?php wp_nonce_field( 'github_sync_nonce' ); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="repo_url">GitHub Repository URL</label></th>
                            <td><input name="repo_url" type="url" id="repo_url" value="" class="regular-text" placeholder="https://github.com/user/repo" required></td>
                        </tr>
                        <tr>
                            <th><label for="repo_token">Personal Access Token (PAT)</label></th>
                            <td><input name="repo_token" type="password" id="repo_token" value="" class="regular-text" placeholder="Optional for private repos"></td>
                        </tr>
                        <tr>
                            <th><label for="repo_branch">Branch</label></th>
                            <td><input name="repo_branch" type="text" id="repo_branch" value="main" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><label for="repo_folder">Custom Folder Name</label></th>
                            <td><input name="repo_folder" type="text" id="repo_folder" value="" class="regular-text" placeholder="Optional"></td>
                        </tr>
                        <tr>
                            <th><label for="sync_frequency">Sync Frequency</label></th>
                            <td>
                                <select name="sync_frequency" id="sync_frequency">
                                    <option value="manual">Manual</option>
                                    <option value="hourly">Hourly</option>
                                    <option value="twicedaily">Twice Daily</option>
                                    <option value="daily">Daily</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Add Repository' ); ?>
                </form>
            </div>

            <h2 style="margin-top: 40px;">Synced Repositories</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Repository</th>
                        <th>Folder</th>
                        <th>Branch</th>
                        <th>Frequency</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $repos ) ) : ?>
                        <tr><td colspan="5">No repositories added yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $repos as $id => $repo ) : ?>
                            <tr>
                                <td><?php echo esc_html( $repo['url'] ); ?></td>
                                <td><?php echo esc_html( $repo['folder'] ); ?></td>
                                <td><?php echo esc_html( $repo['branch'] ); ?></td>
                                <td><?php echo esc_html( $repo['frequency'] ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=github_sync_action&sync_task=sync_now&id=' . $id . '&_wpnonce=' . wp_create_nonce( 'github_sync_nonce' ) ) ); ?>" class="button button-small">Sync Now</a>
                                    <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=github_sync_action&sync_task=delete_repo&id=' . $id . '&_wpnonce=' . wp_create_nonce( 'github_sync_nonce' ) ) ); ?>" class="button button-small button-link-delete" onclick="return confirm('Remove this repo?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top: 40px;">Update Timeline</h2>
            <div class="card" style="max-width: 100%; height: 300px; overflow-y: scroll;">
                <ul style="margin: 0; padding: 10px;">
                    <?php if ( empty( $logs ) ) : ?>
                        <li>No sync events yet.</li>
                    <?php else : ?>
                        <?php foreach ( array_reverse( $logs ) as $log ) : ?>
                            <li style="margin-bottom: 5px; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                                <strong>[<?php echo esc_html( $log['time'] ); ?>]</strong> 
                                <?php echo esc_html( $log['message'] ); ?> 
                                <span style="color: <?php echo $log['status'] === 'success' ? 'green' : 'red'; ?>;">
                                    (<?php echo strtoupper( esc_html( $log['status'] ) ); ?>)
                                </span>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <?php
    }

    public function handle_sync_action() {
        check_admin_referer( 'github_sync_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $task = isset( $_POST['sync_task'] ) ? $_POST['sync_task'] : ( isset( $_GET['sync_task'] ) ? $_GET['sync_task'] : '' );
        $repos = get_option( $this->option_name, array() );

        if ( $task === 'add_repo' ) {
            $id = uniqid();
            $repos[ $id ] = array(
                'url'       => esc_url_raw( $_POST['repo_url'] ),
                'token'     => sanitize_text_field( $_POST['repo_token'] ),
                'branch'    => sanitize_text_field( $_POST['repo_branch'] ),
                'folder'    => sanitize_text_field( $_POST['repo_folder'] ) ?: basename( $_POST['repo_url'] ),
                'frequency' => sanitize_text_field( $_POST['sync_frequency'] ),
            );
            update_option( $this->option_name, $repos );
            $this->log_event( "Added repository: " . $repos[ $id ]['url'], 'success' );
            
            if ( $repos[ $id ]['frequency'] !== 'manual' ) {
                wp_schedule_event( time(), $repos[ $id ]['frequency'], 'github_sync_cron_event', array( 'id' => $id ) );
            }
        } elseif ( $task === 'delete_repo' ) {
            $id = isset( $_GET['id'] ) ? $_GET['id'] : '';
            if ( isset( $repos[ $id ] ) ) {
                wp_clear_scheduled_hook( 'github_sync_cron_event', array( 'id' => $id ) );
                unset( $repos[ $id ] );
                update_option( $this->option_name, $repos );
            }
        } elseif ( $task === 'sync_now' ) {
            $id = isset( $_GET['id'] ) ? $_GET['id'] : '';
            if ( isset( $repos[ $id ] ) ) {
                $result = $this->sync_repo( $id, $repos[ $id ] );
                if ( is_wp_error( $result ) ) {
                    set_transient( 'github_sync_notice', array( 'type' => 'error', 'message' => $result->get_error_message() ), 30 );
                } else {
                    set_transient( 'github_sync_notice', array( 'type' => 'success', 'message' => 'Plugin synchronized successfully!' ), 30 );
                }
            }
        }

        wp_redirect( admin_url( 'options-general.php?page=github-sync' ) );
        exit;
    }

    private function sync_repo( $id, $repo ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $parsed_url = parse_url( $repo['url'] );
        $path       = trim( $parsed_url['path'], '/' );
        $zip_url    = "https://api.github.com/repos/{$path}/zipball/" . $repo['branch'];

        // Create temp file FIRST, then stream the download directly to it
        $temp_file = wp_tempnam( 'github_sync_' );

        $args = array(
            'timeout'     => 300,
            'redirection' => 5,
            'stream'      => true,
            'filename'    => $temp_file,
            'headers'     => array(
                'Accept'               => 'application/vnd.github+json',
                'User-Agent'           => 'WordPress/GitHub-Sync',
                'X-GitHub-Api-Version' => '2022-11-28',
            ),
        );

        if ( ! empty( $repo['token'] ) ) {
            $args['headers']['Authorization'] = 'Bearer ' . $repo['token'];
        }

        $this->log_event( "Downloading ZIP from: " . $zip_url, 'success' );
        $response = wp_remote_get( $zip_url, $args );

        if ( is_wp_error( $response ) ) {
            @unlink( $temp_file );
            $this->log_event( "Download failed for " . $repo['url'] . ": " . $response->get_error_message(), 'error' );
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            @unlink( $temp_file );
            $this->log_event( "Download failed for " . $repo['url'] . ": HTTP " . $response_code . " - " . wp_remote_retrieve_response_message( $response ), 'error' );
            return new WP_Error( 'http_error', "HTTP " . $response_code . " - " . wp_remote_retrieve_response_message( $response ) );
        }

        // Validate the downloaded file
        $file_size = filesize( $temp_file );
        $this->log_event( "Download successful. File size: " . size_format( $file_size ), 'success' );

        if ( $file_size < 100 ) {
            @unlink( $temp_file );
            $this->log_event( "Downloaded file is too small (" . $file_size . " bytes). Repository may be empty or token may be invalid.", 'error' );
            return new WP_Error( 'empty_zip', 'Downloaded file is too small.' );
        }

        // Validate ZIP magic bytes (PK header)
        $fh = fopen( $temp_file, 'rb' );
        $magic = fread( $fh, 4 );
        fclose( $fh );
        if ( substr( $magic, 0, 2 ) !== 'PK' ) {
            $body_preview = file_get_contents( $temp_file, false, null, 0, 200 );
            @unlink( $temp_file );
            $this->log_event( "Downloaded file is NOT a valid ZIP. Content preview: " . substr( $body_preview, 0, 150 ), 'error' );
            return new WP_Error( 'invalid_zip', 'Downloaded file is not a valid ZIP archive.' );
        }

        // Initialize Filesystem
        global $wp_filesystem;
        if ( ! WP_Filesystem() ) {
            @unlink( $temp_file );
            return new WP_Error( 'filesystem_error', 'Could not initialize WP_Filesystem.' );
        }

        $target_dir       = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $repo['folder'] );
        $temp_extract_dir = wp_normalize_path( trailingslashit( get_temp_dir() ) . 'github_sync_' . $id );

        // Clean previous extraction
        if ( is_dir( $temp_extract_dir ) ) {
            $this->delete_directory( $temp_extract_dir );
        }
        mkdir( $temp_extract_dir, 0755, true );

        // Use native PHP ZipArchive for reliable extraction
        $this->log_event( "Extracting ZIP to: " . $temp_extract_dir, 'success' );

        $zip = new ZipArchive();
        $open_result = $zip->open( $temp_file );
        if ( $open_result !== true ) {
            @unlink( $temp_file );
            $this->log_event( "ZipArchive::open failed with code: " . $open_result, 'error' );
            return new WP_Error( 'zip_open_error', 'Could not open ZIP file. Error code: ' . $open_result );
        }

        $this->log_event( "ZIP contains " . $zip->numFiles . " files. First entry: " . $zip->getNameIndex(0), 'success' );
        $zip->extractTo( $temp_extract_dir );
        $zip->close();
        @unlink( $temp_file );

        // Use native PHP scandir to find extracted contents
        $items = scandir( $temp_extract_dir );
        $root_folder = '';
        $item_names  = array();

        if ( $items ) {
            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) continue;
                $full_path    = $temp_extract_dir . '/' . $item;
                $item_names[] = $item . ' [' . ( is_dir( $full_path ) ? 'DIR' : 'FILE' ) . ']';
                if ( is_dir( $full_path ) && empty( $root_folder ) ) {
                    $root_folder = $item;
                }
            }
        }

        $this->log_event( "Extracted contents: " . ( ! empty( $item_names ) ? implode( ', ', $item_names ) : 'EMPTY' ), 'success' );

        if ( $root_folder ) {
            $source_path = $temp_extract_dir . '/' . $root_folder;
            $this->log_event( "Root folder: " . $root_folder . " — copying to " . $target_dir, 'success' );

            // Remove existing target and copy
            if ( is_dir( $target_dir ) ) {
                $this->delete_directory( $target_dir );
            }

            $this->recurse_copy( $source_path, $target_dir );
            $this->delete_directory( $temp_extract_dir );

            $this->log_event( "Synchronized " . $repo['url'] . " → " . $repo['folder'] . " ✓", 'success' );
            return true;
        }

        $found_info = ! empty( $item_names ) ? "Found: " . implode( ', ', $item_names ) : "Directory is empty.";
        $this->log_event( "Could not find root folder. " . $found_info, 'error' );
        $this->delete_directory( $temp_extract_dir );

        return new WP_Error( 'extract_error', 'Could not find extracted folder structure.' );
    }

    /**
     * Recursively copy a directory.
     */
    private function recurse_copy( $src, $dst ) {
        $dir = opendir( $src );
        if ( ! is_dir( $dst ) ) {
            mkdir( $dst, 0755, true );
        }
        while ( false !== ( $file = readdir( $dir ) ) ) {
            if ( $file === '.' || $file === '..' ) continue;
            $src_path = $src . '/' . $file;
            $dst_path = $dst . '/' . $file;
            if ( is_dir( $src_path ) ) {
                $this->recurse_copy( $src_path, $dst_path );
            } else {
                copy( $src_path, $dst_path );
            }
        }
        closedir( $dir );
    }

    /**
     * Recursively delete a directory.
     */
    private function delete_directory( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $items = scandir( $dir );
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $path = $dir . '/' . $item;
            if ( is_dir( $path ) ) {
                $this->delete_directory( $path );
            } else {
                unlink( $path );
            }
        }
        rmdir( $dir );
    }

    public function run_auto_sync( $id ) {
        $repos = get_option( $this->option_name, array() );
        if ( isset( $repos[ $id ] ) ) {
            $this->sync_repo( $id, $repos[ $id ] );
        }
    }

    private function log_event( $message, $status ) {
        $logs = get_option( $this->log_option_name, array() );
        $logs[] = array(
            'time'    => current_time( 'mysql' ),
            'message' => $message,
            'status'  => $status,
        );
        // Keep only last 50 logs
        if ( count( $logs ) > 50 ) {
            array_shift( $logs );
        }
        update_option( $this->log_option_name, $logs );
    }

    public function display_notices() {
        $notice = get_transient( 'github_sync_notice' );
        if ( $notice ) {
            delete_transient( 'github_sync_notice' );
            ?>
            <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
                <p><?php echo esc_html( $notice['message'] ); ?></p>
            </div>
            <?php
        }
    }
}

new GitHub_Sync();
