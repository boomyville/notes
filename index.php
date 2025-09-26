<?php
// PHP Markdown Notes App
// Single file notes application with markdown support

// Configuration
$notes_dir = 'notes';
$default_file = 'welcome.md';

// Create notes directory if it doesn't exist
if (!is_dir($notes_dir)) {
    mkdir($notes_dir, 0755, true);
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
    
    // Lists (nested support)
    $text = preg_replace('/^(\s*)[\*\-\+] (.+)$/m', '$1<li>$2</li>', $text);
    
    // Wrap consecutive list items in ul tags
    $lines = explode("\n", $text);
    $result = [];
    $in_list = false;
    $list_level = 0;
    
    foreach ($lines as $line) {
        if (preg_match('/^(\s*)<li>/', $line, $matches)) {
            $current_level = strlen($matches[1]) / 2;
            
            if (!$in_list) {
                $result[] = '<ul>';
                $in_list = true;
                $list_level = $current_level;
            } elseif ($current_level > $list_level) {
                $result[] = '<ul>';
                $list_level = $current_level;
            } elseif ($current_level < $list_level) {
                for ($i = $list_level; $i > $current_level; $i--) {
                    $result[] = '</ul>';
                }
                $list_level = $current_level;
            }
            
            $result[] = $line;
        } else {
            if ($in_list) {
                for ($i = 0; $i <= $list_level; $i++) {
                    $result[] = '</ul>';
                }
                $in_list = false;
                $list_level = 0;
            }
            $result[] = $line;
        }
    }
    
    if ($in_list) {
        for ($i = 0; $i <= $list_level; $i++) {
            $result[] = '</ul>';
        }
    }
    
    $text = implode("\n", $result);
    
    // Line breaks
    $text = nl2br($text);
    
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
            $message = 'File saved successfully!';
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
            line-height: 1.5;
            resize: vertical;
            outline: none;
        }
        
        .preview {
            padding: 20px;
            height: 500px;
            overflow-y: auto;
            line-height: 1.3;
        }
        
        .preview h1, .preview h2, .preview h3 {
            margin-top: 12px;
            margin-bottom: 6px;
            color: #2c3e50;
            line-height: 1.2;
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
            margin-bottom: 8px;
        }
        
        .preview ul {
            margin-bottom: 8px;
            padding-left: 18px;
        }
        
        .preview ul ul {
            margin-bottom: 0;
            margin-top: 0;
        }
        
        .preview li {
            margin-bottom: 0;
            line-height: 1.2;
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
            margin-bottom: 8px;
            line-height: 1.2;
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
                
                <a href="?file=<?= urlencode($file) ?>&mode=edit" class="btn btn-primary">Edit</a>
                <a href="?file=<?= urlencode($file) ?>&mode=preview" class="btn btn-secondary">Preview Only</a>
                
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
    
    <script>
        // Auto-save functionality
        let autoSaveTimer = null;
        let hasUnsavedChanges = false;
        
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
                })
                .catch(error => {
                    console.error('Auto-save failed:', error);
                });
            }
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
                // Set up auto-save on content change
                textarea.addEventListener('input', function() {
                    hasUnsavedChanges = true;
                    scheduleAutoSave();
                    
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
                    
                    // Handle nested lists
                    let lines = content.split('\n');
                    let result = [];
                    let inList = false;
                    
                    lines.forEach(line => {
                        if (line.match(/^(\s*)[\*\-\+] (.+)$/)) {
                            if (!inList) {
                                result.push('<ul>');
                                inList = true;
                            }
                            result.push(line.replace(/^(\s*)[\*\-\+] (.+)$/, '$1<li>$2</li>'));
                        } else {
                            if (inList) {
                                result.push('</ul>');
                                inList = false;
                            }
                            result.push(line);
                        }
                    });
                    
                    if (inList) {
                        result.push('</ul>');
                    }
                    
                    content = result.join('\n');
                    content = content.replace(/\n/g, '<br>');
                    
                    preview.innerHTML = content;
                });
                
                // Initial auto-save setup
                scheduleAutoSave();
            }
        } else {
            // Mobile auto-save setup
            const textarea = document.querySelector('.editor');
            if (textarea) {
                textarea.addEventListener('input', function() {
                    hasUnsavedChanges = true;
                    scheduleAutoSave();
                });
                scheduleAutoSave();
            }
        }
    </script>
</body>
</html>
