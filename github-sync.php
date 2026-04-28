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
        add_action( 'init', array( $this, 'check_external_sync' ) );
        add_action( 'init', array( $this, 'check_webhook_sync' ) );
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
                            <th><label for="repo_secret">Webhook Secret</label></th>
                            <td><input name="repo_secret" type="password" id="repo_secret" value="" class="regular-text" placeholder="Optional (for GitHub Webhooks)"></td>
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

            <h2 style="margin-top: 40px;">
                Synced Repositories
                <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=github_sync_action&sync_task=sync_all&_wpnonce=' . wp_create_nonce( 'github_sync_nonce' ) ) ); ?>" class="button button-primary" style="margin-left: 15px;">Sync All Now</a>
            </h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Repository</th>
                        <th>Folder</th>
                        <th>Branch</th>
                        <th>Frequency</th>
                        <th>Webhook URL</th>
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
                                    <code style="font-size: 10px;"><?php echo esc_url( home_url( '/?github_sync_action=webhook&id=' . $id ) ); ?></code>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=github_sync_action&sync_task=sync_now&id=' . $id . '&_wpnonce=' . wp_create_nonce( 'github_sync_nonce' ) ) ); ?>" class="button button-small">Sync Now</a>
                                    <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=github_sync_action&sync_task=delete_repo&id=' . $id . '&_wpnonce=' . wp_create_nonce( 'github_sync_nonce' ) ) ); ?>" class="button button-small button-link-delete" onclick="return confirm('Remove this repo?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top: 40px;">Automation / External Link</h2>
            <div class="card" style="max-width: 100%; padding: 20px;">
                <p>Use the link below to synchronize all repositories without logging into WordPress. This is useful for external cron jobs or simple browser bookmarks.</p>
                <code style="display: block; padding: 10px; background: #f0f0f0; border: 1px solid #ccc; margin-bottom: 10px; word-break: break-all;">
                    <?php echo esc_url( home_url( '/?github_sync_action=sync_all_external&token=' . $this->get_external_sync_token() ) ); ?>
                </code>
                <p class="description">Keep this link private as it allows anyone to trigger a full synchronization.</p>
            </div>

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
                'secret'    => sanitize_text_field( $_POST['repo_secret'] ),
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
        } elseif ( $task === 'sync_all' ) {
            $count = $this->sync_all_repos();
            set_transient( 'github_sync_notice', array( 'type' => 'success', 'message' => "All {$count} repositories synchronized successfully!" ), 30 );
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

        $upload_dir = wp_upload_dir();
        $base_temp  = trailingslashit( $upload_dir['basedir'] ) . 'github-sync-temp';
        
        if ( ! is_dir( $base_temp ) ) {
            mkdir( $base_temp, 0755, true );
        }

        $temp_file        = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $base_temp . DIRECTORY_SEPARATOR . 'sync_' . $id . '.zip' );
        $temp_extract_dir = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $base_temp . DIRECTORY_SEPARATOR . 'extract_' . $id );
        $target_dir       = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, trailingslashit( WP_PLUGIN_DIR ) . $repo['folder'] );

        $args = array(
            'timeout'     => 300,
            'redirection' => 5,
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
            $this->log_event( "Download failed: " . $response->get_error_message(), 'error' );
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            $this->log_event( "Download failed: HTTP " . $response_code, 'error' );
            return new WP_Error( 'http_error', "HTTP " . $response_code );
        }

        // Initialize Filesystem
        global $wp_filesystem;
        if ( ! WP_Filesystem() ) {
            return new WP_Error( 'filesystem_error', 'Could not initialize WP_Filesystem.' );
        }

        $body = wp_remote_retrieve_body( $response );
        $size = strlen( $body );
        $this->log_event( "Download successful. ZIP size: " . size_format( $size ), 'success' );

        if ( $size < 100 ) {
            return new WP_Error( 'empty_zip', 'Downloaded file is too small.' );
        }

        // Save ZIP using native file_put_contents for binary safety
        file_put_contents( $temp_file, $body );
        
        // Validate ZIP magic bytes (PK header)
        $fh = fopen( $temp_file, 'rb' );
        $magic = fread( $fh, 4 );
        fclose( $fh );
        if ( substr( $magic, 0, 2 ) !== 'PK' ) {
            $body_preview = substr( $body, 0, 200 );
            @unlink( $temp_file );
            $this->log_event( "Downloaded file is NOT a valid ZIP. Content preview: " . $body_preview, 'error' );
            return new WP_Error( 'invalid_zip', 'Downloaded file is not a valid ZIP archive.' );
        }

        // Clean and prepare extraction directory
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
        
        $extracted = $zip->extractTo( $temp_extract_dir );
        $status    = $zip->getStatusString();
        $zip->close();
        @unlink( $temp_file );

        if ( ! $extracted ) {
            $this->log_event( "ZipArchive::extractTo failed. Status: " . $status, 'error' );
            return new WP_Error( 'zip_extract_error', 'Could not extract ZIP file. Status: ' . $status );
        }

        // Use native PHP scandir to find extracted contents
        $items = scandir( $temp_extract_dir );
        $root_folder = '';
        $item_names  = array();

        if ( $items ) {
            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) continue;
                $full_path    = $temp_extract_dir . DIRECTORY_SEPARATOR . $item;
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

    public function sync_all_repos() {
        $repos = get_option( $this->option_name, array() );
        $count = 0;
        foreach ( $repos as $id => $repo ) {
            $result = $this->sync_repo( $id, $repo );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            }
        }
        return $count;
    }

    public function check_external_sync() {
        if ( isset( $_GET['github_sync_action'] ) && $_GET['github_sync_action'] === 'sync_all_external' ) {
            $token = $this->get_external_sync_token();
            $param_token = isset( $_GET['token'] ) ? $_GET['token'] : '';

            if ( $param_token === $token ) {
                $count = $this->sync_all_repos();
                wp_die( "Success: {$count} repositories synchronized.", "GitHub Sync External", array( 'response' => 200 ) );
            } else {
                wp_die( "Unauthorized: Invalid token.", "GitHub Sync External", array( 'response' => 403 ) );
            }
        }
    }

    private function get_external_sync_token() {
        $token = get_option( 'github_sync_external_token' );
        if ( ! $token ) {
            $token = wp_generate_password( 32, false );
            update_option( 'github_sync_external_token', $token );
        }
        return $token;
    }

    public function check_webhook_sync() {
        if ( ! isset( $_GET['github_sync_action'] ) || $_GET['github_sync_action'] !== 'webhook' ) {
            return;
        }

        $id = isset( $_GET['id'] ) ? $_GET['id'] : '';
        $repos = get_option( $this->option_name, array() );

        if ( ! isset( $repos[ $id ] ) ) {
            wp_die( "Invalid Repository ID.", "GitHub Sync Webhook", array( 'response' => 404 ) );
        }

        $repo = $repos[ $id ];
        $payload = file_get_contents( 'php://input' );

        // 1. Verify Signature (if secret is set)
        if ( ! empty( $repo['secret'] ) ) {
            $header = isset( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';
            if ( ! $this->verify_webhook_signature( $payload, $header, $repo['secret'] ) ) {
                $this->log_event( "Webhook signature verification failed for " . $repo['url'], 'error' );
                wp_die( "Invalid Signature.", "GitHub Sync Webhook", array( 'response' => 403 ) );
            }
        }

        // 2. Parse Payload
        $data = json_decode( $payload, true );
        
        // 3. Verify it's a 'push' event (GitHub sends X-GitHub-Event header)
        $event = isset( $_SERVER['HTTP_X_GITHUB_EVENT'] ) ? $_SERVER['HTTP_X_GITHUB_EVENT'] : '';
        
        // Ping event is sent by GitHub when the webhook is created
        if ( $event === 'ping' ) {
            wp_die( "Ping received. Webhook is active!", "GitHub Sync Webhook", array( 'response' => 200 ) );
        }

        if ( $event !== 'push' ) {
            wp_die( "Event '{$event}' ignored.", "GitHub Sync Webhook", array( 'response' => 200 ) );
        }

        if ( ! $data ) {
            wp_die( "Invalid Payload.", "GitHub Sync Webhook", array( 'response' => 400 ) );
        }

        // 4. Trigger Sync
        $this->log_event( "Webhook received for " . $repo['url'] . " — triggering sync.", 'success' );
        $result = $this->sync_repo( $id, $repo );

        if ( is_wp_error( $result ) ) {
            wp_die( "Sync failed: " . $result->get_error_message(), "GitHub Sync Webhook", array( 'response' => 500 ) );
        }

        wp_die( "Sync successful.", "GitHub Sync Webhook", array( 'response' => 200 ) );
    }

    private function verify_webhook_signature( $payload, $header, $secret ) {
        if ( empty( $header ) ) return false;

        $split = explode( '=', $header );
        if ( count( $split ) !== 2 ) return false;

        $algo = $split[0];
        $signature = $split[1];

        if ( $algo !== 'sha256' ) return false;

        $expected = hash_hmac( 'sha256', $payload, $secret );
        return hash_equals( $expected, $signature );
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
