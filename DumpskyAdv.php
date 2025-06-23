<?php
// --- DEBUGGING & CONFIGURATION ---
// SET TO true TO ENABLE ERROR LOGGING TO 'debug.log'.
// This is essential for diagnosing HTTP 500 errors.
define('DEBUG_MODE', true);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Do not display errors to the browser
    ini_set('log_errors', 1);
    ini_set('error_log', 'debug.log'); // Errors will be saved to this file
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 0);
}

// Prerequisite check: Ensure the mysqli extension is available.
if (!function_exists('mysqli_connect')) {
    $errorMessage = 'PHP Error: The mysqli extension is not installed or enabled. This is required to connect to the database. Please check your php.ini configuration.';
    if (DEBUG_MODE) {
        error_log($errorMessage);
    }
    // We can't proceed, so show a user-friendly message.
    http_response_code(500);
    die($errorMessage);
}


// Start the session after initial checks.
session_start();

// --- DYNAMIC TEXT & FILENAME CONFIGURATION ---
$TEXTS = [
    'filenames' => [
        'main_prefix'     => 'db_backup_main_',
        'table_prefix'    => 'db_backup_table_',
        'selected_prefix' => 'db_backup_selected_'
    ],
    'messages' => [
        'no_config'              => 'No database configuration found in session. Please connect first.',
        'no_tables'              => 'No tables found to export.',
        'invalid_table'          => 'Invalid table name specified.',
        'db_connection_failed'   => 'Database Connection Failed: {error}',
        'file_creation_failed'   => 'Failed to create backup file. Check server write permissions.',
        'exporting_structure'    => 'Exporting structure for: `{table}`...',
        'exporting_data'         => 'Exporting table `{table}`: {rows_processed}/{total_rows} rows...',
        'exporting_format'       => 'Exporting table `{table}` as {format}...',
        'export_complete'        => 'Export complete!',
        'export_cancelled'       => 'Export cancelled by user.',
        'dump_header_db'         => '-- SQL Dump For Database: `{db_name}`',
        'dump_header_time'       => '-- Generation Time: {time}',
        'unsupported_format'     => 'The selected export format ({format}) is not yet supported.',
        'zip_not_supported'      => 'Error: The ZipArchive PHP extension is required for multi-table CSV export but is not enabled on this server.'
    ],
];


// --- SCRIPT ROUTING ---
$action = $_GET['action'] ?? 'render_ui';

switch ($action) {
    case 'analyze_tables':
        handle_analyze_tables();
        break;
    case 'export_main':
        perform_main_export();
        break;
    case 'export_table': // For single table export
        perform_single_table_export($_GET['table_name'] ?? '');
        break;
    case 'prepare_selected_export': // Prepares the session for selected export
        handle_prepare_selected_export();
        break;
    case 'stream_selected_export': // Streams the prepared selected export
        perform_selected_export();
        break;
    case 'stop_export':
        handle_stop_export();
        break;
    default:
        display_html_interface();
        break;
}

/**
 * Connects to the DB, analyzes all tables using a user-defined threshold, and returns a list with sizes.
 */
function handle_analyze_tables() {
    header('Content-Type: application/json');
    $config = json_decode(file_get_contents('php://input'), true);

    if (!$config) {
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit();
    }

    // Simulate network latency for testing shimmer effect
    // sleep(2);

    $mysqli = @new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);

    if ($mysqli->connect_error) {
        if(defined('DEBUG_MODE') && DEBUG_MODE) error_log("DB Connection Error: " . $mysqli->connect_error);
        echo json_encode(['success' => false, 'error' => $mysqli->connect_error]);
        exit();
    }
    
    $_SESSION['db_config'] = $config;

    $query = "SELECT table_name, (data_length + index_length) as table_bytes 
              FROM information_schema.TABLES 
              WHERE table_schema = ? 
              ORDER BY table_bytes DESC";

    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        if(defined('DEBUG_MODE') && DEBUG_MODE) error_log("Analyze Tables Prepare Failed: " . $mysqli->error);
        echo json_encode(['success' => false, 'error' => 'Failed to prepare database query.']);
        exit();
    }
    $stmt->bind_param('s', $config['name']);
    $stmt->execute();
    $result = $stmt->get_result();

    $tables = [];
    $large_table_threshold_mb = $config['threshold_mb'] ?? 10;
    $large_table_threshold_bytes = $large_table_threshold_mb * 1024 * 1024;

    while ($row = $result->fetch_assoc()) {
        $table_bytes = $row['table_bytes'] ?? 0;
        $tables[] = [
            'name' => $row['table_name'],
            'size_bytes' => (int)$table_bytes,
            'size_mb' => round($table_bytes / 1024 / 1024, 2),
            'is_large' => $table_bytes > $large_table_threshold_bytes,
        ];
    }
    $stmt->close();
    $mysqli->close();

    echo json_encode([
        'success' => true, 
        'tables' => $tables,
        'zip_supported' => class_exists('ZipArchive')
    ]);
    exit();
}


/**
 * Receives a list of selected tables via POST and saves them to the session for streaming.
 */
function handle_prepare_selected_export() {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    $tables_to_export = $data['tables'] ?? [];

    if (empty($tables_to_export)) {
        echo json_encode(['success' => false, 'error' => 'No tables selected for export.']);
        exit();
    }
    
    $_SESSION['export_settings'] = [
        'format' => preg_replace('/[^a-zA-Z]/', '', $data['format'] ?? 'sql'),
        'type' => preg_replace('/[^a-zA-Z]/', '', $data['type'] ?? 'both'),
        'csv_multi' => preg_replace('/[^a-zA-Z]/', '', $data['csv_multi'] ?? 'zip'),
    ];
    
    $_SESSION['selected_for_export'] = array_map(function($table_name) {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
    }, $tables_to_export);

    echo json_encode(['success' => true]);
    exit();
}

/**
 * Performs an export of tables stored in the session.
 */
function perform_selected_export() {
    global $TEXTS;
    @set_time_limit(0); 
    $tables_to_export = $_SESSION['selected_for_export'] ?? [];

    if (empty($tables_to_export)) {
        setup_sse_headers();
        send_sse_message('error', 'No tables found in session to export.');
        exit();
    }
    
    $settings = $_SESSION['export_settings'] ?? ['format' => 'sql', 'type' => 'both', 'csv_multi' => 'zip'];
    $format = $settings['format'];
    $type = $settings['type'];
    $csv_multi = $settings['csv_multi'];
    
    $_SESSION['export_cancelled'] = false;
    $mysqli = connect_to_db();

    $ext = $format;
    if ($format === 'csv' && count($tables_to_export) > 1 && $csv_multi === 'zip') {
        $ext = 'zip';
    }
    $backup_file_name = $TEXTS['filenames']['selected_prefix'] . $_SESSION['db_config']['name'] . '_' . date("Y-m-d_H-i-s") . '.' . $ext;
    
    $_SESSION['export_file_to_delete'] = $backup_file_name;
    session_write_close();
    export_tables($mysqli, $tables_to_export, $backup_file_name, $format, $type, $csv_multi);
}


/**
 * Sets a session flag to signal the running export process to stop.
 */
