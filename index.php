<?php
// PHP Markdown Notes App
// Single file notes application with markdown support

// Configuration
$notes_dir = 'notes';
$snapshots_dir = 'snapshots';
$default_file = 'welcome.md';
$max_snapshots = 50;

// Create notes directory if it doesn't exist
if (!is_dir($notes_dir)) {
    mkdir($notes_dir, 0755, true);
}

// Create snapshots directory if it doesn't exist
if (!is_dir($snapshots_dir)) {
    mkdir($snapshots_dir, 0755, true);
}

// Table parsing function
function parseMarkdownTables($text) {
    $lines = explode("\n", $text);
    $result = [];
    $inTable = false;
    $tableBuffer = [];
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Check if line looks like a table row (contains |)
        if (preg_match('/^\s*\|.*\|\s*$/', $trimmed)) {
            $tableBuffer[] = $line;
            $inTable = true;
        } else {
            // If we were in a table and this line doesn't look like a table row
            if ($inTable && count($tableBuffer) > 0) {
                // Process the table and replace the buffer with HTML
                $tableHtml = convertTableToHtml($tableBuffer);
                // Add the table as a single block
                $result[] = implode("\n", $tableHtml);
                $tableBuffer = [];
                $inTable = false;
            }
            // Only add non-empty lines or preserve intentional empty lines
            if (!empty($trimmed) || !$inTable) {
                $result[] = $line;
            }
        }
    }
    
    // Process any remaining table at the end
    if ($inTable && count($tableBuffer) > 0) {
        $tableHtml = convertTableToHtml($tableBuffer);
        $result[] = implode("\n", $tableHtml);
    }
    
    return implode("\n", $result);
}

// Snapshot management functions
function saveSnapshot($file, $content) {
    global $snapshots_dir, $max_snapshots;
    
    $filename = pathinfo($file, PATHINFO_FILENAME);
    $file_snapshot_dir = $snapshots_dir . '/' . $filename;
    
    // Create file-specific snapshot directory
    if (!is_dir($file_snapshot_dir)) {
        mkdir($file_snapshot_dir, 0755, true);
    }
    
    // Create snapshot filename with timestamp
    $timestamp = date('Y-m-d_H-i-s');
    $snapshot_file = $file_snapshot_dir . '/' . $timestamp . '.md';
    
    // Save the snapshot
    if (file_put_contents($snapshot_file, $content) !== false) {
        // Clean up old snapshots if we exceed the limit
        cleanupOldSnapshots($file_snapshot_dir, $max_snapshots);
        return true;
    }
    return false;
}

function cleanupOldSnapshots($snapshot_dir, $max_snapshots) {
    $files = glob($snapshot_dir . '/*.md');
    if (count($files) > $max_snapshots) {
        // Sort by modification time (oldest first)
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Delete oldest files
        $files_to_delete = array_slice($files, 0, count($files) - $max_snapshots);
        foreach ($files_to_delete as $file) {
            unlink($file);
        }
    }
}

