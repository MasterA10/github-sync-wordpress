# GitHub Sync for WordPress

GitHub Sync is a powerful WordPress plugin designed to keep your plugins updated directly from GitHub repositories. It supports private repositories, custom branch selection, and automated synchronization via WP-Cron.

## 🚀 Features

- **Private Repository Support**: Sync securely using Personal Access Tokens (PAT).
- **Automated Updates**: Schedule syncs (Hourly, Daily, etc.) using WordPress Cron.
- **Custom Directory Names**: Choose exactly where your plugin should be installed.
- **Detailed Update Timeline**: Monitor every step of the sync process (Download, extraction, file movement) directly from the admin panel.
- **Developer Friendly**: Built to handle complex repository structures and private source code.

## 🛠 Project Challenges & Solutions

During development, we faced several technical challenges, particularly relating to the Windows environment (LocalWP) and GitHub's latest security standards.

### 1. Private Repository Authentication (404 Errors)
**Problem**: Standard WordPress functions like `download_url()` were failing to pass the required Authorization headers for private repositories, leading to 404 Not Found errors.
**Solution**: We refactored the download logic to use `wp_remote_get()` with manual Bearer token headers and streamed the binary response directly to a temporary file.

### 2. Fine-Grained Personal Access Tokens
**Problem**: GitHub's newer "Fine-Grained" tokens have stricter requirements and sometimes returned empty zipballs if not properly handled.
**Solution**: We implemented the `X-GitHub-Api-Version: 2022-11-28` header and ensured the token `Contents: Read-only` permission was addressed in the documentation.

### 3. "Empty Directory" Extraction (Windows/LocalWP)
**Problem**: Standard WordPress extraction utilities (`unzip_file`) and the system `TEMP` folder were failing silently on Windows. The plugin would report a successful extraction, but the target directory would appear empty.
**Solution**:
- **Native PHP Logic**: We replaced WordPress abstractions with native PHP `ZipArchive` and `scandir` for maximum reliability on Windows.
- **Safe Storage**: We moved temporary extraction from the system `C:\Windows\TEMP` folder to the site's own `wp-content/uploads` directory, which is guaranteed to be writable and visible to the PHP process.

### 4. Binary Data Integrity
**Problem**: Saving binary ZIP data through certain WordPress filesystem methods was causing corruption.
**Solution**: Switched to native `file_put_contents()` for saving the ZIP binary directly, ensuring the archive integrity is maintained.

## 📦 Installation

1. Upload the `github-sync` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Settings > GitHub Sync** to add your first repository.

## 📝 License

This project is open-source and available under the MIT License.
