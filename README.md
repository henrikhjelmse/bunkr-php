# Bunkr Advanced Downloader

Bunkr Advanced Downloader is a PHP-based tool for downloading media files from [Bunkr.cr](https://bunkr.cr) and similar sites. It supports both web and command-line interfaces, making it versatile for various use cases.

## Features

- Download single files or entire albums.
- Supports `wget` and `curl` as download methods.
- File integrity verification to ensure successful downloads.
- Resume interrupted downloads.
- Filter downloads by file extensions.
- Customizable download paths.
- Export URL lists without downloading files.

## Requirements

- PHP 7.0 or higher.
- `curl` PHP extension.
- `wget` (optional, for faster downloads).
- Write permissions in the download directory.

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/your-username/bunkr-php.git
   cd bunkr-php
   ```

2. Ensure PHP and required extensions are installed:
   ```bash
   php -v
   php -m | grep curl
   ```

3. (Optional) Install `wget` for enhanced download capabilities:
   ```bash
   # On Debian/Ubuntu
   sudo apt install wget

   # On macOS
   brew install wget
   ```

## Usage

### Web Interface

1. Start a local PHP server:
   ```bash
   php -S localhost:8000
   ```

2. Open your browser and navigate to:
   ```
   http://localhost:8000
   ```

3. Enter the Bunkr URL and configure options to start downloading.

### Command-Line Interface (CLI)

Run the script with the following options:

```bash
php index.php -u <bunkr_url> [-r retries] [-e extensions] [-p custom_path] [-w]
```

#### Options:
- `-u`: URL of the album or file to download.
- `-f`: File containing a list of URLs to process.
- `-r`: Number of retries for failed downloads (default: 10).
- `-e`: Comma-separated list of file extensions to download (e.g., `mp4,jpg,png`).
- `-p`: Custom download path (default: `downloads` directory).
- `-w`: Export URL list only (no downloads).

#### Examples:

1. Download an album:
   ```bash
   php index.php -u https://bunkr.cr/a/album-name
   ```

2. Download specific file types:
   ```bash
   php index.php -u https://bunkr.cr/a/album-name -e mp4,jpg
   ```

3. Export URL list without downloading:
   ```bash
   php index.php -u https://bunkr.cr/a/album-name -w
   ```

4. Process multiple URLs from a file:
   ```bash
   php index.php -f urls.txt
   ```

## Project Structure

- `index.php`: Main script for both web and CLI usage.
- `downloads/`: Default directory for downloaded files.
- `README.md`: Documentation for the project.

## Contributing

Contributions are welcome! Feel free to open issues or submit pull requests to improve the project.


## Disclaimer

This tool is intended for educational purposes only. Use it responsibly and ensure compliance with the terms of service of the websites you interact with.
