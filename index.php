<?php
/**
 * Bunkr Advanced Downloader
 * 
 * This script allows downloading media files from Bunkr.cr and similar sites.
 * It supports both web interface and command-line usage.
 * 
 * Features:
 * - Downloads single files or entire albums
 * - Supports wget and curl download methods
 * - File integrity verification
 * - Resume interrupted downloads
 * - Extension filtering
 * - Custom download paths
 * - URL list export option
 * 
 * Usage:
 * Web: Access through browser and use the form interface
 * CLI: php index.php -u <url> [-r retries] [-e extensions] [-p path] [-w]
 * 
 * Dependencies:
 * - PHP 7.0+
 * - curl extension
 * - wget (optional)
 * - write permissions in download directory
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

set_time_limit(0);
ini_set('memory_limit', '512M');

define('BUNKR_VS_API_URL_FOR_SLUG', 'https://bunkr.cr/api/vs');
define('SECRET_KEY_BASE', 'SECRET_KEY_');
define('DEFAULT_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36');

$isConsole = (php_sapi_name() === 'cli');

function is_wget_available() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $null = 'NUL';
        exec('where wget > ' . escapeshellarg($null) . ' 2> ' . escapeshellarg($null), $output, $return_var);
    } else {
        $null = '/dev/null';
        exec('which wget > ' . escapeshellarg($null) . ' 2> ' . escapeshellarg($null), $output, $return_var);
    }
    return $return_var === 0;
}

$wget_available = is_wget_available();

function create_session() {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, DEFAULT_USER_AGENT);
    curl_setopt($ch, CURLOPT_REFERER, 'https://bunkr.sk/');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Connection: keep-alive'
    ]);
    return $ch;
}

function curl_get_contents($ch, $url, $cookies = [], $referer = '') {
    curl_setopt($ch, CURLOPT_URL, $url);
    
    if (!empty($cookies)) {
        $cookie_string = '';
        foreach ($cookies as $key => $value) {
            $cookie_string .= "$key=$value; ";
        }
        curl_setopt($ch, CURLOPT_COOKIE, $cookie_string);
    }
    
    if (!empty($referer)) {
        curl_setopt($ch, CURLOPT_REFERER, $referer);
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    
    if (strlen($response) > 5000000) {
        $response = substr($response, 0, 5000000);
    }
    
    return $response;
}

function curl_post_json($ch, $url, $data) {
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_setopt($ch, CURLOPT_POST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Connection: keep-alive'
    ]);
    
    if ($status_code !== 200) {
        return null;
    }
    
    return $response;
}

function download_file($ch, $url, $save_path, $is_bunkr = true, $referer = '') {
    global $wget_available;

    // Sanitize file path for shell usage
    $save_path_safe = escapeshellarg($save_path);
    $url_safe = escapeshellarg($url);
    $referer_safe = escapeshellarg($referer ?: 'https://bunkr.sk/');
    $user_agent_safe = escapeshellarg(DEFAULT_USER_AGENT);

    if (file_exists($save_path)) {
        $file_size = filesize($save_path);
        return ["success" => true, "size" => $file_size, "message" => "File already exists, skipped download"];
    }

    if ($wget_available) {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $wget_cmd = 'wget --quiet --show-progress --no-check-certificate --user-agent=' . $user_agent_safe .
                ' --referer=' . $referer_safe . ' ' . $url_safe . ' -O ' . $save_path_safe;
        } else {
            $wget_cmd = "wget --quiet --show-progress --no-check-certificate --user-agent=" . $user_agent_safe .
                " --referer=" . $referer_safe . " " . $url_safe . " -O " . $save_path_safe;
        }

        exec($wget_cmd, $output, $return_var);

        if ($return_var === 0 && file_exists($save_path)) {
            $file_size = filesize($save_path);
            if ($file_size > 0) {
                return ["success" => true, "size" => $file_size, "method" => "wget"];
            }
        }
    }
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_REFERER, $referer ?: 'https://bunkr.sk/');
    
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $file_size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($http_code !== 200) {
        return ["success" => false, "error" => "HTTP error $http_code"];
    }
    
    curl_setopt($ch, CURLOPT_NOBODY, false);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $content = curl_exec($ch);
    
    if ($content === false) {
        return ["success" => false, "error" => curl_error($ch)];
    }
    
    if (strpos($url, 'bnkr.b-cdn.net/maintenance.mp4') !== false) {
        return ["success" => false, "error" => "Server is down for maintenance"];
    }
    
    if (file_put_contents($save_path, $content) === false) {
        return ["success" => false, "error" => "Failed to save file"];
    }
    
    $content = null;
    
    if ($is_bunkr && $file_size > -1) {
        $downloaded_size = filesize($save_path);
        if ($downloaded_size != $file_size) {
            return ["success" => false, "error" => "Size check failed ($downloaded_size vs $file_size), file could be broken"];
        }
    }
    
    return ["success" => true, "size" => $file_size, "method" => "curl"];
}

function get_url_data($url) {
    $parsed_url = parse_url($url);
    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
    $file_name = basename($path);
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    
    return [
        'file_name' => $file_name,
        'extension' => ".$extension",
        'hostname' => isset($parsed_url['host']) ? $parsed_url['host'] : ''
    ];
}

function get_and_prepare_download_path($custom_path, $album_name) {
    $final_path = $custom_path ?: __DIR__ . '/downloads';
    
    if ($album_name) {
        $final_path .= '/' . $album_name;
    }
    
    $final_path = str_replace("\n", '', $final_path);
    
    if (!is_dir($final_path)) {
        mkdir($final_path, 0755, true);
    }
    
    $already_downloaded_path = $final_path . '/already_downloaded.txt';
    if (!file_exists($already_downloaded_path)) {
        file_put_contents($already_downloaded_path, '');
    }
    
    return $final_path;
}

function get_already_downloaded_url($download_path) {
    $file_path = $download_path . '/already_downloaded.txt';
    
    if (!file_exists($file_path)) {
        return [];
    }
    
    return explode("\n", file_get_contents($file_path));
}

function mark_as_downloaded($item_url, $download_path) {
    $file_path = $download_path . '/already_downloaded.txt';
    file_put_contents($file_path, $item_url . "\n", FILE_APPEND);
}

function write_url_to_list($item_url, $download_path) {
    $list_path = $download_path . '/url_list.txt';
    file_put_contents($list_path, $item_url . "\n", FILE_APPEND);
}

function remove_illegal_chars($string) {
    return preg_replace('/[<>:"\/\\\\|?*\']|[\x00-\x1F]/', "-", $string);
}

function get_encryption_data($ch, $slug) {
    $response = curl_post_json($ch, BUNKR_VS_API_URL_FOR_SLUG, ['slug' => $slug]);
    
    if ($response === null) {
        return null;
    }
    
    return json_decode($response, true);
}

function decrypt_encrypted_url($encryption_data) {
    if (!$encryption_data || !isset($encryption_data['url']) || !isset($encryption_data['timestamp'])) {
        return null;
    }
    
    $secret_key = SECRET_KEY_BASE . floor($encryption_data['timestamp'] / 3600);
    $encrypted_url_bytearray = str_split(base64_decode($encryption_data['url']));
    $secret_key_byte_array = str_split($secret_key);
    
    $decrypted_url = "";
    
    for ($i = 0; $i < count($encrypted_url_bytearray); $i++) {
        $decrypted_url .= chr(ord($encrypted_url_bytearray[$i]) ^ ord($secret_key_byte_array[$i % count($secret_key_byte_array)]));
    }
    
    return $decrypted_url;
}

function get_real_download_url($ch, $url, $is_bunkr = true) {
    if ($is_bunkr) {
        if (strpos($url, 'https://') === false && strpos($url, 'http://') === false) {
            $url = 'https://bunkr.sk' . (substr($url, 0, 1) === '/' ? '' : '/') . $url;
        }
    } else {
        $url = str_replace('/f/', '/api/f/', $url);
    }
    
    $response = curl_get_contents($ch, $url);
    
    if (!$response) {
        return null;
    }
    
    if ($is_bunkr) {
        if (preg_match('/\/f\/(.*?)$/', $url, $matches)) {
            $slug = $matches[1];
            $encryption_data = get_encryption_data($ch, $slug);
            
            if ($encryption_data) {
                $encrypted_url = decrypt_encrypted_url($encryption_data);
                if ($encrypted_url) {
                    return ['url' => $encrypted_url, 'size' => -1];
                }
            }
        }
        
        if (preg_match('/<a[^>]*href="([^"]*)"[^>]*class="[^"]*btn-main[^"]*"[^>]*>Download<\/a>/', $response, $matches)) {
            return ['url' => $matches[1], 'size' => -1];
        }
        
        if (preg_match('/<a[^>]*href="([^"]*)"[^>]*download/', $response, $matches)) {
            return ['url' => $matches[1], 'size' => -1];
        }
    } else {
        $item_data = @json_decode($response, true);
        if (isset($item_data['url'])) {
            return [
                'url' => $item_data['url'], 
                'size' => -1, 
                'name' => isset($item_data['name']) ? $item_data['name'] : null
            ];
        }
    }
    
    return null;
}

function get_items_list($ch, $url, $retries = 10, $extensions = null, $only_export = false, $custom_path = null, $isConsole = false) {
    if (strpos($url, 'http') !== 0) {
        $url = 'https://' . $url;
    }
    
    $extensions_list = $extensions ? explode(',', $extensions) : [];
    
    if (!$isConsole) {
        echo "<div class='debug'>Processing URL: " . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</div>";
    }
    
    $response = curl_get_contents($ch, $url);
    
    if (!$response) {
        $error_code = curl_errno($ch);
        $error_msg = "Failed to fetch the Bunkr page. Error: " . curl_error($ch) . " (Code: $error_code)";
        if ($isConsole) {
            echo htmlspecialchars($error_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
        } else {
            echo "<div class='error'>" . htmlspecialchars($error_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</div>";
        }
        return ['success' => false, 'message' => $error_msg];
    }
    
    $dom = new DOMDocument();
    
    libxml_use_internal_errors(true);
    @$dom->loadHTML($response, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    $title_element = $xpath->query('//title')->item(0);
    $is_bunkr = $title_element && strpos($title_element->textContent, "| Bunkr") !== false;
    $items = [];
    $album_name = '';

    if ($is_bunkr) {
        $videos_gallery = $xpath->query('//span[contains(@class, "ic-videos")], //div[contains(@class, "lightgallery")]');
        $is_direct_link = $videos_gallery instanceof DOMNodeList && $videos_gallery->length > 0;
        
        $album_name = getAlbumName($xpath, $is_direct_link);

        if ($is_direct_link) {
            if ($real_url = get_real_download_url($ch, $url, true)) {
                $items[] = $real_url;
            } else {
                echo $isConsole ? "Failed to get download URL for direct link\n" 
                               : "<div class='error'>Failed to get download URL for direct link</div>";
            }
        } else {
            foreach ($xpath->query('//a[contains(@class, "after:absolute")]') as $box) {
                if ($href = $box->getAttribute('href')) {
                    $items[] = ['url' => $href, 'size' => -1];
                }
            }
        }
    } else {
        $items_dom = $xpath->query('//a[contains(@class, "image")]');
        foreach ($items_dom as $item_dom) {
            $items[] = ['url' => "https://cyberdrop.me" . $item_dom->getAttribute('href'), 'size' => -1];
        }
        
        $album_name_element = $xpath->query('//h1[@id="title"]')->item(0);
        $album_name = $album_name_element ? remove_illegal_chars(trim($album_name_element->textContent)) : 'cyberdrop_album_' . date('Y-m-d_H-i-s');
    }
    
    if (empty($items)) {
        if ($isConsole) {
            echo htmlspecialchars("No items found in the provided URL", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
        } else {
            echo "<div class='error'>No items found in the provided URL</div>";
        }
        return ['success' => false, 'message' => 'No items found'];
    }
    
    $download_path = get_and_prepare_download_path($custom_path, $album_name);
    
    if (!is_writable($download_path)) {
        $error_msg = "Download directory is not writable: " . $download_path;
        if ($isConsole) {
            echo htmlspecialchars($error_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
        } else {
            echo "<div class='error'>" . htmlspecialchars($error_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</div>";
        }
        return ['success' => false, 'message' => $error_msg];
    }
    
    $already_downloaded_url = get_already_downloaded_url($download_path);
    
    $success_count = 0;
    $fail_count = 0;
    
    if ($isConsole) {
        echo htmlspecialchars("Found " . count($items) . " items in album \"$album_name\"", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
        echo htmlspecialchars("Saving to: $download_path", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n";
    } else {
        echo "<h2>Album: " . htmlspecialchars($album_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h2>";
        echo "<p>Found " . count($items) . " items</p>";
        echo "<div class='download-progress'>";
    }
    
    foreach ($items as $item) {
        if ($item === null) continue;
        
        if ($success_count > 0 && $success_count % 10 === 0) {
            gc_collect_cycles();
        }
        
        if (!$is_direct_link) {
            if (empty($item['url'])) continue;
            
            $download_item = get_real_download_url($ch, $item['url'], $is_bunkr);
            if ($download_item === null) {
                $fail_count++;
                if ($isConsole) {
                    echo htmlspecialchars("Unable to find a download link for " . $item['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
                } else {
                    echo "<div class='error'>Unable to find a download link for " . htmlspecialchars($item['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</div>";
                }
                continue;
            }
            $item = $download_item;
        }
        
        if (!isset($item['url']) || empty($item['url'])) {
            $fail_count++;
            continue;
        }
        
        $url_data = get_url_data($item['url']);
        $extension = $url_data['extension'];
        
        if (in_array($item['url'], $already_downloaded_url) || 
            (!empty($extensions_list) && !in_array($extension, $extensions_list))) {
            continue;
        }
        
        $file_name = isset($item['name']) && !$is_bunkr ? $item['name'] : $url_data['file_name'];
        $file_name = preg_replace('/[^\w\.-]/', '_', $file_name);
        if (empty($file_name)) $file_name = 'file_' . md5($item['url']) . $extension;
        
        $save_path = $download_path . '/' . $file_name;
        
        if ($only_export) {
            write_url_to_list($item['url'], $download_path);
            $success_count++;
        } else {
            $downloaded = false;
            for ($i = 1; $i <= $retries; $i++) {
                if ($isConsole) {
                    echo htmlspecialchars("Downloading $file_name (try $i/$retries)... ", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    echo htmlspecialchars($item['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
                } else {
                    echo "<div class='file-progress'>";
                    echo "<span>Downloading: " . htmlspecialchars($file_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . " (try $i/$retries)</span>";
                }
                
                $result = download_file($ch, $item['url'], $save_path, $is_bunkr, $url);
                
                if ($result['success']) {
                    mark_as_downloaded($item['url'], $download_path);
                    $success_count++;
                    $downloaded = true;
                    
                    $status_msg = isset($result['message']) ? $result['message'] : "Success!";
                    $method_msg = isset($result['method']) ? " (using " . $result['method'] . ")" : "";
                    
                    if ($isConsole) {
                        echo htmlspecialchars($status_msg . $method_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
                    } else {
                        echo "<span class='success'>" . htmlspecialchars($status_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . htmlspecialchars($method_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</span>";
                        echo "</div>";
                    }
                    break;
                } else {
                    if ($isConsole) {
                        echo htmlspecialchars("Failed: " . $result['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
                    } else {
                        echo "<span class='error'>" . htmlspecialchars($result['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</span>";
                        echo "</div>";
                    }
                    
                    if ($i < $retries) {
                        sleep(2);
                    } else {
                        $fail_count++;
                    }
                }
            }
        }
    }
    
    if (!$only_export) {
        if ($isConsole) {
            echo htmlspecialchars("\nDownload completed!", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
            echo htmlspecialchars("Successes: $success_count", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
            echo htmlspecialchars("Failures: $fail_count", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
            echo htmlspecialchars("Files saved to: $download_path", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
        } else {
            echo "</div>";
            echo "<div class='summary'>";
            echo "<p>Download completed!</p>";
            echo "<p>Successfully downloaded: $success_count files</p>";
            echo "<p>Failed: $fail_count files</p>";
            echo "<p>Files saved to: " . htmlspecialchars($download_path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
            echo "</div>";
        }
    } else {
        $message = "URL list exported to {$download_path}/url_list.txt";
        if ($isConsole) {
            echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
        } else {
            echo "<div class='success'>" . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</div>";
        }
    }
    
    return [
        'success' => true,
        'downloaded' => $success_count,
        'failed' => $fail_count,
        'path' => $download_path
    ];
}

function getAlbumName($xpath, $is_direct_link) {
    if ($is_direct_link) {
        $name_query = '//h1[contains(@class, "text-[20px]")] | //h1[contains(@class, "truncate")]';
    } else {
        $name_query = '//h1[contains(@class, "truncate")]';
    }
    
    $element = $xpath->query($name_query)->item(0);
    return $element ? remove_illegal_chars(trim($element->textContent)) 
                   : 'bunkr_' . ($is_direct_link ? 'download' : 'album') . '_' . date('Y-m-d_H-i-s');
}

$bunkrUrl = '';
$retries = 2;
$extensions = null;
$custom_path = null;
$only_export = false;

if ($isConsole) {
    $options = getopt("u:f:r:e:p:w");

    if (isset($options['u'])) {
        $bunkrUrl = sanitize_url($options['u']);
        if ($bunkrUrl === false) {
            echo htmlspecialchars("Invalid URL provided.", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
            exit(1);
        }
    } elseif (isset($options['f'])) {
        $file_path = sanitize_path($options['f']);
        if (file_exists($file_path)) {
            $urls = [];
            foreach (explode("\n", file_get_contents($file_path)) as $url) {
                $url = sanitize_url($url);
                if ($url !== false) {
                    $urls[] = $url;
                }
            }
            echo "Found " . count($urls) . " URLs in file.\n";
        } else {
            echo "File not found: " . htmlspecialchars($file_path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
            exit(1);
        }
    } else {
        echo "Usage: php index.php -u <bunkr_url> [-r retries] [-e extensions] [-p custom_path] [-w]\n";
        echo "   or: php index.php -f <file_with_urls> [-r retries] [-e extensions] [-p custom_path] [-w]\n";
        exit(1);
    }

    if (isset($options['r'])) $retries = (int)$options['r'];
    if (isset($options['e'])) $extensions = $options['e'];
    if (isset($options['p'])) $custom_path = sanitize_path($options['p']);
    if (isset($options['w'])) $only_export = true;
} elseif (isset($_POST['bunkr'])) {
    $bunkrUrl = sanitize_url($_POST['bunkr']);
    if ($bunkrUrl === false) {
        if (!$isConsole) {
            echo "<div class='error'>Invalid URL provided.</div>";
        } else {
            echo htmlspecialchars("Invalid URL provided.", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
        }
        exit(1);
    }
    if (isset($_POST['retries'])) $retries = min(50, max(1, (int)$_POST['retries']));
    if (isset($_POST['extensions'])) $extensions = trim(preg_replace('/[^a-zA-Z0-9,]/', '', $_POST['extensions']));
    if (isset($_POST['custom_path'])) {
        $custom_path = sanitize_path($_POST['custom_path']);
        $custom_path_real = realpath($custom_path);
        if ($custom_path_real === false || strpos($custom_path_real, realpath(__DIR__)) !== 0) {
            $custom_path = __DIR__ . '/downloads';
        } else {
            $custom_path = $custom_path_real;
        }
    }
    if (isset($_POST['only_export'])) $only_export = ($_POST['only_export'] == '1');
}

if (!empty($bunkrUrl) || (isset($urls) && !empty($urls))) {
    try {
        $ch = create_session();
        
        if (!empty($bunkrUrl)) {
            get_items_list($ch, $bunkrUrl, $retries, $extensions, $only_export, $custom_path, $isConsole);
        } elseif (isset($urls)) {
            foreach ($urls as $url) {
                $url = trim($url);
                if (empty($url)) continue;
                
                if ($isConsole) {
                    echo htmlspecialchars("\nProcessing: $url", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
                } else {
                    echo "<h3>Processing: " . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h3>";
                }
                
                get_items_list($ch, $url, $retries, $extensions, $only_export, $custom_path, $isConsole);
            }
        }
        
        curl_close($ch);
        
        if (!$isConsole) {
            echo "<div class='navigation'><a href='index.php'>Back to Download Form</a></div>";
        }
    } catch (Exception $e) {
        if ($isConsole) {
            echo htmlspecialchars("Error: " . $e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
        } else {
            echo "<div class='error'>Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</div>";
            echo "<div class='navigation'><a href='index.php'>Back to Download Form</a></div>";
        }
    }
    exit(0);
}

if (!$isConsole) {
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bunkr Advanced Downloader</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="card">
            <div class="card-body">
                <h1 class="card-title mb-4">Bunkr Advanced Downloader</h1>
                <p class="lead">Enter a Bunkr.cr URL to download all media files from an album or file page</p>
                
                <form action="index.php" method="post">
                    <div class="mb-3">
                        <label for="bunkr" class="form-label">Bunkr URL:</label>
                        <input type="text" class="form-control" id="bunkr" name="bunkr" 
                               placeholder="https://bunkr.cr/a/album-name" required>
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="retries" class="form-label">Number of retries:</label>
                                <input type="number" class="form-control" id="retries" name="retries" 
                                       value="10" min="1" max="50">
                            </div>
                            
                            <div class="mb-3">
                                <label for="extensions" class="form-label">Extensions to download:</label>
                                <input type="text" class="form-control" id="extensions" name="extensions" 
                                       placeholder="mp4,jpg,png">
                                <div class="form-text">Comma separated, leave empty for all</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="custom_path" class="form-label">Custom download path:</label>
                                <input type="text" class="form-control" id="custom_path" name="custom_path" 
                                       placeholder="/path/to/downloads">
                                <div class="form-text">Leave empty for default</div>
                            </div>
                            
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="only_export" name="only_export" value="1">
                                <label class="form-check-label" for="only_export">
                                    Only export URL list (don't download files)
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Download</button>
                </form>
                
                <div class="card mt-4">
                    <div class="card-body">
                        <h3 class="card-title">Command Line Usage</h3>
                        <div class="bg-light p-2 rounded mb-2">
                            <code>php index.php -u https://bunkr.cr/a/album-name [-r retries] [-e extensions] [-p custom_path] [-w]</code>
                        </div>
                        <p>Or with a file containing URLs:</p>
                        <div class="bg-light p-2 rounded mb-3">
                            <code>php index.php -f urls.txt [-r retries] [-e extensions] [-p custom_path] [-w]</code>
                        </div>
                        <h4>Options:</h4>
                        <ul class="list-group">
                            <li class="list-group-item"><strong>-u</strong>: URL to fetch</li>
                            <li class="list-group-item"><strong>-f</strong>: File with list of URLs</li>
                            <li class="list-group-item"><strong>-r</strong>: Number of retries (default: 10)</li>
                            <li class="list-group-item"><strong>-e</strong>: Extensions to download (comma separated)</li>
                            <li class="list-group-item"><strong>-p</strong>: Custom download path</li>
                            <li class="list-group-item"><strong>-w</strong>: Export URL list only (don't download)</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
?>