function getSnapshots($file) {
    global $snapshots_dir;
    
    $filename = pathinfo($file, PATHINFO_FILENAME);
    $file_snapshot_dir = $snapshots_dir . '/' . $filename;
    
    if (!is_dir($file_snapshot_dir)) {
        return [];
    }
    
    $files = glob($file_snapshot_dir . '/*.md');
    $snapshots = [];
    
    foreach ($files as $file_path) {
        $basename = basename($file_path, '.md');
        $timestamp = DateTime::createFromFormat('Y-m-d_H-i-s', $basename);
        if ($timestamp) {
            $snapshots[] = [
                'file' => $file_path,
                'timestamp' => $timestamp->format('Y-m-d H:i:s'),
                'basename' => $basename,
                'size' => filesize($file_path)
            ];
        }
    }
    
    // Sort by timestamp (newest first)
    usort($snapshots, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    return $snapshots;
}

function restoreSnapshot($snapshot_file, $target_file) {
    if (file_exists($snapshot_file)) {
        $content = file_get_contents($snapshot_file);
        return file_put_contents($target_file, $content) !== false;
    }
    return false;
}

function convertTableToHtml($tableLines) {
    if (count($tableLines) < 2) return $tableLines;
    
    $result = [];
    $result[] = '<table class="markdown-table">';
    
    $headerProcessed = false;
    
    for ($i = 0; $i < count($tableLines); $i++) {
        $line = trim($tableLines[$i]);
        
        // Skip separator lines (lines with only |, -, :, and spaces)
        if (preg_match('/^\|\s*[-:]+\s*(\|\s*[-:]+\s*)*\|?\s*$/', $line)) {
            continue;
        }
        
        // Remove leading and trailing |
        $line = trim($line, '| ');
        $cells = explode('|', $line);
        
        // Clean up cells
        $cells = array_map('trim', $cells);
        
        if (!$headerProcessed) {
            // Header row
            $result[] = '<thead><tr>';
            foreach ($cells as $cell) {
                $result[] = '<th>' . htmlspecialchars($cell) . '</th>';
            }
            $result[] = '</tr></thead><tbody>';
            $headerProcessed = true;
        } else {
            // Data row
            $result[] = '<tr>';
            foreach ($cells as $cell) {
                $result[] = '<td>' . htmlspecialchars($cell) . '</td>';
            }
            $result[] = '</tr>';
        }
    }
    
    $result[] = '</tbody></table>';
    return $result;
}

// Simple markdown parser function
function parseMarkdown($text) {
    // Headers
    $text = preg_replace('/^### (.*$)/im', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.*$)/im', '<h2>$1</h2>', $text);
    $text = preg_replace('/^# (.*$)/im', '<h1>$1</h1>', $text);
    
    // Bold and italic
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text);
    
    // Code blocks
    $text = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $text);
    $text = preg_replace('/`(.*?)`/', '<code>$1</code>', $text);
    
    // Links
    $text = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '<a href="$2">$1</a>', $text);
    
    // Tables
    $text = parseMarkdownTables($text);
    
    // Lists (nested support)
    $text = preg_replace('/^(\s*)[\*\-\+] (.+)$/m', '$1<li>$2</li>', $text);
    
    // Wrap consecutive list items in ul tags
    $lines = explode("\n", $text);
    $result = [];
    $in_list = false;
    $list_levels = [];
    
    foreach ($lines as $line) {
        if (preg_match('/^(\s*)<li>/', $line, $matches)) {
            $current_level = strlen($matches[1]) / 2;
            
            if (!$in_list) {
                $result[] = '<ul>';
                $in_list = true;
                $list_levels = [$current_level];
            } else {
                $last_level = end($list_levels);
                
                if ($current_level > $last_level) {
                    // Going deeper - add new ul
                    $result[] = '<ul>';
                    $list_levels[] = $current_level;
                } elseif ($current_level < $last_level) {
                    // Going up - close ul tags
                    while (!empty($list_levels) && end($list_levels) > $current_level) {
                        array_pop($list_levels);
                        $result[] = '</ul>';
                    }
                }
            }
            
            // Remove the spaces from the li tag for clean output
            $result[] = trim($line);
        } else {
            if ($in_list) {
                // Close all open ul tags
                while (!empty($list_levels)) {
                    array_pop($list_levels);
                    $result[] = '</ul>';
                }
                $in_list = false;
            }
            $result[] = $line;
        }
    }
    
    if ($in_list) {
        // Close any remaining open ul tags
        while (!empty($list_levels)) {
            array_pop($list_levels);
            $result[] = '</ul>';
        }
    }
    
    $text = implode("\n", $result);
    
    // Line breaks (but not within list structures)
    // First, protect list markup from nl2br
    $text = preg_replace('/(<\/?ul>)\s*/', '$1', $text);
    $text = preg_replace('/(<li>.*?<\/li>)\s*/', '$1', $text);
    
    // Apply nl2br but skip lines that are just list markup
    $lines = explode("\n", $text);
    $final_result = [];
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '<ul>' || $trimmed === '</ul>' || preg_match('/^<li>.*<\/li>$/', $trimmed) || 
            strpos($trimmed, '<table class="markdown-table">') === 0 || strpos($trimmed, '</table>') === 0 ||
            preg_match('/^<t[rhd]|<\/t[rhd]|<thead|<\/thead>|<tbody|<\/tbody>/', $trimmed)) {
            // Don't add br to list markup lines or table markup lines
            $final_result[] = $line;
        } else {
            // Add br to content lines, but skip completely empty lines
            if (!empty($trimmed)) {
                $final_result[] = $line . '<br>';
            } else {
                $final_result[] = $line;
            }
        }
    }
    
    $text = implode("\n", $final_result);
    
    // Clean up any double br tags and trailing brs
    $text = preg_replace('/<br>\s*<br>/', '<br>', $text);
    $text = preg_replace('/<br>\s*$/', '', $text);
    
    return $text;
}

// Handle file operations
$action = $_GET['action'] ?? '';
$file = $_GET['file'] ?? $default_file;
$message = '';

// Sanitize filename
$file = preg_replace('/[^a-zA-Z0-9._-]/', '', $file);
if (!$file) $file = $default_file;
if (!str_ends_with($file, '.md')) $file .= '.md';

$file_path = $notes_dir . '/' . $file;

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save'])) {
        $content = $_POST['content'] ?? '';
        if (file_put_contents($file_path, $content) !== false) {
            // Save snapshot for manual saves
            if (saveSnapshot($file, $content)) {
                $message = 'File saved and snapshot created!';
            } else {
                $message = 'File saved successfully!';
            }
        } else {
            $message = 'Error saving file!';
        }
    } elseif (isset($_POST['delete'])) {
        if (unlink($file_path)) {
            $message = 'File deleted successfully!';
            $file = $default_file;
            $file_path = $notes_dir . '/' . $file;
        } else {
            $message = 'Error deleting file!';
        }
    } elseif (isset($_POST['restore_snapshot'])) {
        $snapshot_file = $_POST['snapshot_file'] ?? '';
        if ($snapshot_file && file_exists($snapshot_file)) {
            if (restoreSnapshot($snapshot_file, $file_path)) {
                $message = 'Snapshot restored successfully!';
                // Reload content after restoration
                $content = file_get_contents($file_path);
            } else {
                $message = 'Error restoring snapshot!';
            }
        } else {
            $message = 'Snapshot file not found!';
        }
    } elseif (isset($_POST['delete_all_snapshots'])) {
        $filename = pathinfo($file, PATHINFO_FILENAME);
        $file_snapshot_dir = $snapshots_dir . '/' . $filename;
        
        if (is_dir($file_snapshot_dir)) {
            $snapshot_files = glob($file_snapshot_dir . '/*.md');
            $deleted_count = 0;
            
            foreach ($snapshot_files as $snapshot_file) {
                if (unlink($snapshot_file)) {
                    $deleted_count++;
                }
            }
            
            // Remove empty directory
            if (count(glob($file_snapshot_dir . '/*')) === 0) {
                rmdir($file_snapshot_dir);
            }
            
            $message = "Deleted {$deleted_count} snapshots successfully!";
        } else {
            $message = 'No snapshots found to delete!';
        }
    } elseif (isset($_POST['new_file'])) {
        $new_file = $_POST['new_filename'] ?? '';
        $new_file = preg_replace('/[^a-zA-Z0-9._-]/', '', $new_file);
        if ($new_file && !str_ends_with($new_file, '.md')) $new_file .= '.md';
        if ($new_file) {
            $new_path = $notes_dir . '/' . $new_file;
            if (!file_exists($new_path)) {
                file_put_contents($new_path, "# " . pathinfo($new_file, PATHINFO_FILENAME) . "\n\nStart writing your notes here...");
                $file = $new_file;
                $file_path = $new_path;
                $message = 'New file created!';
            } else {
                $message = 'File already exists!';
            }
        }
    }
}

