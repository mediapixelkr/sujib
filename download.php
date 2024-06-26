<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

const LOCALE = 'fr_FR.UTF-8';

require 'functions.php';

set_time_limit(4000);
setlocale(LC_ALL, LOCALE);
putenv('LC_ALL=' . LOCALE);

if (isset($_POST["url"])) {
    $database = initializeDatabase();

    // Ensure URL includes the protocol
    $url = $_POST["url"];
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'http://' . $url;
    }

    // Extract video ID from URL
    preg_match('#(?<=(?:v|i)=)[a-zA-Z0-9-]+(?=&)|(?<=(?:v|i)\/)[^&\n]+|(?<=embed\/)[^"&\n]+|(?<=(?:v|i)=)[^&\n]+|(?<=youtu.be\/)[^&\n]+#', $url, $video_id);
    if (empty($video_id)) {
        echo json_encode(['error' => 'Invalid URL']);
        exit();
    }

    $pid = $_POST["id"];

    // Fetch profile settings
    $profile = fetchProfile($database, $pid);
    if (!$profile) {
        echo json_encode(['error' => 'Profile not found']);
        exit();
    }

    // Fetch global options
    $options = fetchOptions($database);
    if (empty($options['download_dir'])) {
        echo json_encode(['error' => 'Download directory not set']);
        exit();
    }

    $options_subtitles = $options['subtitles'] ?? 0;
    $options_sub_lang = $options['sub_lang'] ?? 'en'; // Default to 'en' if not set

    // Determine video quality
    $quality = determineQuality($profile);

    $temp_filename = $options['download_dir'] . '/' . $profile['destination'];

    // Final filename
    $get_filename_command = 'yt-dlp ' . escapeshellarg($url) . ' --get-filename -o ' . escapeshellarg($temp_filename) . ' --merge-output-format ' . $profile['container'] . ' ' . $profile['command_line'] . ' ' . $profile['cache'];
    $filename = executeCommand($get_filename_command);

    if (!isset($filename[0]) || strpos($filename[0], "WARNING") === 0) {
        echo json_encode(['error' => 'Failed to retrieve filename']);
        exit();
    }

    $final_filename = $filename[0];
    $filesql = SQLite3::escapeString($final_filename);

    // Insert download record into queue
    $temprowid = insertIntoQueue($database, $video_id[0], $filesql);

    // Construct the download command based on subtitle options
    $download_command = 'yt-dlp ' . escapeshellarg($url) . ' --ignore-config --prefer-ffmpeg ' . $profile['command_line'];

    if ($options_subtitles == 1) {
        // Add subtitle options for external subtitles
        $download_command .= ' --write-sub --write-auto-sub --sub-lang ' . $options_sub_lang . '.* --convert-subs srt';
    } elseif ($options_subtitles == 2) {
        // Add subtitle options for embedded subtitles
        $download_command .= ' --write-subs --write-auto-subs --embed-subs --compat-options no-keep-subs --sub-lang ' . $options_sub_lang . '.*';
    }

    $download_command .= ' -o ' . escapeshellarg($final_filename) . ' --merge-output-format ' . $profile['container'] . ' ' . $profile['cache'] . ' ' . $quality . ' --quiet';

    // Log the command
    //error_log("Download command: " . $download_command);

    // Execute the download command
    exec($download_command . ' 2>&1', $output, $return_var);

    // Log the output
    //error_log("Download output: " . implode("\n", $output));

    // Verify the file existence
    if (file_exists($final_filename)) {
        // Fetch media info
        $mediainfo = fetchMediaInfo($final_filename);
        
        // Insert download record into downloaded table
        $date = date("F d Y H:i:s", filemtime($final_filename));
        $rowid = insertIntoDownloaded($database, $video_id[0], $mediainfo, $final_filename, $date, $temprowid);

        // Remove from queue
        removeFromQueue($database, $temprowid);

        // Prepare response
        $table = createTable(basename($final_filename), $mediainfo, $date);
        $response = ['id' => $rowid, 'table' => $table];
        echo json_encode($response);
    } else {
        echo json_encode(['error' => 'Download failed']);
    }

    $database->close();
}
?>
