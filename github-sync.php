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
        
        $args = array(
            'timeout' => 300,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/GitHub-Sync'
            )
        );

        if ( ! empty( $repo['token'] ) ) {
            $args['headers']['Authorization'] = 'token ' . $repo['token'];
        }

        $response = wp_remote_get( $zip_url, $args );
        if ( is_wp_error( $response ) ) {
            $this->log_event( "Download failed for " . $repo['url'] . ": " . $response->get_error_message(), 'error' );
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            $this->log_event( "Download failed for " . $repo['url'] . ": HTTP " . $response_code . " - " . wp_remote_retrieve_response_message( $response ), 'error' );
            return new WP_Error( 'http_error', "HTTP " . $response_code . " - " . wp_remote_retrieve_response_message( $response ) );
        }

        // Initialize Filesystem
        if ( ! WP_Filesystem() ) {
            return new WP_Error( 'filesystem_error', 'Could not initialize session for directory extraction.' );
        }
        
        global $wp_filesystem;

        $temp_file = get_temp_dir() . 'github_sync_zip_' . $id . '.zip';
        $wp_filesystem->put_contents( $temp_file, wp_remote_retrieve_body( $response ) );
        
        global $wp_filesystem;

        $target_dir = WP_PLUGIN_DIR . '/' . $repo['folder'];
        
        $temp_extract_dir = get_temp_dir() . 'github_sync_' . $id;
        if ( $wp_filesystem->is_dir( $temp_extract_dir ) ) {
            $wp_filesystem->delete( $temp_extract_dir, true );
        }
        $wp_filesystem->mkdir( $temp_extract_dir );

        $unzipped = unzip_file( $temp_file, $temp_extract_dir );
        @unlink( $temp_file );

        if ( is_wp_error( $unzipped ) ) {
            $this->log_event( "Extraction failed for " . $repo['url'] . ": " . $unzipped->get_error_message(), 'error' );
            return $unzipped;
        }

        $extracted_folders = $wp_filesystem->dirlist( $temp_extract_dir );
        if ( ! empty( $extracted_folders ) ) {
            $root_folder = '';
            foreach ( $extracted_folders as $folder ) {
                if ( $folder['type'] === 'd' ) {
                    $root_folder = $folder['name'];
                    break;
                }
            }

            if ( $root_folder ) {
                $source_path = trailingslashit( $temp_extract_dir ) . $root_folder;
                
                if ( $wp_filesystem->is_dir( $target_dir ) ) {
                    $wp_filesystem->delete( $target_dir, true );
                }
                
                $wp_filesystem->mkdir( $target_dir );
                copy_dir( $source_path, $target_dir );
                $wp_filesystem->delete( $temp_extract_dir, true );
                
                $this->log_event( "Synchronized " . $repo['url'] . " to " . $repo['folder'], 'success' );
                return true;
            }
        }

        return new WP_Error( 'extract_error', 'Could not find extracted folder structure.' );
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