function handle_stop_export() {
    header('Content-Type: application/json');
    $_SESSION['export_cancelled'] = true;
    if (isset($_SESSION['export_file_to_delete']) && file_exists($_SESSION['export_file_to_delete'])) {
        @unlink($_SESSION['export_file_to_delete']);
    }
    unset($_SESSION['export_file_to_delete']);
    echo json_encode(['success' => true, 'message' => 'Export cancellation signal sent.']);
    exit();
}


/**
 * Establishes a database connection using session credentials.
 */
function connect_to_db() {
    global $TEXTS;
    if (!isset($_SESSION['db_config'])) {
        setup_sse_headers();
        send_sse_message('error', $TEXTS['messages']['no_config']);
        exit();
    }
    $config = $_SESSION['db_config'];
    setup_sse_headers();
    $mysqli = @new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
    if ($mysqli->connect_error) {
        $error_message = str_replace('{error}', $mysqli->connect_error, $TEXTS['messages']['db_connection_failed']);
        if(defined('DEBUG_MODE') && DEBUG_MODE) error_log("SSE DB Connection Error: " . $mysqli->connect_error);
        send_sse_message('error', $error_message);
        exit();
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

/**
 * Performs the main database export.
 */
function perform_main_export() {
    global $TEXTS;
    @set_time_limit(0);
    $mysqli = connect_to_db();
    $_SESSION['export_cancelled'] = false;
    
    $format = preg_replace('/[^a-zA-Z]/', '', $_GET['format'] ?? 'sql');
    $type = preg_replace('/[^a-zA-Z]/', '', $_GET['type'] ?? 'both');
    $csv_multi = preg_replace('/[^a-zA-Z]/', '', $_GET['csv_multi'] ?? 'zip');
    
    $skipped_tables = json_decode($_GET['skipped_tables'] ?? '[]', true);
    $allTablesResult = $mysqli->query("SHOW TABLES");
    $tables_to_export = [];
    while ($row = $allTablesResult->fetch_row()) {
        if (!in_array($row[0], $skipped_tables)) {
            $tables_to_export[] = $row[0];
        }
    }
    $allTablesResult->free();

    if (empty($tables_to_export)) {
        send_sse_message('error', $TEXTS['messages']['no_tables']);
        $mysqli->close();
        exit();
    }

    $ext = $format;
    if ($format === 'csv' && count($tables_to_export) > 1 && $csv_multi === 'zip') {
        $ext = 'zip';
    }
    $backup_file_name = $TEXTS['filenames']['main_prefix'] . $_SESSION['db_config']['name'] . '_' . date("Y-m-d_H-i-s") . '.' . $ext;
    
    $_SESSION['export_file_to_delete'] = $backup_file_name;
    session_write_close();
    export_tables($mysqli, $tables_to_export, $backup_file_name, $format, $type, $csv_multi);
}

/**
 * Performs the export for a single table.
 */
function perform_single_table_export($table_name) {
    global $TEXTS;
    @set_time_limit(0);
    $sanitized_table = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
    if (empty($sanitized_table)) {
        setup_sse_headers();
        send_sse_message('error', $TEXTS['messages']['invalid_table']);
        exit();
    }
    
    $format = preg_replace('/[^a-zA-Z]/', '', $_GET['format'] ?? 'sql');
    $type = preg_replace('/[^a-zA-Z]/', '', $_GET['type'] ?? 'both');
    $csv_multi = preg_replace('/[^a-zA-Z]/', '', $_GET['csv_multi'] ?? 'zip');

    $_SESSION['export_cancelled'] = false;
    $mysqli = connect_to_db();
    $ext = $format;
    $backup_file_name = $TEXTS['filenames']['table_prefix'] . $sanitized_table . '_' . date("Y-m-d_H-i-s") . '.' . $ext;
    
    $_SESSION['export_file_to_delete'] = $backup_file_name;
    session_write_close();
    export_tables($mysqli, [$sanitized_table], $backup_file_name, $format, $type, $csv_multi);
}


// --- CORE EXPORT AND HELPER FUNCTIONS ---

function check_for_cancellation() {
    session_start();
    $cancelled = $_SESSION['export_cancelled'] ?? false;
    session_write_close();
    return $cancelled;
}

function setup_sse_headers() { 
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    if (ob_get_level() > 0) ob_end_flush();
    ob_implicit_flush(true);
}

/**
 * Main export router. Calls the correct handler based on format.
 */
function export_tables($mysqli, $tables, $backup_file_name, $format = 'sql', $type = 'both', $csv_multi = 'zip') {
    switch ($format) {
        case 'sql':
            export_as_sql($mysqli, $tables, $backup_file_name, $type);
            break;
        case 'csv':
            export_as_csv($mysqli, $tables, $backup_file_name, $csv_multi);
            break;
        case 'xml':
            export_as_xml($mysqli, $tables, $backup_file_name);
            break;
        default:
            send_sse_message('error', 'Invalid export format specified.');
            break;
    }
}

/**
 * Handles SQL export.
 */
function export_as_sql($mysqli, $tables, $backup_file_name, $type) {
    global $TEXTS;
    $file_handle = @fopen($backup_file_name, 'w');
    if (!$file_handle) { send_sse_message('error', $TEXTS['messages']['file_creation_failed']); exit(); }
    
    session_start(); $db_name = $_SESSION['db_config']['name']; session_write_close();
    $header_db = str_replace('{db_name}', $db_name, $TEXTS['messages']['dump_header_db']);
    $header_time = str_replace('{time}', date('Y-m-d H:i:s'), $TEXTS['messages']['dump_header_time']);
    fwrite($file_handle, $header_db . "\n" . $header_time . "\n\n");
    if ($type === 'both' || $type === 'data') { fwrite($file_handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n"); }

    $total_tables = count($tables); $tables_processed = 0;
    foreach ($tables as $table) {
        if (check_for_cancellation()) { fclose($file_handle); @unlink($backup_file_name); send_sse_message('error', $TEXTS['messages']['export_cancelled']); exit(); }
        $tables_processed++; $overall_progress = round(($tables_processed / $total_tables) * 100);
        if ($type === 'both' || $type === 'structure') {
            send_sse_message('progress', str_replace('{table}', $table, $TEXTS['messages']['exporting_structure']), $overall_progress);
            $create_table_result = $mysqli->query("SHOW CREATE TABLE `{$table}`");
            if ($create_table_row = $create_table_result->fetch_assoc()) { fwrite($file_handle, "DROP TABLE IF EXISTS `{$table}`;\n"); fwrite($file_handle, $create_table_row['Create Table'] . ";\n\n"); }
            $create_table_result->free();
        }
        if ($type === 'both' || $type === 'data') {
            $count_result = $mysqli->query("SELECT COUNT(*) FROM `{$table}`"); $total_rows = $count_result->fetch_row()[0]; $count_result->free();
            if ($total_rows > 0) {
                if ($type === 'data') { send_sse_message('progress', str_replace('{table}', $table, $TEXTS['messages']['exporting_structure']), $overall_progress); }
                fwrite($file_handle, "-- Dumping data for table `{$table}`\n");
                $chunk_size = 500;
                for ($offset = 0; $offset < $total_rows; $offset += $chunk_size) {
                    if (check_for_cancellation()) { fclose($file_handle); @unlink($backup_file_name); send_sse_message('error', $TEXTS['messages']['export_cancelled']); exit(); }
                    $rows_processed_so_far = min($offset + $chunk_size, $total_rows);
                    send_sse_message('progress', str_replace(['{table}', '{rows_processed}', '{total_rows}'], [$table, $rows_processed_so_far, $total_rows], $TEXTS['messages']['exporting_data']), $overall_progress);
                    $data_result = $mysqli->query("SELECT * FROM `{$table}` LIMIT {$chunk_size} OFFSET {$offset}");
                    if ($data_result && $data_result->num_rows > 0) {
                        $num_fields = $data_result->field_count;
                        while ($row = $data_result->fetch_row()) {
                            $insert_sql = "INSERT INTO `{$table}` VALUES(";
                            for ($j = 0; $j < $num_fields; $j++) { $insert_sql .= isset($row[$j]) ? '"' . $mysqli->real_escape_string($row[$j]) . '"' : 'NULL'; if ($j < ($num_fields - 1)) $insert_sql .= ','; }
                            fwrite($file_handle, $insert_sql . ");\n");
                        }
                    }
                    if ($data_result) $data_result->free();
                }
                fwrite($file_handle, "\n");
            }
        }
    }
    if ($type === 'both' || $type === 'data') { fwrite($file_handle, "SET FOREIGN_KEY_CHECKS=1;\n"); }
    fclose($file_handle); $mysqli->close();
    session_start(); $_SESSION['export_cancelled'] = false; unset($_SESSION['export_file_to_delete']); session_write_close();
    send_sse_message('complete', $TEXTS['messages']['export_complete'], 100, $backup_file_name);
}

/**
 * Handles CSV export. Creates a single CSV for one table, or a ZIP/concatenated file for multiple.
 */
function export_as_csv($mysqli, $tables, $backup_file_name, $multi_file_mode = 'zip') {
    global $TEXTS;
    $is_multi_table = (count($tables) > 1);

    if ($is_multi_table && $multi_file_mode === 'zip') {
        if (!class_exists('ZipArchive')) { send_sse_message('error', $TEXTS['messages']['zip_not_supported']); exit(); }
        $zip = new ZipArchive();
        if ($zip->open($backup_file_name, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) { send_sse_message('error', $TEXTS['messages']['file_creation_failed']); exit(); }
    } else {
        $file_handle = @fopen($backup_file_name, 'w');
        if (!$file_handle) { send_sse_message('error', $TEXTS['messages']['file_creation_failed']); exit(); }
    }

    $total_tables = count($tables); $tables_processed = 0;
    foreach($tables as $table) {
        if (check_for_cancellation()) { if(isset($zip)) $zip->close(); if(isset($file_handle)) fclose($file_handle); @unlink($backup_file_name); send_sse_message('error', $TEXTS['messages']['export_cancelled']); exit(); }
        $tables_processed++; $overall_progress = round(($tables_processed / $total_tables) * 100);
        send_sse_message('progress', str_replace(['{table}', '{format}'], [$table, 'CSV'], $TEXTS['messages']['exporting_format']), $overall_progress);
        
        $current_handle = isset($zip) ? fopen('php://temp', 'w+') : $file_handle;
        if ($is_multi_table && $multi_file_mode === 'single') { fwrite($current_handle, "\n#\n# TABLE: `{$table}`\n#\n"); }

        $fields_result = $mysqli->query("SELECT * FROM `{$table}` LIMIT 0");
        $headers = [];
        foreach ($fields_result->fetch_fields() as $field) { $headers[] = $field->name; }
        $fields_result->free(); fputcsv($current_handle, $headers);

        $count_result = $mysqli->query("SELECT COUNT(*) FROM `{$table}`"); $total_rows = $count_result->fetch_row()[0]; $count_result->free();
        if ($total_rows > 0) {
            $chunk_size = 500;
             for ($offset = 0; $offset < $total_rows; $offset += $chunk_size) {
                 if (check_for_cancellation()) { fclose($current_handle); if(isset($zip)) $zip->close(); @unlink($backup_file_name); send_sse_message('error', $TEXTS['messages']['export_cancelled']); exit(); }
                 $rows_processed_so_far = min($offset + $chunk_size, $total_rows);
                 send_sse_message('progress', str_replace(['{table}', '{rows_processed}', '{total_rows}'], [$table, $rows_processed_so_far, $total_rows], $TEXTS['messages']['exporting_data']), $overall_progress);
                 $data_result = $mysqli->query("SELECT * FROM `{$table}` LIMIT {$chunk_size} OFFSET {$offset}");
                 while ($row = $data_result->fetch_assoc()) { fputcsv($current_handle, $row); }
                 $data_result->free();
             }
        }
        if (isset($zip)) { rewind($current_handle); $zip->addFromString($table . '.csv', stream_get_contents($current_handle)); fclose($current_handle); }
    }
    if (isset($zip)) { $zip->close(); } else { fclose($file_handle); }
    $mysqli->close();
    session_start(); $_SESSION['export_cancelled'] = false; unset($_SESSION['export_file_to_delete']); session_write_close();
    send_sse_message('complete', $TEXTS['messages']['export_complete'], 100, $backup_file_name);
}

/**
 * Handles XML export.
 */
function export_as_xml($mysqli, $tables, $backup_file_name) {
    global $TEXTS;
    $writer = new XMLWriter(); $writer->openUri($backup_file_name); $writer->startDocument('1.0', 'UTF-8'); $writer->setIndent(true);
    session_start(); $db_name = $_SESSION['db_config']['name']; session_write_close();
    $writer->startElement('database'); $writer->writeAttribute('name', $db_name);

    $total_tables = count($tables); $tables_processed = 0;
    foreach ($tables as $table) {
        if (check_for_cancellation()) { @unlink($backup_file_name); send_sse_message('error', $TEXTS['messages']['export_cancelled']); exit(); }
        $tables_processed++; $overall_progress = round(($tables_processed / $total_tables) * 100);
        send_sse_message('progress', str_replace(['{table}', '{format}'], [$table, 'XML'], $TEXTS['messages']['exporting_format']), $overall_progress);
        $writer->startElement($table);
        $count_result = $mysqli->query("SELECT COUNT(*) FROM `{$table}`"); $total_rows = $count_result->fetch_row()[0]; $count_result->free();
        if ($total_rows > 0) {
            $chunk_size = 500;
             for ($offset = 0; $offset < $total_rows; $offset += $chunk_size) {
                 if (check_for_cancellation()) { @unlink($backup_file_name); send_sse_message('error', $TEXTS['messages']['export_cancelled']); exit(); }
                 $rows_processed_so_far = min($offset + $chunk_size, $total_rows);
                 send_sse_message('progress', str_replace(['{table}', '{rows_processed}', '{total_rows}'], [$table, $rows_processed_so_far, $total_rows], $TEXTS['messages']['exporting_data']), $overall_progress);
                 $data_result = $mysqli->query("SELECT * FROM `{$table}` LIMIT {$chunk_size} OFFSET {$offset}");
                 while ($row = $data_result->fetch_assoc()) {
                     $writer->startElement('row');
                     foreach ($row as $key => $value) { if (!is_null($value)) { $writer->writeElement($key, $value); } }
                     $writer->endElement();
                 }
                 $data_result->free();
             }
        }
        $writer->endElement();
    }
    $writer->endElement(); $writer->endDocument(); $writer->flush();
    $mysqli->close();
    session_start(); $_SESSION['export_cancelled'] = false; unset($_SESSION['export_file_to_delete']); session_write_close();
    send_sse_message('complete', $TEXTS['messages']['export_complete'], 100, $backup_file_name);
}

function send_sse_message($event, $message, $progress = null, $file = null) {
    $data = ['message' => $message, 'progress' => $progress, 'file' => $file];
    echo "event: " . $event . "\n";
    echo "data: " . json_encode($data) . "\n\n";
    if (ob_get_level() > 0) flush();
}


/**
 * Displays the main HTML page.
 */
function display_html_interface() {
    $debug_message = '';
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $debug_message = '<div class="mt-4 text-center bg-amber-100 text-amber-800 p-3 rounded-md text-sm"><strong>Debug Mode is ON.</strong> Any server errors (like HTTP 500) will be logged to a <code>debug.log</code> file in this script\'s directory.</div>';
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Exporter</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; /* bg-slate-50 */ }
        .form-input { @apply w-full h-11 px-4 py-2 bg-white border border-slate-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/50 focus:outline-none transition-all duration-200; }
        .form-label { @apply block text-sm font-medium text-slate-700; }
        .screen { transition: opacity 0.3s ease-out, transform 0.3s ease-out; }
        .screen.hidden { opacity: 0; transform: scale(0.98); pointer-events: none; position: absolute; top:0; left:0; width: 100%; }
        
        /* ENHANCED Button & Animation Styles */
        .btn-animate { @apply transform transition-all duration-200 ease-in-out; }
        .btn-animate:hover { @apply -translate-y-0.5; }
        .btn-animate:active { @apply scale-95; }
        
        .progress-bar-animated {
            background-image: linear-gradient(45deg, hsla(0,0%,100%,.15) 25%,transparent 25%,transparent 50%,hsla(0,0%,100%,.15) 50%,hsla(0,0%,100%,.15) 75%,transparent 75%,transparent);
            background-size: 40px 40px;
            animation: progress-stripes 1s linear infinite;
        }
        
        /* Shimmer Effect */
        @keyframes shimmer {
            0% { background-position: -468px 0; }
            100% { background-position: 468px 0; } 
        }
        .shimmer-bg {
            animation: shimmer 1.5s linear infinite;
            background-color: #e2e8f0;
            background-image: linear-gradient(to right, #e2e8f0 0%, #f1f5f9 20%, #e2e8f0 40%, #e2e8f0 100%);
            background-repeat: no-repeat;
            background-size: 800px 100%; 
        }
        
        /* --- START: Styles for Redesigned Actions Wrapper --- */
        
        /* Hide radio button visually but keep it accessible */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }
        
        /* Interactive Format Card Styling */
        .format-card {
            background-color: #f8fafc; /* bg-slate-50 */
            border-color: #e2e8f0; /* border-slate-200 */
        }

        .format-card.selected {
            background-color: #eef2ff; /* bg-indigo-50 */
            border-color: #6366f1; /* border-indigo-500 */
            box-shadow: 0 0 0 2px #6366f1;
            transform: translateY(-4px);
        }
        .format-card.selected .icon-color {
            color: #6366f1; /* text-indigo-500 */
        }
        .format-card.selected .text-color {
            color: #4338ca; /* text-indigo-700 */
        }
        
        /* Animated Collapse/Expand for Settings */
        #settingsChevron.rotated {
            transform: rotate(180deg);
        }
        #settingsContent {
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease-in-out, padding 0.4s ease-in-out;
            opacity: 0;
            max-height: 0;
            padding-top: 0;
            padding-bottom: 0;
        }
        #settingsContent.open {
            opacity: 1;
            max-height: 500px; /* Large enough to fit content */
            padding-top: 1.25rem; /* p-5 */
            padding-bottom: 1.25rem; /* p-5 */
        }
        
        /* --- END: Styles for Redesigned Actions Wrapper --- */

    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-[84rem] relative">
        <div id="connectionScreen" class="screen bg-white rounded-2xl shadow-lg p-8 lg:p-12 space-y-8 max-w-2xl mx-auto border border-slate-200/80">
            <div class="text-center">
                <h1 class="text-4xl font-bold text-slate-800">Database Connection</h1>
                <p class="text-slate-500 mt-3 text-lg">Enter your credentials to begin.</p>
            </div>
            <form id="dbForm" class="space-y-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-6">
                    <div>
                        <label for="dbHost" class="form-label mb-2">Database Host</label>
                        <input type="text" id="dbHost" class="block w-full px-4 py-3 text-slate-900 bg-slate-50 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition placeholder-slate-400" placeholder="e.g., localhost">
                    </div>
                    <div>
                        <label for="dbName" class="form-label mb-2">Database Name</label>
                        <input type="text" id="dbName" class="block w-full px-4 py-3 text-slate-900 bg-slate-50 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition placeholder-slate-400" placeholder="e.g., my_database">
                    </div>
                    <div>
                        <label for="dbUser" class="form-label mb-2">Database User</label>
                        <input type="text" id="dbUser" class="block w-full px-4 py-3 text-slate-900 bg-slate-50 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition placeholder-slate-400" placeholder="e.g., root">
                    </div>
                    <div>
                        <label for="dbPass" class="form-label mb-2">Database Password</label>
                        <input type="password" id="dbPass" class="block w-full px-4 py-3 text-slate-900 bg-slate-50 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                    </div>
                </div>

                <div class="border-t border-slate-200 pt-6">
                    <label for="thresholdSelect" class="form-label mb-2">Auto-skip tables larger than</label>
                    <select id="thresholdSelect" class="block w-full px-4 py-3 text-slate-900 bg-slate-50 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                        <option value="5">5 MB</option>
                        <option value="10" selected>10 MB</option>
                        <option value="25">25 MB</option>
                        <option value="50">50 MB</option>
                        <option value="100">100 MB</option>
                    </select>
                </div>

                <div id="connectionError" class="hidden text-center bg-red-100 text-red-700 p-3 rounded-lg"></div>

                <div class="pt-4">
                    <button type="submit" id="connectBtn" class="w-full flex justify-center items-center gap-3 bg-blue-600 text-white font-semibold h-14 py-3 px-8 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors btn-animate">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        <span id="connectBtnText" class="text-lg">Connect & Analyze</span>
                        <svg id="connectBtnSpinner" class="animate-spin h-5 w-5 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </button>
                </div>
            </form>
            <?php echo $debug_message; ?>
        </div>

        <div id="exporterScreen" class="screen hidden bg-white rounded-2xl shadow-lg p-8 lg:p-10 space-y-6 border border-slate-200/80">
            <div class="relative text-center pb-4 border-b border-slate-200">
                <button id="goBackBtn" class="absolute left-0 top-1/2 -translate-y-1/2 bg-slate-100 text-slate-700 p-2 rounded-full hover:bg-slate-200 transition-colors btn-animate" title="Start Over">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M11 15l-3-3m0 0l3-3m-3 3h8M3 12a9 9 0 1118 0 9 9 0 01-18 0z" />
                    </svg>
                </button>
                <h1 class="text-3xl font-bold text-slate-800">Exporter Dashboard</h1>
                <p class="text-slate-500 mt-2">Connected to <strong id="dbNameDisplay" class="text-slate-700 font-semibold"></strong></p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 bg-slate-100 border border-slate-200/80 rounded-xl p-1">
                    <div class="flex flex-col sm:flex-row justify-between items-center mb-2 gap-3 p-4">
                        <div>
                            <h2 class="text-xl font-bold text-slate-700">Triage Tables for Export</h2>
                            <p class="text-sm text-slate-500">Move tables between the 'Included' and 'Skipped' groups.</p>
                        </div>
                        <div class="flex items-center space-x-3 text-sm">
                            <label for="sortBySelect" class="text-sm font-medium text-slate-500">Sort by:</label>
                            <select id="sortBySelect" class="form-input !w-auto !h-auto !py-1.5 !px-3 text-sm rounded-md">
                                <option value="name" selected>Name</option>
                                <option value="size">Size</option>
                            </select>
                            <button id="skipAllBtn" class="font-medium text-blue-600 hover:text-blue-700 btn-animate" title="Skip All Tables">Skip All</button>
                            <button id="includeAllBtn" class="font-medium text-blue-600 hover:text-blue-700 btn-animate" title="Include All Tables">Include All</button>
                        </div>
                    </div>
                    <div id="tableListContainer" class="flex flex-col max-h-[555px]">
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="bg-slate-50 border border-slate-200/80 rounded-xl p-4">
                        <h3 class="text-lg font-semibold text-slate-700 text-center mb-4">Database Composition</h3>
                        <div class="relative h-48 sm:h-64">
                             <canvas id="sizePieChart"></canvas>
                        </div>
                    </div>
                    <div class="bg-blue-50 border border-blue-200/80 rounded-xl p-4 text-center">
                        <h3 class="text-base font-semibold text-blue-800">Main Export Size</h3>
                        <p id="dbExportSizeDisplay" class="text-3xl font-bold text-blue-600 mt-1">...</p>
                    </div>
                   <div class="bg-amber-50 border border-amber-200/80 rounded-xl p-4 text-center">
                        <h3 class="text-base font-semibold text-amber-800">Skipped Tables Size</h3>
                        <p id="dbSkippedSizeDisplay" class="text-3xl font-bold text-amber-600 mt-1">...</p>
                    </div>
                    <div class="bg-slate-100 border border-slate-200/80 rounded-xl p-4 text-center">
                        <h3 class="text-base font-semibold text-slate-600">Total DB Size</h3>
                        <p id="dbTotalSizeDisplay" class="text-3xl font-bold text-slate-500 mt-1">...</p>
                    </div>
                </div>
            </div>
            
            <div class="pt-6 space-y-6">
                 <div id="progressContainer" class="hidden space-y-4">
                    <p id="statusText" class="text-center text-slate-600 text-lg font-medium">Initializing...</p>
                    <div class="w-full bg-slate-200 rounded-full h-4 overflow-hidden shadow-inner">
                        <div id="progressBar" class="h-4 rounded-full progress-bar-animated" style="width: 0%; transition: width 0.3s ease-in-out;"></div>
                    </div>
                     <div id="resultArea" class="hidden text-center pt-2"></div>
                     <div id="stopBtnContainer" class="mt-4 flex justify-center">
                         <button id="stopBtn" class="w-full max-w-sm bg-red-600 text-white font-bold py-3 px-6 rounded-lg shadow-md hover:bg-red-700 btn-animate flex items-center justify-center">
                             Cancel Export
                         </button>
                     </div>
                </div>
                
                <div id="actionsWrapper" class="space-y-8">
                    <div class="bg-white border border-slate-200/80 rounded-xl shadow-sm">
                        <div id="settingsHeader" class="flex justify-between items-center p-4 cursor-pointer select-none">
                            <h3 class="text-lg font-semibold text-slate-800">Export Settings</h3>
                            <svg id="settingsChevron" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-slate-500 transition-transform duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>

                        <div id="settingsContent" class="overflow-hidden px-5">
                            <div class="border-t border-slate-200/80 space-y-6">
                                <div>
                                    <legend class="form-label mb-2">Export Format</legend>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                                        <label class="format-card p-4 border rounded-lg cursor-pointer text-center btn-animate">
                                            <input type="radio" name="exportFormat" value="sql" class="sr-only" checked>
                                            <svg class="mx-auto h-10 w-10 mb-2 icon-color text-slate-500 transition-colors" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75M3.75 6.375h16.5" /></svg>
                                            <span class="font-semibold text-color text-slate-700 transition-colors">SQL</span>
                                        </label>

                                        <label id="csvLabel" class="format-card p-4 border rounded-lg cursor-pointer text-center btn-animate">
                                             <input type="radio" name="exportFormat" value="csv" class="sr-only">
                                             <svg class="mx-auto h-10 w-10 mb-2 icon-color text-slate-500 transition-colors" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                             <span class="font-semibold text-color text-slate-700 transition-colors">CSV</span>
                                        </label>

                                        <label class="format-card p-4 border rounded-lg cursor-pointer text-center btn-animate">
                                            <input type="radio" name="exportFormat" value="xml" class="sr-only">
                                            <svg class="mx-auto h-10 w-10 mb-2 icon-color text-slate-500 transition-colors" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5" /></svg>
                                            <span class="font-semibold text-color text-slate-700 transition-colors">XML</span>
                                        </label>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4 pt-2">
                                    <fieldset id="exportTypeContainer">
                                         <legend class="form-label mb-2 flex items-center gap-2">
                                            <span>Export Type</span>
                                            <div id="exportTypeInfo" class="relative hidden group">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-400 cursor-pointer" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg>
                                                <span class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-64 bg-slate-800 text-white text-xs text-center rounded py-2 px-3 shadow-lg opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none z-10">CSV & XML are data-only formats. These options only apply to SQL exports.</span>
                                            </div>
                                        </legend>
                                        <div class="flex flex-col space-y-1">
                                            <label class="flex items-center text-sm p-2 rounded-md transition-colors hover:bg-slate-100"><input type="radio" name="exportType" value="both" class="h-4 w-4 mr-3 text-blue-600 focus:ring-blue-500 border-slate-300" checked> Structure & data</label>
                                            <label class="flex items-center text-sm p-2 rounded-md transition-colors hover:bg-slate-100"><input type="radio" name="exportType" value="data" class="h-4 w-4 mr-3 text-blue-600 focus:ring-blue-500 border-slate-300"> Data only</label>
                                            <label class="flex items-center text-sm p-2 rounded-md transition-colors hover:bg-slate-100"><input type="radio" name="exportType" value="structure" class="h-4 w-4 mr-3 text-blue-600 focus:ring-blue-500 border-slate-300"> Structure only</label>
                                        </div>
                                    </fieldset>
                                    
                                    <div id="csvMultiFileOptions" class="hidden">
                                        <p class="form-label mb-2 font-medium">CSV Options</p>
                                        <div class="flex flex-col space-y-1">
                                            <label class="flex items-center text-sm p-2 rounded-md transition-colors hover:bg-slate-100"><input type="radio" name="csvMulti" value="zip" class="h-4 w-4 mr-3 text-blue-600 focus:ring-blue-500 border-slate-300" checked> ZIP archive (Recommended)</label>
                                            <label class="flex items-center text-sm p-2 rounded-md transition-colors hover:bg-slate-100"><input type="radio" name="csvMulti" value="single" class="h-4 w-4 mr-3 text-blue-600 focus:ring-blue-500 border-slate-300"> Single concatenated file</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="actionsContainer" class="border-t-2 border-slate-200/60 pt-8 space-y-4">
                        <button id="exportMainBtn" class="w-full h-14 bg-blue-600 text-white font-bold rounded-lg shadow-lg shadow-blue-500/30 hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-300 transition-all btn-animate text-center disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-none disabled:hover:bg-blue-600 flex justify-center items-center text-lg gap-3">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zM12.75 6a.75.75 0 00-1.5 0v6c0 .414.336.75.75.75h4.5a.75.75 0 000-1.5h-3.75V6z" clip-rule="evenodd" /></svg>
                            </button>
                        <button id="exportSkippedBtn" class="w-full h-14 bg-slate-700 text-white font-bold rounded-lg shadow-md shadow-slate-500/20 hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-slate-400 transition-all btn-animate disabled:opacity-40 disabled:cursor-not-allowed disabled:shadow-none disabled:hover:bg-slate-700 flex justify-center items-center gap-3">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6"><path d="M5.625 3.75a2.625 2.625 0 100 5.25h12.75a2.625 2.625 0 100-5.25H5.625z" /><path fill-rule="evenodd" d="M18.375 11.25H5.625a1.125 1.125 0 00-1.125 1.125v6A1.125 1.125 0 005.625 19.5h12.75a1.125 1.125 0 001.125-1.125v-6a1.125 1.125 0 00-1.125-1.125zM9.75 12.75a.75.75 0 00-1.5 0v2.25H6a.75.75 0 000 1.5h2.25v2.25a.75.75 0 001.5 0v-2.25H12a.75.75 0 000-1.5H9.75V12.75z" clip-rule="evenodd" /></svg>
                             </button>
                    </div>
                </div>
                </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // --- UI Elements ---
    const connectionScreen = document.getElementById('connectionScreen');
    const exporterScreen = document.getElementById('exporterScreen');
    const goBackBtn = document.getElementById('goBackBtn');
    const dbForm = document.getElementById('dbForm');
    const connectBtn = document.getElementById('connectBtn');
    const connectBtnText = document.getElementById('connectBtnText');
    const connectBtnSpinner = document.getElementById('connectBtnSpinner');
    const connectionError = document.getElementById('connectionError');
    const dbHost = document.getElementById('dbHost');
    const dbName = document.getElementById('dbName');
    const dbUser = document.getElementById('dbUser');
    const dbPass = document.getElementById('dbPass');
    const thresholdSelect = document.getElementById('thresholdSelect');
    const dbNameDisplay = document.getElementById('dbNameDisplay');
    const tableListContainer = document.getElementById('tableListContainer');
    const dbTotalSizeDisplay = document.getElementById('dbTotalSizeDisplay');
    const dbExportSizeDisplay = document.getElementById('dbExportSizeDisplay');
    const dbSkippedSizeDisplay = document.getElementById('dbSkippedSizeDisplay');
    const skipAllBtn = document.getElementById('skipAllBtn');
    const includeAllBtn = document.getElementById('includeAllBtn');
    const sortBySelect = document.getElementById('sortBySelect');
    const actionsWrapper = document.getElementById('actionsWrapper');
    const actionsContainer = document.getElementById('actionsContainer');
    const exportMainBtn = document.getElementById('exportMainBtn');
    const exportSkippedBtn = document.getElementById('exportSkippedBtn');
    const progressContainer = document.getElementById('progressContainer');
    const stopBtnContainer = document.getElementById('stopBtnContainer');
    const stopBtn = document.getElementById('stopBtn');
    const resultArea = document.getElementById('resultArea');
    const statusText = document.getElementById('statusText');
    const progressBar = document.getElementById('progressBar');
    const pieChartCanvas = document.getElementById('sizePieChart');

    // --- NEW/MODIFIED Export Settings UI Elements ---
    const settingsHeader = document.getElementById('settingsHeader');
    const settingsContent = document.getElementById('settingsContent');
    const settingsChevron = document.getElementById('settingsChevron');
    const exportFormatRadios = document.querySelectorAll('input[name="exportFormat"]');
    const exportTypeContainer = document.getElementById('exportTypeContainer');
    const exportTypeRadios = document.querySelectorAll('input[name="exportType"]');
    const csvLabel = document.getElementById('csvLabel');
    const csvMultiFileOptions = document.getElementById('csvMultiFileOptions');
    const exportTypeLegendText = document.getElementById('exportTypeLegendText');
    const exportTypeInfo = document.getElementById('exportTypeInfo');

    // --- State Management ---
    let allTablesData = [];
    let skippedTableNames = new Set();
    let currentEventSource = null;
    const CONFIG_KEY = 'dbExporterConfig';
    let sizePieChart = null;
    let zipSupported = false;

    // --- Core Functions ---
    function saveConfigToLocalStorage(config) { localStorage.setItem(CONFIG_KEY, JSON.stringify(config)); }
    function loadConfigFromLocalStorage() { const savedConfig = localStorage.getItem(CONFIG_KEY); if (savedConfig) { const config = JSON.parse(savedConfig); dbHost.value = config.host || ''; dbName.value = config.name || ''; dbUser.value = config.user || ''; dbPass.value = config.pass || ''; if (config.threshold_mb) thresholdSelect.value = config.threshold_mb; } }
    loadConfigFromLocalStorage();
    function switchScreen(screenToShow, screenToHide) { screenToHide.classList.add('hidden'); screenToShow.classList.remove('hidden'); }

    function initializeLists() {
        skippedTableNames.clear();
        allTablesData.forEach(table => { if (table.is_large) { skippedTableNames.add(table.name); } });
        initializePieChart();
        renderTableLists();
        updateStats();
        handleFormatChange();
        // --- NEW: Open settings panel by default on this screen ---
        settingsContent.classList.add('open');
        settingsChevron.classList.add('rotated');
    }
    
    function renderTableLists() {
        const sortBy = sortBySelect.value;
        const sortComparator = (a, b) => { if (sortBy === 'name') { return a.name.localeCompare(b.name); } return b.size_bytes - a.size_bytes; };
        const includedTables = allTablesData.filter(t => !skippedTableNames.has(t.name)).sort(sortComparator);
        const skippedTables = allTablesData.filter(t => skippedTableNames.has(t.name)).sort(sortComparator);
        tableListContainer.innerHTML = ''; 
        const includedWrapper = document.createElement('div');
        includedWrapper.className = 'flex-grow overflow-y-auto min-h-0';
        includedWrapper.innerHTML = `<div class="px-4 py-2 sticky top-0 bg-blue-100 z-10 rounded-t-lg"><h3 class="text-base font-semibold text-blue-800">Included in Main Backup (${includedTables.length})</h3></div><div class="p-4 space-y-2 bg-white rounded-b-lg">${includedTables.map(table => createTableRowHTML(table, 'skip')).join('') || '<p class="text-slate-500 text-sm p-4 text-center">No tables in this group.</p>'}</div>`;
        tableListContainer.appendChild(includedWrapper);
        const skippedWrapper = document.createElement('div');
        skippedWrapper.className = 'flex-shrink-0 border-t-4 border-slate-200 bg-slate-50';
        skippedWrapper.innerHTML = `<div class="px-4 py-2 sticky top-0 bg-amber-100 z-10"><h3 class="text-base font-semibold text-amber-800">Skipped Large Tables (${skippedTables.length})</h3></div><div class="p-4 space-y-2 max-h-48 overflow-y-auto">${skippedTables.map(table => createTableRowHTML(table, 'include')).join('') || '<p class="text-slate-500 text-sm p-4 text-center">No tables in this group.</p>'}</div>`;
        tableListContainer.appendChild(skippedWrapper);
    }
    
    // MODIFIED: createTableRowHTML to use new icons and layout
    function createTableRowHTML(table, action) {
        const isSkipAction = action === 'skip';
        const moveButtonIcon = isSkipAction 
            ? `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM6.75 9.25a.75.75 0 000 1.5h6.5a.75.75 0 000-1.5h-6.5z" clip-rule="evenodd" /></svg>`
            : `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.25a.75.75 0 00-1.5 0v2.5h-2.5a.75.75 0 000 1.5h2.5v2.5a.75.75 0 001.5 0v-2.5h2.5a.75.75 0 000-1.5h-2.5v-2.5z" clip-rule="evenodd" /></svg>`;
        const moveButtonColor = isSkipAction ? 'text-amber-600 hover:text-amber-700' : 'text-green-600 hover:text-green-700';
        const moveButtonTitle = isSkipAction ? 'Move to Skipped' : 'Move to Included';
        const singleExportButton = `<button data-table="${table.name}" class="export-single-btn text-indigo-500 hover:text-indigo-700 p-1.5 rounded-full hover:bg-indigo-100 transition-colors btn-animate" title="Export this table">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                    </button>`;

        return `<div class="flex items-center justify-between p-2.5 bg-white hover:bg-slate-50 rounded-lg shadow-sm border border-slate-200/60 transition-colors duration-150">
                    <div class="flex items-center space-x-3 flex-grow min-w-0">
                        <button data-table-name="${table.name}" data-action="${action}" class="move-btn flex-shrink-0 rounded-full transition-colors btn-animate ${moveButtonColor}" title="${moveButtonTitle}">${moveButtonIcon}</button>
                        <span class="font-mono text-slate-800 text-sm truncate" title="${table.name}">${table.name}</span>
                    </div>
                    <div class="flex items-center space-x-4 flex-shrink-0 ml-2">
                        <span class="text-sm text-slate-500 font-medium w-24 text-right">${table.size_mb} MB</span>
                        ${singleExportButton}
                    </div>
                </div>`;
    }

    function handleMoveTable(tableName, action) { if (action === 'skip') { skippedTableNames.add(tableName); } else { skippedTableNames.delete(tableName); } renderTableLists(); updateStats(); }
    function updateStats() { let totalSizeBytes = 0; let skippedSizeBytes = 0; allTablesData.forEach(table => { totalSizeBytes += table.size_bytes; if (skippedTableNames.has(table.name)) { skippedSizeBytes += table.size_bytes; } }); const includedSizeBytes = totalSizeBytes - skippedSizeBytes; dbTotalSizeDisplay.textContent = (totalSizeBytes / 1024 / 1024).toFixed(2) + ' MB'; dbSkippedSizeDisplay.textContent = (skippedSizeBytes / 1024 / 1024).toFixed(2) + ' MB'; dbExportSizeDisplay.textContent = (includedSizeBytes / 1024 / 1024).toFixed(2) + ' MB'; exportMainBtn.querySelector('span').innerHTML = `Export Main Backup <span class="font-normal text-sm opacity-80">(${allTablesData.length - skippedTableNames.size} Tables)</span>`; exportSkippedBtn.disabled = (skippedTableNames.size === 0); exportSkippedBtn.querySelector('span').innerHTML = `Export Skipped Tables <span class="font-normal text-sm opacity-80">(${skippedTableNames.size} Tables)</span>`; if(sizePieChart) { updatePieChart(includedSizeBytes / 1024 / 1024, skippedSizeBytes / 1024 / 1024); } }
    function initializePieChart() { if (sizePieChart) { sizePieChart.destroy(); sizePieChart = null; } const ctx = pieChartCanvas.getContext('2d'); sizePieChart = new Chart(ctx, { type: 'doughnut', data: { labels: ['Included Tables', 'Skipped Tables'], datasets: [{ data: [0, 0], backgroundColor: ['rgba(59, 130, 246, 0.8)', 'rgba(245, 158, 11, 0.8)'], borderColor: ['#ffffff'], borderWidth: 2 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (context) => `${context.label}: ${context.parsed.toFixed(2)} MB` } } } } }); }
    function updatePieChart(includedSize, skippedSize) { if (!sizePieChart) return; sizePieChart.data.datasets[0].data[0] = includedSize; sizePieChart.data.datasets[0].data[1] = skippedSize; sizePieChart.update(); }
    
    function updateFormatCards() {
        const selectedValue = document.querySelector('input[name="exportFormat"]:checked').value;
        document.querySelectorAll('.format-card').forEach(card => {
            const radio = card.querySelector('input[type="radio"]');
            if (radio && radio.value === selectedValue) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        });
    }

    function handleFormatChange() {
        const selectedFormat = document.querySelector('input[name="exportFormat"]:checked').value;
        const isSql = selectedFormat === 'sql';
        const isCsv = selectedFormat === 'csv';
        csvMultiFileOptions.classList.toggle('hidden', !isCsv);
        
        exportTypeInfo.classList.toggle('hidden', isSql);
        
        exportTypeRadios.forEach(radio => {
            radio.disabled = !isSql;
            radio.parentElement.classList.toggle('text-slate-400', !isSql);
            if(!isSql) {
                if(radio.value === 'data') radio.checked = true;
            }
        });

        const csvRadioInput = document.querySelector('input[name=exportFormat][value=csv]');
        if (!zipSupported) {
            csvLabel.classList.add('text-slate-400', 'cursor-not-allowed', 'opacity-60', 'hover:!shadow-none', 'hover:!translate-y-0');
            csvLabel.title = 'Multi-table CSV export as ZIP requires the ZipArchive PHP extension, which is not enabled on this server.';
            csvRadioInput.disabled = true;
            if (csvRadioInput.checked) { document.querySelector('input[name=exportFormat][value=sql]').checked = true; handleFormatChange(); }
        } else {
            csvLabel.classList.remove('text-slate-400', 'cursor-not-allowed', 'opacity-60', 'hover:!shadow-none', 'hover:!translate-y-0');
            csvLabel.title = '';
            csvRadioInput.disabled = false;
        }

        updateFormatCards();
    }

    function renderSkeletonLoader() {
        const skeletonRow = `<div class="flex items-center justify-between p-2.5 bg-white rounded-lg shadow-sm"><div class="h-5 w-3/5 rounded shimmer-bg"></div><div class="h-5 w-1/5 rounded shimmer-bg"></div></div>`;
        const skeletonTriage = `<div class="p-4 space-y-3">${skeletonRow.repeat(8)}</div>`;
        tableListContainer.innerHTML = skeletonTriage;

        const skeletonStat = `<div class="h-8 w-32 mx-auto rounded-lg shimmer-bg"></div>`;
        dbExportSizeDisplay.innerHTML = skeletonStat;
        dbSkippedSizeDisplay.innerHTML = skeletonStat;
        dbTotalSizeDisplay.innerHTML = skeletonStat;

        if (sizePieChart) { sizePieChart.destroy(); sizePieChart = null; }
        pieChartCanvas.getContext('2d').clearRect(0, 0, pieChartCanvas.width, pieChartCanvas.height);
    }
    
    dbForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const config = { host: dbHost.value, name: dbName.value, user: dbUser.value, pass: dbPass.value, threshold_mb: thresholdSelect.value };

        dbNameDisplay.textContent = config.name;
        switchScreen(exporterScreen, connectionScreen);
        renderSkeletonLoader();
        goBackBtn.disabled = true;
        connectionError.classList.add('hidden');

        try {
            const response = await fetch('?action=analyze_tables', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(config) });
            if (!response.ok) { throw new Error(`Server error: ${response.statusText}`); }
            const result = await response.json();

            if (result.success) {
                saveConfigToLocalStorage(config);
                allTablesData = result.tables;
                zipSupported = result.zip_supported;
                initializeLists(); // This replaces skeleton with real data
            } else {
                throw new Error(result.error);
            }
        } catch (err) {
            switchScreen(connectionScreen, exporterScreen);
            connectionError.textContent = 'Connection Failed: ' + err.message;
            connectionError.classList.remove('hidden');
        } finally {
            goBackBtn.disabled = false;
        }
    });

    // Event listener for collapsible settings panel
    settingsHeader.addEventListener('click', () => {
        settingsContent.classList.toggle('open');
        settingsChevron.classList.toggle('rotated');
    });
    
    // Add a span for dynamic text content in buttons to protect icons
    exportMainBtn.appendChild(document.createElement('span'));
    exportSkippedBtn.appendChild(document.createElement('span'));

    goBackBtn.addEventListener('click', () => { switchScreen(connectionScreen, exporterScreen); allTablesData = []; });
    skipAllBtn.addEventListener('click', () => { allTablesData.forEach(t => skippedTableNames.add(t.name)); renderTableLists(); updateStats(); });
    includeAllBtn.addEventListener('click', () => { skippedTableNames.clear(); renderTableLists(); updateStats(); });
    sortBySelect.addEventListener('change', renderTableLists);
    exportFormatRadios.forEach(radio => radio.addEventListener('change', handleFormatChange));

    tableListContainer.addEventListener('click', (e) => {
        const button = e.target.closest('.move-btn, .export-single-btn');
        if (!button) return;
        if (button.classList.contains('move-btn')) {
            handleMoveTable(button.dataset.tableName, button.dataset.action);
        } else if (button.classList.contains('export-single-btn')) {
            const tableName = button.dataset.table;
            const format = document.querySelector('input[name="exportFormat"]:checked').value;
            const type = document.querySelector('input[name="exportType"]:checked').value;
            const csvMulti = document.querySelector('input[name="csvMulti"]:checked').value;
            const url = `?action=export_table&table_name=${encodeURIComponent(tableName)}&format=${format}&type=${type}&csv_multi=${csvMulti}`;
            startExport({ mode: 'single', name: tableName, url: url });
        }
    });
    exportMainBtn.addEventListener('click', () => {
        const format = document.querySelector('input[name="exportFormat"]:checked').value;
        const type = document.querySelector('input[name="exportType"]:checked').value;
        const csvMulti = document.querySelector('input[name="csvMulti"]:checked').value;
        const url = `?action=export_main&skipped_tables=${encodeURIComponent(JSON.stringify(Array.from(skippedTableNames)))}&format=${format}&type=${type}&csv_multi=${csvMulti}`;
        startExport({ mode: 'main', url: url });
    });
    exportSkippedBtn.addEventListener('click', async () => {
        const tablesToExport = Array.from(skippedTableNames);
        if (tablesToExport.length === 0) return;
        const format = document.querySelector('input[name="exportFormat"]:checked').value;
        const type = document.querySelector('input[name="exportType"]:checked').value;
        const csvMulti = document.querySelector('input[name="csvMulti"]:checked').value;
        const prepareResponse = await fetch('?action=prepare_selected_export', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ tables: tablesToExport, format: format, type: type, csv_multi: csvMulti }) });
        const prepareResult = await prepareResponse.json();
        if (prepareResult.success) {
            startExport({ mode: 'selected', url: '?action=stream_selected_export' });
        } else {
            alert('Error preparing export: ' + prepareResult.error);
        }
    });

    stopBtn.addEventListener('click', async () => { if (currentEventSource) { stopBtn.disabled = true; stopBtn.innerHTML = `<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" ...>...</svg> Cancelling...`; statusText.textContent = 'Sending cancellation signal...'; progressBar.classList.remove('bg-blue-500', 'bg-green-500', 'bg-red-500'); progressBar.classList.add('bg-slate-400'); await fetch('?action=stop_export'); currentEventSource.close(); currentEventSource = null; statusText.textContent = 'Export cancelled. Cleaning up...'; await new Promise(resolve => setTimeout(resolve, 1200)); progressContainer.classList.add('hidden'); actionsWrapper.classList.remove('hidden'); stopBtn.disabled = false; stopBtn.innerHTML = 'Cancel Export'; } });
    function startExport(exportDetails) { actionsWrapper.classList.add('hidden'); progressContainer.classList.remove('hidden'); stopBtnContainer.classList.remove('hidden'); progressContainer.scrollIntoView({ behavior: 'smooth', block: 'center' }); resultArea.innerHTML = ''; resultArea.classList.add('hidden'); statusText.textContent = `Initializing Export...`; progressBar.style.width = '0%'; progressBar.classList.remove('bg-red-500', 'bg-green-500', 'bg-slate-400'); progressBar.classList.add('bg-blue-500'); progressBar.classList.add('progress-bar-animated'); currentEventSource = new EventSource(exportDetails.url); currentEventSource.addEventListener('progress', (event) => { const data = JSON.parse(event.data); progressBar.style.width = data.progress + '%'; statusText.textContent = data.message; }); currentEventSource.addEventListener('complete', (event) => { const data = JSON.parse(event.data); progressBar.style.width = '100%'; progressBar.classList.remove('bg-blue-500'); progressBar.classList.add('bg-green-500'); progressBar.classList.remove('progress-bar-animated'); statusText.textContent = 'Completed!'; let successMessage = `<p class="text-green-800 font-medium mt-4">Export successful!</p>`; if (exportDetails.mode === 'single' && exportDetails.name) { successMessage += `<p class="text-slate-600 font-mono text-sm mt-2">Table: <strong class="text-slate-800">${exportDetails.name}</strong></p>`; } resultArea.innerHTML = `${successMessage}<a href="${data.file}" download class="mt-4 inline-flex items-center gap-2 bg-green-600 text-white font-bold py-3 px-8 rounded-lg shadow-md hover:bg-green-700 btn-animate">Download Backup</a>`; resultArea.classList.remove('hidden'); cleanupAfterExport(); }); currentEventSource.addEventListener('error', (event) => { let msg = 'An unknown server error occurred.'; if (event.data) { try { msg = JSON.parse(event.data).message; } catch (e) { if (currentEventSource?.readyState === EventSource.CLOSED) { msg = 'Export cancelled or connection lost.'; } else if (event.data) { msg = event.data; } } } statusText.textContent = `Error: ${msg}`; progressBar.style.width = '100%'; progressBar.classList.remove('bg-blue-500'); progressBar.classList.add('bg-red-500'); progressBar.classList.remove('progress-bar-animated'); cleanupAfterExport(); }); }
    function cleanupAfterExport() { if (currentEventSource) { currentEventSource.close(); currentEventSource = null; } stopBtnContainer.classList.add('hidden'); actionsWrapper.classList.remove('hidden'); }
    handleFormatChange();
});
</script>
</body>
</html>
<?php
}
?>