// Get file content
$content = '';
if (file_exists($file_path)) {
    $content = file_get_contents($file_path);
} else {
    // Create default welcome file
    $content = "# Welcome to Markdown Notes!\n\nThis is your notes app. You can:\n\n* Write in **Markdown**\n* Create multiple files\n* Switch between preview and raw mode\n\n## Features\n\n- Full markdown support\n- File management\n- Preview mode\n- Raw markdown editing\n\nStart taking notes!";
    file_put_contents($file_path, $content);
}

// Get list of files
$files = [];
if (is_dir($notes_dir)) {
    $files = array_filter(scandir($notes_dir), function($f) {
        return str_ends_with($f, '.md') && $f !== '.' && $f !== '..';
    });
}

$view_mode = $_GET['mode'] ?? 'edit';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Markdown Notes</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .toolbar {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        
        .file-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            transition: background-color 0.2s;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        
        .main-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        @media (min-width: 768px) {
            .main-content {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        .editor-panel, .preview-panel {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .panel-header {
            background: #34495e;
            color: white;
            padding: 15px 20px;
            font-weight: bold;
        }
        
        .editor {
            width: 100%;
            height: 500px;
            border: none;
            padding: 20px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 14px;
            line-height: 1;
            resize: vertical;
            outline: none;
        }
        
        .preview {
            padding: 20px;
            height: 500px;
            overflow-y: auto;
            line-height: 1;
        }
        
        .preview h1, .preview h2, .preview h3 {
            margin-top: 12px;
            margin-bottom: 6px;
            color: #2c3e50;
            line-height: 1;
        }
        
        .preview h1:first-child, .preview h2:first-child, .preview h3:first-child {
            margin-top: 0;
        }
        
        .preview h1 {
            border-bottom: 2px solid #3498db;
            padding-bottom: 3px;
        }
        
        .preview h2 {
            border-bottom: 1px solid #bdc3c7;
            padding-bottom: 2px;
        }
        
        .preview p {
            margin-bottom: 2px;
        }
        
        .preview ul {
            margin-bottom: 2px;
            margin-top: 0;
            padding-left: 20px;
            line-height: 1.6;
        }
        
        .preview ul ul {
            margin-bottom: 0;
            margin-top: 0;
            padding-left: 20px;
        }
        
        .preview li {
            margin-bottom: 2px;
            margin-top: 0;
            line-height: 1.6;
        }
        
        .preview code {
            background: #f8f9fa;
            padding: 2px 4px;
            border-radius: 3px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 0.9em;
        }
        
        .preview pre {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
            margin-bottom: 4px;
            line-height: 1.4;
        }
        
        .preview pre code {
            background: none;
            padding: 0;
        }
        
        .preview a {
            color: #3498db;
            text-decoration: none;
        }
        
        .preview a:hover {
            text-decoration: underline;
        }
        
        /* Table styling */
        .preview .markdown-table {
            border-collapse: collapse;
            width: 100%;
            margin: 0;
            font-size: 14px;
        }
        
        .preview .markdown-table th,
        .preview .markdown-table td {
            border: 1px solid #ddd;
            padding: 8px 12px;
            text-align: left;
        }
        
        .preview .markdown-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .preview .markdown-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .preview .markdown-table tr:hover {
            background-color: #e9ecef;
        }
        
        .message {
            padding: 10px 15px;
            margin-bottom: 15px;
            border-radius: 4px;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .new-file-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .new-file-form input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .mobile-toggle {
            display: none;
        }
        
        @media (max-width: 767px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            
            .mobile-toggle {
                display: block;
                margin-bottom: 10px;
            }
            
            .editor-panel, .preview-panel {
                display: none;
            }
            
            .editor-panel.active, .preview-panel.active {
                display: block;
            }
        }
        
        .full-width {
            grid-column: 1 / -1;
        }
        
        /* Autosave notification */
        .autosave-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 14px;
            font-weight: 500;
            z-index: 1000;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
        }
        
        .autosave-notification.show {
            opacity: 1;
            transform: translateX(0);
        }
        
        /* Search functionality */
        .search-container {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        
        .search-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 200px;
            font-size: 14px;
        }
        
        .search-panel {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .search-panel .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .close-search {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            padding: 0 5px;
        }
        
        .search-results {
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        /* Modal styles */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 8px;
            width: 80%;
            max-width: 600px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            background: #34495e;
            color: white;
            padding: 15px 20px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
        }
        
        .close {
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .close:hover {
            color: #f39c12;
        }
        
        .modal-body {
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .snapshots-grid {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .snapshot-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border: 1px solid #e9ecef;
            border-radius: 5px;
            background: #f8f9fa;
        }
        
        .snapshot-info strong {
            color: #2c3e50;
            font-size: 14px;
        }
        
        .snapshot-info small {
            color: #7f8c8d;
        }
        
        .snapshot-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .search-result-item {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .search-result-item:hover {
            background-color: #f8f9fa;
        }
        
        .result-file-name {
            font-weight: bold;
            color: #3498db;
            margin-bottom: 5px;
        }
        
        .result-context {
            color: #666;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .highlight {
            background-color: #fff3cd;
            padding: 1px 2px;
            border-radius: 2px;
            font-weight: bold;
        }
        
        .no-results {
            text-align: center;
            color: #666;
            font-style: italic;
        }
        
        @media (max-width: 767px) {
            .search-input {
                width: 150px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Markdown Notes</h1>
            
            <?php if ($message): ?>
                <div class="message"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <div class="toolbar">
                <select class="file-select" onchange="location.href='?file=' + this.value + '&mode=<?= $view_mode ?>'">
                    <?php foreach ($files as $f): ?>
                        <option value="<?= htmlspecialchars($f) ?>" <?= $f === $file ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <div class="search-container">
                    <input type="text" id="search-input" placeholder="Search all notes..." class="search-input">
                    <button type="button" class="btn btn-secondary" onclick="toggleSearch()" id="search-toggle">Search</button>
                    <button type="button" class="btn btn-warning" onclick="showSnapshots()" style="margin-left: 10px;">Snapshots</button>
                </div>
                
                <a href="?file=<?= urlencode($file) ?>&mode=edit" class="btn btn-primary">Edit</a>
                <a href="?file=<?= urlencode($file) ?>&mode=preview" class="btn btn-secondary">Preview Only</a>
                
                <button type="button" class="btn btn-secondary" onclick="undo()" title="Undo (Ctrl+Z)">Undo</button>
                <button type="button" class="btn btn-secondary" onclick="redo()" title="Redo (Ctrl+Y)">Redo</button>
                
                <form method="post" style="display: inline;">
                    <button type="submit" name="delete" class="btn btn-danger" 
                            onclick="return confirm('Are you sure you want to delete this file?')">Delete</button>
                </form>
            </div>
            
            <div class="new-file-form">
                <form method="post" style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" name="new_filename" placeholder="new-file-name" required>
                    <button type="submit" name="new_file" class="btn btn-success">New File</button>
                </form>
            </div>
        </div>
        
        <!-- Search Results Panel -->
        <div id="search-panel" class="search-panel" style="display: none;">
            <div class="panel-header">
                <span>Search Results</span>
                <button class="close-search" onclick="toggleSearch()">×</button>
            </div>
            <div id="search-results" class="search-results">
                <p class="no-results">Enter a search term to find notes</p>
            </div>
        </div>
        
        <!-- Snapshots Modal -->
        <div id="snapshots-modal" class="modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>File Snapshots - <?= htmlspecialchars($file) ?></h3>
                    <span class="close" onclick="closeSnapshots()">&times;</span>
                </div>
                <div class="modal-body">
                    <?php $snapshots = getSnapshots($file); ?>
                    <?php if (!empty($snapshots)): ?>
                    <div style="text-align: right; margin-bottom: 15px;">
                        <form method="post" style="display: inline;">
                            <button type="submit" name="delete_all_snapshots" class="btn btn-sm btn-danger" 
                                    onclick="return confirm('Are you sure you want to delete ALL snapshots for this file? This cannot be undone.')">
                                Delete All Snapshots (<?= count($snapshots) ?>)
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <div id="snapshots-list">
                        <?php
                        if (empty($snapshots)) {
                            echo '<p>No snapshots available for this file yet. Save the file to create your first snapshot!</p>';
                        } else {
                            echo '<div class="snapshots-grid">';
                            foreach ($snapshots as $snapshot) {
                                echo '<div class="snapshot-item">';
                                echo '<div class="snapshot-info">';
                                echo '<strong>' . htmlspecialchars($snapshot['timestamp']) . '</strong><br>';
                                echo '<small>' . number_format($snapshot['size']) . ' bytes</small>';
                                echo '</div>';
                                echo '<div class="snapshot-actions">';
                                echo '<form method="post" style="display: inline;">';
                                echo '<input type="hidden" name="snapshot_file" value="' . htmlspecialchars($snapshot['file']) . '">';
                                echo '<button type="submit" name="restore_snapshot" class="btn btn-sm btn-success" onclick="return confirm(\'Are you sure you want to restore this snapshot? This will overwrite the current file.\')">';
                                echo 'Restore</button>';
                                echo '</form>';
                                echo '</div>';
                                echo '</div>';
                            }
                            echo '</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($view_mode === 'preview'): ?>
            <div class="preview-panel full-width">
                <div class="panel-header">Preview - <?= htmlspecialchars($file) ?></div>
                <div class="preview">
                    <?= parseMarkdown($content) ?>
                </div>
            </div>
        <?php else: ?>
            <div class="mobile-toggle">
                <button class="btn btn-primary" onclick="toggleView('editor')">Editor</button>
                <button class="btn btn-secondary" onclick="toggleView('preview')">Preview</button>
            </div>
            
            <div class="main-content">
                <div class="editor-panel active">
                    <div class="panel-header">Editor - <?= htmlspecialchars($file) ?></div>
                    <form method="post">
                        <textarea class="editor" name="content" placeholder="Start writing in Markdown..."><?= htmlspecialchars($content) ?></textarea>
                        <div style="padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #e9ecef;">
                            <button type="submit" name="save" class="btn btn-success">Save</button>
                        </div>
                    </form>
                </div>
                
                <div class="preview-panel">
                    <div class="panel-header">Preview</div>
                    <div class="preview" id="preview">
                        <?= parseMarkdown($content) ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Autosave notification -->
    <div id="autosave-notification" class="autosave-notification">
        <span id="autosave-message"></span>
    </div>
    
    <script>
        // Auto-save functionality
        let autoSaveTimer = null;
        let hasUnsavedChanges = false;
        
        // Undo/Redo system
        let undoHistory = [];
        let redoHistory = [];
        let currentContent = '';
        const MAX_HISTORY = 200;
        let isUndoRedo = false;
        
        function saveToHistory(content) {
            if (isUndoRedo) return; // Don't save history during undo/redo operations
            
            // Only save if content actually changed
            if (content !== currentContent) {
                undoHistory.push(currentContent);
                
                // Limit history size
                if (undoHistory.length > MAX_HISTORY) {
                    undoHistory.shift();
                }
                
                // Clear redo history when new content is added
                redoHistory = [];
                currentContent = content;
            }
        }
        
        function undo() {
            const textarea = document.querySelector('.editor');
            if (!textarea || undoHistory.length === 0) return;
            
            isUndoRedo = true;
            redoHistory.push(currentContent);
            currentContent = undoHistory.pop();
            textarea.value = currentContent;
            
            // Trigger preview update
            textarea.dispatchEvent(new Event('input'));
            hasUnsavedChanges = true;
            scheduleAutoSave();
            isUndoRedo = false;
        }
        
        function redo() {
            const textarea = document.querySelector('.editor');
            if (!textarea || redoHistory.length === 0) return;
            
            isUndoRedo = true;
            undoHistory.push(currentContent);
            currentContent = redoHistory.pop();
            textarea.value = currentContent;
            
            // Trigger preview update
            textarea.dispatchEvent(new Event('input'));
            hasUnsavedChanges = true;
            scheduleAutoSave();
            isUndoRedo = false;
        }
        
        // Add keyboard shortcut for search (Ctrl+F) and other existing shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'z' && !e.shiftKey) {
                e.preventDefault();
                undo();
            } else if ((e.ctrlKey && e.key === 'y') || (e.ctrlKey && e.shiftKey && e.key === 'Z')) {
                e.preventDefault();
                redo();
            } else if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                if (!searchVisible) {
                    toggleSearch();
                }
                if (searchVisible) {
                    document.getElementById('search-input').select();
                }
            }
        });
        
        // Smart auto-indentation for lists
        function handleListIndentation(textarea, e) {
            if (e.key === 'Enter') {
                const cursorPos = textarea.selectionStart;
                const textBeforeCursor = textarea.value.substring(0, cursorPos);
                const lines = textBeforeCursor.split('\n');
                const currentLine = lines[lines.length - 1];
                
                // Check if current line is a list item
                const listMatch = currentLine.match(/^(\s*)([\*\-\+])\s(.*)$/);
                if (listMatch) {
                    const indent = listMatch[1]; // The spaces before the list marker
                    const marker = listMatch[2]; // The list marker (*, -, or +)
                    const content = listMatch[3]; // The content after the marker
                    
                    // If the list item is empty (no content after the marker)
                    if (content.trim() === '') {
                        e.preventDefault();
                        
                        const restOfText = textarea.value.substring(cursorPos);
                        let newText = '';
                        
                        if (indent.length >= 2) {
                            // Not at root level - outdent by removing 2 spaces
                            const newIndent = indent.substring(2);
                            newText = textBeforeCursor.substring(0, textBeforeCursor.length - currentLine.length) + 
                                     newIndent + marker + ' ' + restOfText;
                            
                            // Position cursor after the outdented list marker
                            const newCursorPos = cursorPos - currentLine.length + newIndent.length + 2; // +2 for "* "
                            textarea.value = newText;
                            textarea.selectionStart = textarea.selectionEnd = newCursorPos;
                        } else {
                            // At root level - remove list marker completely, just add new line
                            newText = textBeforeCursor.substring(0, textBeforeCursor.length - currentLine.length) + 
                                     '\n' + restOfText;
                            
                            // Position cursor at the beginning of the new line
                            const newCursorPos = cursorPos - currentLine.length + 1; // +1 for \n
                            textarea.value = newText;
                            textarea.selectionStart = textarea.selectionEnd = newCursorPos;
                        }
                        
                        // Trigger preview update
                        textarea.dispatchEvent(new Event('input'));
                        hasUnsavedChanges = true;
                        scheduleAutoSave();
                        
                        return true;
                    } else {
                        // List item has content - continue with same indentation
                        e.preventDefault();
                        
                        const restOfText = textarea.value.substring(cursorPos);
                        
                        // Insert new line with same indentation and list marker
                        const newText = textBeforeCursor + '\n' + indent + marker + ' ' + restOfText;
                        textarea.value = newText;
                        
                        // Position cursor after the new list marker
                        const newCursorPos = cursorPos + 1 + indent.length + 2; // +1 for \n, +2 for "* "
                        textarea.selectionStart = textarea.selectionEnd = newCursorPos;
                        
                        // Trigger preview update
                        textarea.dispatchEvent(new Event('input'));
                        hasUnsavedChanges = true;
                        scheduleAutoSave();
                        
                        return true;
                    }
                }
            } else if (e.key === 'Tab') {
                const cursorPos = textarea.selectionStart;
                const textBeforeCursor = textarea.value.substring(0, cursorPos);
                const lines = textBeforeCursor.split('\n');
                const currentLine = lines[lines.length - 1];
                
                // Check if current line is a list item
                const listMatch = currentLine.match(/^(\s*)([\*\-\+])\s/);
                if (listMatch) {
                    e.preventDefault();
                    
                    if (e.shiftKey) {
                        // Shift+Tab: Decrease indentation (remove 2 spaces)
                        if (listMatch[1].length >= 2) {
                            const newIndent = listMatch[1].substring(2);
                            const lineStart = cursorPos - currentLine.length;
                            const newLine = newIndent + listMatch[2] + currentLine.substring(listMatch[1].length + 1);
                            
                            textarea.value = textarea.value.substring(0, lineStart) + newLine + textarea.value.substring(cursorPos);
                            textarea.selectionStart = textarea.selectionEnd = cursorPos - 2;
                        }
                    } else {
                        // Tab: Increase indentation (add 2 spaces)
                        const newIndent = listMatch[1] + '  ';
                        const lineStart = cursorPos - currentLine.length;
                        const newLine = newIndent + listMatch[2] + currentLine.substring(listMatch[1].length + 1);
                        
                        textarea.value = textarea.value.substring(0, lineStart) + newLine + textarea.value.substring(cursorPos);
                        textarea.selectionStart = textarea.selectionEnd = cursorPos + 2;
                    }
                    
                    // Trigger preview update
                    textarea.dispatchEvent(new Event('input'));
                    hasUnsavedChanges = true;
                    scheduleAutoSave();
                    
                    return true;
                }
            }
            return false;
        }
        
        // Search functionality
        let searchVisible = false;
        let allFiles = <?= json_encode(array_values($files)) ?>;
        
        function toggleSearch() {
            const panel = document.getElementById('search-panel');
            const toggle = document.getElementById('search-toggle');
            const input = document.getElementById('search-input');
            
            searchVisible = !searchVisible;
            
            if (searchVisible) {
                panel.style.display = 'block';
                toggle.textContent = 'Close Search';
                input.focus();
                if (input.value.trim()) {
                    performSearch(input.value);
                }
            } else {
                panel.style.display = 'none';
                toggle.textContent = 'Search';
            }
        }
        
        function performSearch(query) {
            if (query.length < 2) {
                document.getElementById('search-results').innerHTML = '<p class="no-results">Enter at least 2 characters to search</p>';
                return;
            }
            
            const resultsContainer = document.getElementById('search-results');
            resultsContainer.innerHTML = '<p class="no-results">Searching...</p>';
            
            // Search through all files
            Promise.all(allFiles.map(fileName => 
                fetch(`?file=${encodeURIComponent(fileName)}&mode=preview`)
                    .then(response => response.text())
                    .then(html => {
                        // Extract content from HTML response
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const contentElement = doc.querySelector('.preview');
                        return { fileName, content: contentElement ? contentElement.textContent : '' };
                    })
                    .catch(() => ({ fileName, content: '' }))
            )).then(files => {
                const results = [];
                const queryLower = query.toLowerCase();
                
                files.forEach(({ fileName, content }) => {
                    const lines = content.split('\n').filter(line => line.trim());
                    const matches = [];
                    
                    lines.forEach((line, index) => {
                        const lineLower = line.toLowerCase();
                        if (lineLower.includes(queryLower)) {
                            // Get context (surrounding text)
                            let context = line;
                            if (context.length > 150) {
                                const queryIndex = lineLower.indexOf(queryLower);
                                const start = Math.max(0, queryIndex - 50);
                                const end = Math.min(context.length, queryIndex + 100);
                                context = '...' + context.substring(start, end) + '...';
                            }
                            
                            matches.push({
                                lineNumber: index + 1,
                                context: highlightText(context, query)
                            });
                        }
                    });
                    
                    if (matches.length > 0) {
                        results.push({ fileName, matches });
                    }
                });
                
                displaySearchResults(results, query);
            });
        }
        
        function highlightText(text, query) {
            const regex = new RegExp(`(${escapeRegExp(query)})`, 'gi');
            return text.replace(regex, '<span class="highlight">$1</span>');
        }
        
        function escapeRegExp(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
        
        function displaySearchResults(results, query) {
            const container = document.getElementById('search-results');
            
            if (results.length === 0) {
                container.innerHTML = `<p class="no-results">No results found for "${query}"</p>`;
                return;
            }
            
            let totalMatches = results.reduce((sum, r) => sum + r.matches.length, 0);
            let html = `<p style="margin-bottom: 15px; color: #666;"><strong>${totalMatches} results</strong> in <strong>${results.length} files</strong> for "${query}"</p>`;
            
            results.forEach(({ fileName, matches }) => {
                html += `<div style="margin-bottom: 20px;">`;
                html += `<h4 style="color: #3498db; margin-bottom: 10px; cursor: pointer;" onclick="openFile('${fileName}')">${fileName} (${matches.length} matches)</h4>`;
                
                matches.slice(0, 3).forEach(match => {
                    html += `
                        <div class="search-result-item" onclick="openFile('${fileName}')">
                            <div class="result-context">${match.context}</div>
                        </div>
                    `;
                });
                
                if (matches.length > 3) {
                    html += `<p style="color: #666; font-size: 12px; margin-left: 15px;">... and ${matches.length - 3} more matches</p>`;
                }
                html += `</div>`;
            });
            
            container.innerHTML = html;
        }
        
        function openFile(fileName) {
            window.location.href = `?file=${encodeURIComponent(fileName)}&mode=edit`;
        }
        
        // Snapshots functions
        function showSnapshots() {
            const modal = document.getElementById('snapshots-modal');
            modal.style.display = 'block';
        }
        
        function closeSnapshots() {
            const modal = document.getElementById('snapshots-modal');
            modal.style.display = 'none';
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('snapshots-modal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
        
        // Search input event listeners
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search-input');
            let searchTimeout;
            
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    const query = this.value.trim();
                    
                    if (query.length === 0) {
                        document.getElementById('search-results').innerHTML = '<p class="no-results">Enter a search term to find notes</p>';
                        return;
                    }
                    
                    // Debounce search to avoid too many requests
                    searchTimeout = setTimeout(() => {
                        performSearch(query);
                    }, 300);
                });
                
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        performSearch(this.value.trim());
                    }
                    if (e.key === 'Escape') {
                        toggleSearch();
                    }
                });
            }
        });
        
        // Client-side table parsing
        function parseClientTables(content) {
            const lines = content.split('\n');
            const result = [];
            let inTable = false;
            let tableBuffer = [];
            
            lines.forEach(line => {
                const trimmed = line.trim();
                
                // Check if line looks like a table row (contains |)
                if (/^\s*\|.*\|\s*$/.test(trimmed)) {
                    tableBuffer.push(line);
                    inTable = true;
                } else {
                    // If we were in a table and this line doesn't look like a table row
                    if (inTable && tableBuffer.length > 0) {
                        // Process the table and add as single block
                        const tableHtml = convertClientTableToHtml(tableBuffer);
                        result.push(tableHtml.join('\n'));
                        tableBuffer = [];
                        inTable = false;
                    }
                    // Only add non-empty lines or preserve intentional empty lines
                    if (trimmed !== '' || !inTable) {
                        result.push(line);
                    }
                }
            });
            
            // Process any remaining table at the end
            if (inTable && tableBuffer.length > 0) {
                const tableHtml = convertClientTableToHtml(tableBuffer);
                result.push(tableHtml.join('\n'));
            }
            
            return result.join('\n');
        }
        
        function convertClientTableToHtml(tableLines) {
            if (tableLines.length < 2) return tableLines;
            
            const result = ['<table class="markdown-table">'];
            
            for (let i = 0; i < tableLines.length; i++) {
                let line = tableLines[i].trim();
                
                // Skip separator lines (lines with only |, -, :, and spaces)
                if (/^\|\s*[-:]+\s*(\|\s*[-:]+\s*)*\|?\s*$/.test(line)) {
                    continue;
                }
                
                // Remove leading and trailing |
                line = line.replace(/^\||\|$/g, '').trim();
                const cells = line.split('|').map(cell => cell.trim());
                
                if (i === 0) {
                    // Header row
                    result.push('<thead><tr>');
                    cells.forEach(cell => {
                        result.push(`<th>${cell}</th>`);
                    });
                    result.push('</tr></thead><tbody>');
                } else {
                    // Data row
                    result.push('<tr>');
                    cells.forEach(cell => {
                        result.push(`<td>${cell}</td>`);
                    });
                    result.push('</tr>');
                }
            }
            
            result.push('</tbody></table>');
            return result;
        }
        
        function autoSave() {
            const textarea = document.querySelector('.editor');
            if (textarea && hasUnsavedChanges) {
                const formData = new FormData();
                formData.append('save', '1');
                formData.append('content', textarea.value);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(() => {
                    hasUnsavedChanges = false;
                    console.log('Auto-saved');
                    showAutosaveNotification();
                })
                .catch(error => {
                    console.error('Auto-save failed:', error);
                    showAutosaveNotification(true); // Show error notification
                });
            }
        }
        
        function showAutosaveNotification(isError = false) {
            const notification = document.getElementById('autosave-notification');
            const message = document.getElementById('autosave-message');
            const now = new Date();
            const timestamp = now.toLocaleTimeString('en-US', { 
                hour12: false, 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            });
            
            if (isError) {
                message.textContent = `Autosave failed at ${timestamp}`;
                notification.style.background = '#e74c3c';
            } else {
                message.textContent = `Autosaved at ${timestamp}`;
                notification.style.background = '#27ae60';
            }
            
            // Show notification
            notification.classList.add('show');
            
            // Hide after 2 seconds
            setTimeout(() => {
                notification.classList.remove('show');
            }, 2000);
        }
        
        function scheduleAutoSave() {
            if (autoSaveTimer) {
                clearTimeout(autoSaveTimer);
            }
            autoSaveTimer = setTimeout(autoSave, 30000); // 30 seconds
        }
        
        function toggleView(view) {
            const editor = document.querySelector('.editor-panel');
            const preview = document.querySelector('.preview-panel');
            
            if (view === 'editor') {
                editor.classList.add('active');
                preview.classList.remove('active');
            } else {
                preview.classList.add('active');
                editor.classList.remove('active');
            }
        }
        
        // Auto-update preview on desktop
        if (window.innerWidth > 767) {
            const textarea = document.querySelector('.editor');
            const preview = document.querySelector('#preview');
            
            if (textarea && preview) {
                // Add smart list indentation
                textarea.addEventListener('keydown', function(e) {
                    handleListIndentation(this, e);
                });
                
                // Set up auto-save on content change
                textarea.addEventListener('input', function() {
                    hasUnsavedChanges = true;
                    scheduleAutoSave();
                    
                    // Save to undo history
                    saveToHistory(this.value);
                    
                    // Simple client-side markdown preview update
                    let content = this.value;
                    
                    // Basic markdown parsing (simplified)
                    content = content.replace(/^### (.*$)/gim, '<h3>$1</h3>');
                    content = content.replace(/^## (.*$)/gim, '<h2>$1</h2>');
                    content = content.replace(/^# (.*$)/gim, '<h1>$1</h1>');
                    content = content.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                    content = content.replace(/\*(.*?)\*/g, '<em>$1</em>');
                    content = content.replace(/`(.*?)`/g, '<code>$1</code>');
                    content = content.replace(/\[([^\]]+)\]\(([^\)]+)\)/g, '<a href="$2">$1</a>');
                    
                    // Handle tables
                    content = parseClientTables(content);
                    
                    // Handle nested lists
                    let lines = content.split('\n');
                    let result = [];
                    let inList = false;
                    let listLevels = [];
                    
                    lines.forEach(line => {
                        const listMatch = line.match(/^(\s*)[\*\-\+] (.+)$/);
                        if (listMatch) {
                            const currentLevel = Math.floor(listMatch[1].length / 2);
                            
                            if (!inList) {
                                result.push('<ul>');
                                inList = true;
                                listLevels = [currentLevel];
                            } else {
                                const lastLevel = listLevels[listLevels.length - 1];
                                
                                if (currentLevel > lastLevel) {
                                    // Going deeper - add new ul
                                    result.push('<ul>');
                                    listLevels.push(currentLevel);
                                } else if (currentLevel < lastLevel) {
                                    // Going up - close ul tags
                                    while (listLevels.length > 0 && listLevels[listLevels.length - 1] > currentLevel) {
                                        listLevels.pop();
                                        result.push('</ul>');
                                    }
                                }
                            }
                            
                            result.push('<li>' + listMatch[2] + '</li>');
                        } else {
                            if (inList) {
                                // Close all open ul tags
                                while (listLevels.length > 0) {
                                    listLevels.pop();
                                    result.push('</ul>');
                                }
                                inList = false;
                            }
                            result.push(line);
                        }
                    });
                    
                    if (inList) {
                        // Close any remaining open ul tags
                        while (listLevels.length > 0) {
                            listLevels.pop();
                            result.push('</ul>');
                        }
                    }
                    
                    content = result.join('\n');
                    
                    // Handle line breaks properly (avoid adding br to list markup and table markup)
                    let finalLines = content.split('\n');
                    let processedLines = [];
                    
                    finalLines.forEach(line => {
                        const trimmed = line.trim();
                        if (trimmed === '<ul>' || trimmed === '</ul>' || trimmed.match(/^<li>.*<\/li>$/) ||
                            trimmed.startsWith('<table class="markdown-table">') || trimmed === '</table>' ||
                            trimmed.match(/^<t[rhd]|<\/t[rhd]|<thead|<\/thead>|<tbody|<\/tbody>/)) {
                            // Don't add br to list markup lines or table markup lines
                            processedLines.push(line);
                        } else if (trimmed !== '') {
                            // Add br to non-empty content lines
                            processedLines.push(line + '<br>');
                        } else {
                            // Keep empty lines as is
                            processedLines.push(line);
                        }
                    });
                    
                    content = processedLines.join('\n');
                    
                    preview.innerHTML = content;
                });
                
                // Initialize undo history with current content
                currentContent = textarea.value;
                undoHistory = [];
                redoHistory = [];
                
                // Initial auto-save setup
                scheduleAutoSave();
            }
        } else {
            // Mobile auto-save setup
            const textarea = document.querySelector('.editor');
            if (textarea) {
                // Add smart list indentation for mobile too
                textarea.addEventListener('keydown', function(e) {
                    handleListIndentation(this, e);
                });
                
                textarea.addEventListener('input', function() {
                    hasUnsavedChanges = true;
                    scheduleAutoSave();
                    
                    // Save to undo history
                    saveToHistory(this.value);
                });
                
                // Initialize undo history with current content
                currentContent = textarea.value;
                undoHistory = [];
                redoHistory = [];
                
                scheduleAutoSave();
            }
        }
    </script>
</body>
</html>
