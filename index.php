<?php
// index.php — FileLight: modern file browser with admin descriptions, grid/list views, dark mode

session_start();

/**
 * Load configuration and auto-upgrade plain password to password_hash().
 */
$configPath = __DIR__ . '/filelight.config.php';
$config = is_file($configPath) ? require $configPath : ['admin_password_hash' => 'admin123'];

$stored = $config['admin_password_hash'] ?? '';
$adminPasswordHash = null;
$usePlainPassword = false;
$plainPassword = null;

function filelight_is_bcrypt_hash($value) {
    return is_string($value) && strlen($value) >= 60 && strpos($value, '$2') === 0;
}

/**
 * If admin_password_hash is:
 * - empty      → create a hash for "admin123" and write back.
 * - a bcrypt   → use as-is.
 * - anything else → treat as plain password, hash it, and try to rewrite config.
 *   If rewrite fails, fall back to plain-text comparison.
 */
if ($stored === '') {
    $adminPasswordHash = password_hash('admin123', PASSWORD_DEFAULT);
    $config['admin_password_hash'] = $adminPasswordHash;
    @file_put_contents(
        $configPath,
        "<?php\nreturn " . var_export($config, true) . ";\n",
        LOCK_EX
    );
} elseif (filelight_is_bcrypt_hash($stored)) {
    $adminPasswordHash = $stored;
} else {
    // Treat as plain text password
    $plainPassword = $stored;
    $newHash = password_hash($stored, PASSWORD_DEFAULT);
    $config['admin_password_hash'] = $newHash;
    if (@file_put_contents(
        $configPath,
        "<?php\nreturn " . var_export($config, true) . ";\n",
        LOCK_EX
    ) !== false) {
        $adminPasswordHash = $newHash;
        $usePlainPassword = false;
    } else {
        // Could not rewrite config; fall back to plain password check
        $usePlainPassword = true;
    }
}

/**
 * Handle login / logout
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'login') {
        $pwd = $_POST['password'] ?? '';
        $ok = false;

        if ($adminPasswordHash) {
            $ok = password_verify($pwd, $adminPasswordHash);
        } elseif ($usePlainPassword && $plainPassword !== null) {
            $ok = hash_equals($plainPassword, $pwd);
        }

        if ($ok) {
            $_SESSION['is_admin'] = true;
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? $_SERVER['PHP_SELF']));
            exit;
        } else {
            $login_error = 'Invalid password';
        }
    } elseif ($_POST['action'] === 'logout') {
        unset($_SESSION['is_admin']);
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? $_SERVER['PHP_SELF']));
        exit;
    }
}

$is_admin = !empty($_SESSION['is_admin']);

/**
 * Base directory and current directory
 */
$baseDir = realpath(__DIR__);
$rel     = isset($_GET['dir']) ? trim($_GET['dir'], "/\\") : '';
$dir     = realpath($baseDir . ($rel ? DIRECTORY_SEPARATOR . $rel : ''));

// Security: prevent traversal outside base
if ($dir === false || strpos($dir, $baseDir) !== 0) {
    header("HTTP/1.1 403 Forbidden");
    exit("Forbidden");
}

/**
 * Handle AJAX description update (admin only)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_description') {
    header('Content-Type: application/json');

    if (!$is_admin) {
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }

    $filename   = $_POST['filename'] ?? '';
    $description = $_POST['description'] ?? '';
    $currentDir  = $_POST['dir'] ?? '';

    // Security check for target directory
    $targetDir = realpath($baseDir . ($currentDir ? DIRECTORY_SEPARATOR . $currentDir : ''));
    if ($targetDir === false || strpos($targetDir, $baseDir) !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid directory']);
        exit;
    }

    $descFile = "$targetDir/DESCRIPT.ION";
    $descriptions = [];

    if (is_readable($descFile)) {
        foreach (file($descFile, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $parts = preg_split('/\s{2,}|\t/', $line, 2);
            if (count($parts) === 2) {
                $descriptions[trim($parts[0])] = trim($parts[1]);
            }
        }
    }

    // Update description map
    if ($description === '') {
        unset($descriptions[$filename]);
    } else {
        $descriptions[$filename] = $description;
    }

    // Write back DESCRIPT.ION
    $content = "";
    foreach ($descriptions as $file => $desc) {
        if ($desc !== '') {
            // Use tab for long filenames
            if (strlen($file) > 28) {
                $content .= $file . "\t" . $desc . "\n";
            } else {
                $content .= str_pad($file, 30) . $desc . "\n";
            }
        }
    }

    if (file_put_contents($descFile, $content) !== false) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not write DESCRIPT.ION']);
    }
    exit;
}

/**
 * Files/dirs to hide in listings
 */
$hide = ['index.php', '.htaccess', 'DESCRIPT.ION', 'desc.js', '_h5ai'];

/**
 * Load metadata from DESCRIPT.ION (local first, then root)
 */
$localMeta = "$dir/DESCRIPT.ION";
$rootMeta  = "$baseDir/DESCRIPT.ION";
$metaFile  = is_readable($localMeta) ? $localMeta
           : (is_readable($rootMeta) ? $rootMeta : null);
$meta = [];
if ($metaFile) {
    foreach (file($metaFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        // Try extension-aware parsing
        if (preg_match(
            '/^(.+\.(jpg|jpeg|png|gif|bmp|webp|pdf|doc|docx|txt|mp3|mp4|avi|zip|rar|php|js|css|html|swf|exe|com|bat|cmd|svg|ico|flac|wav|mov|mkv|7z|tar|gz|xml|json|sql|log|md))\s+(.+)$/i',
            $line,
            $matches
        )) {
            $meta[trim($matches[1])] = trim($matches[3]);
        } else {
            $parts = preg_split('/\s{2,}|\t/', $line, 2);
            if (count($parts) === 2) {
                $meta[trim($parts[0])] = trim($parts[1]);
            }
        }
    }
}

/**
 * Optional types.json (not strictly required, but we keep compatibility)
 */
$typesPath = "$baseDir/types.json";
$types = [];
if (is_readable($typesPath)) {
    $content = file_get_contents($typesPath);
    $json    = preg_replace('#^/\*.*?\*/#s', '', $content);
    $types   = json_decode($json, true) ?: [];
}

/**
 * Scan & filter
 */
$all   = array_diff(scandir($dir), ['.', '..']);
$items = array_filter($all, fn($n) => !in_array($n, $hide, true));

/**
 * Search
 */
$q = $_GET['q'] ?? '';
if ($q !== '') {
    $items = array_filter($items, fn($n) =>
        stripos($n, $q) !== false ||
        stripos($meta[$n] ?? '', $q) !== false
    );
}

/**
 * Sort parameters
 */
$validSorts = ['name','date','size','type','description'];
$sort  = in_array($_GET['sort'] ?? 'name', $validSorts) ? $_GET['sort'] : 'name';
$order = ($_GET['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

/**
 * Helper: map extension to human type
 */
function filelight_getFileType($name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $typeMap = [
        'pdf' => 'PDF',
        'doc' => 'Document', 'docx' => 'Document',
        'xls' => 'Spreadsheet', 'xlsx' => 'Spreadsheet',
        'ppt' => 'Presentation', 'pptx' => 'Presentation',
        'txt' => 'Text', 'md' => 'Text',
        'jpg' => 'Image', 'jpeg' => 'Image', 'png' => 'Image', 'gif' => 'Image', 'webp' => 'Image',
        'mp3' => 'Music', 'wav' => 'Music', 'flac' => 'Music', 'aac' => 'Music',
        'mp4' => 'Video', 'avi' => 'Video', 'mkv' => 'Video', 'mov' => 'Video',
        'zip' => 'Archive', 'rar' => 'Archive', '7z' => 'Archive', 'tar' => 'Archive',
        'js' => 'Code', 'css' => 'Code', 'html' => 'Code', 'php' => 'Code', 'py' => 'Code'
    ];
    return $typeMap[$ext] ?? ($ext ? strtoupper($ext) : '');
}

/**
 * Sort: directories first, then by chosen column
 */
usort($items, function($a, $b) use ($dir, $sort, $order, $meta) {
    $pathA = "$dir/$a";
    $pathB = "$dir/$b";
    $isDirA = is_dir($pathA);
    $isDirB = is_dir($pathB);

    if ($isDirA && !$isDirB) return -1;
    if (!$isDirA && $isDirB) return 1;

    switch ($sort) {
        case 'date':
            $cmp = filemtime($pathA) <=> filemtime($pathB);
            break;
        case 'size':
            $szA = $isDirA ? 0 : filesize($pathA);
            $szB = $isDirB ? 0 : filesize($pathB);
            $cmp = $szA <=> $szB;
            break;
        case 'type':
            $typeA = $isDirA ? 'Dir' : filelight_getFileType($a);
            $typeB = $isDirB ? 'Dir' : filelight_getFileType($b);
            $cmp = strcasecmp($typeA, $typeB);
            break;
        case 'description':
            $descA = $meta[$a] ?? '';
            $descB = $meta[$b] ?? '';
            $cmp = strcasecmp($descA, $descB);
            break;
        default:
            $cmp = strnatcasecmp($a, $b);
    }
    return $order === 'asc' ? $cmp : -$cmp;
});

/**
 * Format file size
 */
function filelight_formatSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1073741824, 1) . ' GB';
}

/**
 * Icon + color per file type
 */
function filelight_getFileIcon($name, $isDir) {
    if ($isDir) {
        return ['icon' => 'folder', 'color' => '#5DADE2'];
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $iconMap = [
        'pdf'  => ['icon' => 'file-earmark-pdf',   'color' => '#E74C3C'],
        'doc'  => ['icon' => 'file-earmark-word',  'color' => '#2E86C1'],
        'docx' => ['icon' => 'file-earmark-word',  'color' => '#2E86C1'],
        'xls'  => ['icon' => 'file-earmark-excel', 'color' => '#27AE60'],
        'xlsx' => ['icon' => 'file-earmark-excel', 'color' => '#27AE60'],
        'ppt'  => ['icon' => 'file-earmark-ppt',   'color' => '#E67E22'],
        'pptx' => ['icon' => 'file-earmark-ppt',   'color' => '#E67E22'],
        'txt'  => ['icon' => 'file-earmark-text',  'color' => '#95A5A6'],
        'md'   => ['icon' => 'file-earmark-text',  'color' => '#95A5A6'],
        'jpg'  => ['icon' => 'file-earmark-image', 'color' => '#E74C3C'],
        'jpeg' => ['icon' => 'file-earmark-image', 'color' => '#E74C3C'],
        'png'  => ['icon' => 'file-earmark-image', 'color' => '#E74C3C'],
        'gif'  => ['icon' => 'file-earmark-image', 'color' => '#E74C3C'],
        'webp' => ['icon' => 'file-earmark-image', 'color' => '#E74C3C'],
        'mp3'  => ['icon' => 'file-earmark-music', 'color' => '#9B59B6'],
        'wav'  => ['icon' => 'file-earmark-music', 'color' => '#9B59B6'],
        'flac' => ['icon' => 'file-earmark-music', 'color' => '#9B59B6'],
        'mp4'  => ['icon' => 'file-earmark-play',  'color' => '#F39C12'],
        'avi'  => ['icon' => 'file-earmark-play',  'color' => '#F39C12'],
        'mkv'  => ['icon' => 'file-earmark-play',  'color' => '#F39C12'],
        'mov'  => ['icon' => 'file-earmark-play',  'color' => '#F39C12'],
        'zip'  => ['icon' => 'file-earmark-zip',   'color' => '#34495E'],
        'rar'  => ['icon' => 'file-earmark-zip',   'color' => '#34495E'],
        '7z'   => ['icon' => 'file-earmark-zip',   'color' => '#34495E'],
        'js'   => ['icon' => 'file-earmark-code',  'color' => '#F1C40F'],
        'css'  => ['icon' => 'file-earmark-code',  'color' => '#3498DB'],
        'html' => ['icon' => 'file-earmark-code',  'color' => '#E67E22'],
        'php'  => ['icon' => 'file-earmark-code',  'color' => '#8E44AD'],
        'py'   => ['icon' => 'file-earmark-code',  'color' => '#3498DB'],
    ];

    return $iconMap[$ext] ?? ['icon' => 'file-earmark', 'color' => '#7F8C8D'];
}

/**
 * Breadcrumb builder
 */
function filelight_buildBreadcrumb($rel) {
    if (!$rel) return [];
    $parts = explode('/', $rel);
    $breadcrumb = [];
    $path = '';
    foreach ($parts as $part) {
        $path .= ($path ? '/' : '') . $part;
        $breadcrumb[] = ['name' => $part, 'path' => $path];
    }
    return $breadcrumb;
}

$breadcrumb = filelight_buildBreadcrumb($rel);

/**
 * Build path helper for file links (relative)
 */
function filelight_buildPath($rel, $name) {
    $parts = $rel !== '' ? array_merge(explode('/', $rel), [$name]) : [$name];
    return implode('/', array_map('rawurlencode', $parts));
}

/**
 * Sort link helper
 */
function filelight_sortLink($column, $currentSort, $currentOrder, $rel, $q) {
    $newOrder = ($currentSort === $column && $currentOrder === 'asc') ? 'desc' : 'asc';
    $params = [];
    if ($rel !== '') $params[] = 'dir=' . rawurlencode($rel);
    if ($q   !== '') $params[] = 'q=' . rawurlencode($q);
    $params[] = "sort=$column";
    $params[] = "order=$newOrder";
    return '?' . implode('&', $params);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $rel ? htmlspecialchars(basename($rel)) . ' - ' : '' ?>FileLight</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="filelight.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="breadcrumb">
                <i class="bi bi-lightbulb-fill" id="brandIcon" style="font-size: 24px; margin-right: 8px; color: var(--accent-blue);"></i>
                <a href="?" style="font-weight: 500;">FileLight</a>
                <span class="separator">›</span>
                <a href="?">Files</a>
                <?php foreach ($breadcrumb as $crumb): ?>
                    <span class="separator">›</span>
                    <a href="?dir=<?= rawurlencode($crumb['path']) ?>"><?= htmlspecialchars($crumb['name']) ?></a>
                <?php endforeach; ?>
                <?php if ($is_admin): ?>
                    <span class="admin-indicator">
                        <i class="bi bi-shield-check"></i>
                        Admin Mode
                    </span>
                <?php endif; ?>
            </div>

            <div class="actions">
                <form class="search-box" method="get">
                    <?php if ($rel !== ''): ?>
                        <input type="hidden" name="dir" value="<?= htmlspecialchars($rel) ?>">
                    <?php endif; ?>
                    <input type="text" class="search-input" name="q" placeholder="Search..." value="<?= htmlspecialchars($q) ?>">
                    <i class="bi bi-search search-icon"></i>
                </form>
                <div class="view-toggle">
                    <button id="listViewBtn" class="active" title="List view">
                        <i class="bi bi-list"></i>
                    </button>
                    <button id="gridViewBtn" title="Grid view">
                        <i class="bi bi-grid-3x2-gap"></i>
                    </button>
                </div>
                <button class="theme-toggle" id="themeToggle" title="Toggle dark mode">
                    <i class="bi bi-moon-fill"></i>
                </button>
                <?php if ($is_admin): ?>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="admin-button logged-in">
                            <i class="bi bi-shield-slash"></i>
                            Logout
                        </button>
                    </form>
                <?php else: ?>
                    <button class="admin-button" id="adminButton">
                        <i class="bi bi-shield-lock"></i>
                        Admin
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="file-list">
            <div class="file-header">
                <div>
                    <a href="<?= filelight_sortLink('name', $sort, $order, $rel, $q) ?>">
                        Filename
                        <?php if ($sort === 'name'): ?>
                            <i class="bi bi-chevron-<?= $order === 'asc' ? 'up' : 'down' ?> sort-icon"></i>
                        <?php endif; ?>
                    </a>
                </div>
                <div>
                    <a href="<?= filelight_sortLink('description', $sort, $order, $rel, $q) ?>">
                        Description
                        <?php if ($sort === 'description'): ?>
                            <i class="bi bi-chevron-<?= $order === 'asc' ? 'up' : 'down' ?> sort-icon"></i>
                        <?php endif; ?>
                    </a>
                </div>
                <div style="text-align: center;">
                    <a href="<?= filelight_sortLink('type', $sort, $order, $rel, $q) ?>" style="justify-content: center;">
                        Type
                        <?php if ($sort === 'type'): ?>
                            <i class="bi bi-chevron-<?= $order === 'asc' ? 'up' : 'down' ?> sort-icon"></i>
                        <?php endif; ?>
                    </a>
                </div>
                <div style="text-align: right;">
                    <a href="<?= filelight_sortLink('size', $sort, $order, $rel, $q) ?>" style="justify-content: flex-end;">
                        Size
                        <?php if ($sort === 'size'): ?>
                            <i class="bi bi-chevron-<?= $order === 'asc' ? 'up' : 'down' ?> sort-icon"></i>
                        <?php endif; ?>
                    </a>
                </div>
            </div>

            <?php if (empty($items)): ?>
                <div class="empty-state">
                    <i class="bi bi-folder2-open"></i>
                    <p>No files found</p>
                </div>
            <?php else: ?>
                <?php foreach ($items as $name):
                    $fullPath = "$dir/$name";
                    $isDir    = is_dir($fullPath);
                    $fileInfo = filelight_getFileIcon($name, $isDir);
                    $fileType = $isDir ? 'Dir' : filelight_getFileType($name);
                    $fileSize = $isDir ? '-' : filelight_formatSize(filesize($fullPath));

                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg']);

                    if ($isDir) {
                        $href = '?dir=' . rawurlencode($rel ? "$rel/$name" : $name);
                    } else {
                        $href = filelight_buildPath($rel, $name);
                    }
                ?>
                    <div class="file-item <?= $isImage ? 'is-image' : '' ?>" <?= $isImage ? 'data-preview-url="' . htmlspecialchars($href, ENT_QUOTES) . '"' : '' ?>>
                        <div class="file-name">
                            <div class="file-icon" style="color: <?= $fileInfo['color'] ?>">
                                <i class="bi bi-<?= $fileInfo['icon'] ?>"></i>
                            </div>
                            <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"><?= htmlspecialchars($name) ?></a>
                        </div>
                        <div class="file-description" data-filename="<?= htmlspecialchars($name) ?>">
                            <?php if ($is_admin): ?>
                                <span class="description-text <?= empty($meta[$name]) ? 'empty' : '' ?>">
                                    <?= htmlspecialchars($meta[$name] ?? 'Click to add description...') ?>
                                </span>
                                <div class="description-edit">
                                    <input type="text" class="description-input" value="<?= htmlspecialchars($meta[$name] ?? '') ?>">
                                </div>
                            <?php else: ?>
                                <span class="description-readonly">
                                    <?= htmlspecialchars($meta[$name] ?? '') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="file-type"><?= htmlspecialchars($fileType) ?></div>
                        <div class="file-size"><?= htmlspecialchars($fileSize) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Grid View -->
        <div class="file-grid">
            <?php if (empty($items)): ?>
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <i class="bi bi-folder2-open"></i>
                    <p>No files found</p>
                </div>
            <?php else: ?>
                <?php foreach ($items as $name):
                    $fullPath = "$dir/$name";
                    $isDir    = is_dir($fullPath);
                    $fileInfo = filelight_getFileIcon($name, $isDir);
                    $fileType = $isDir ? 'Dir' : filelight_getFileType($name);
                    $fileSize = $isDir ? '-' : filelight_formatSize(filesize($fullPath));

                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']);

                    if ($isDir) {
                        $href = '?dir=' . rawurlencode($rel ? "$rel/$name" : $name);
                    } else {
                        $href = filelight_buildPath($rel, $name);
                    }
                ?>
                    <div class="grid-item">
                        <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>" class="grid-item-preview">
                            <?php if ($isImage && !$isDir): ?>
                                <img src="<?= htmlspecialchars($href, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($name) ?>" loading="lazy">
                            <?php elseif ($isDir): ?>
                                <i class="bi bi-folder-fill folder-icon" style="color: <?= $fileInfo['color'] ?>"></i>
                            <?php else: ?>
                                <i class="bi bi-<?= $fileInfo['icon'] ?> file-icon" style="color: <?= $fileInfo['color'] ?>"></i>
                            <?php endif; ?>
                        </a>
                        <div class="grid-item-info">
                            <div class="grid-item-name">
                                <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"><?= htmlspecialchars($name) ?></a>
                            </div>
                            <div class="grid-item-meta">
                                <span><?= htmlspecialchars($fileType) ?></span>
                                <span><?= htmlspecialchars($fileSize) ?></span>
                            </div>
                            <div class="grid-item-description" data-filename="<?= htmlspecialchars($name) ?>">
                                <?php if ($is_admin): ?>
                                    <span class="description-text <?= empty($meta[$name]) ? 'empty' : '' ?>">
                                        <?= htmlspecialchars($meta[$name] ?? 'Add description...') ?>
                                    </span>
                                    <div class="description-edit">
                                        <input type="text" class="description-input" value="<?= htmlspecialchars($meta[$name] ?? '') ?>">
                                    </div>
                                <?php else: ?>
                                    <span class="description-readonly">
                                        <?= htmlspecialchars($meta[$name] ?? '') ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="tagline">
                <i class="bi bi-lightbulb" id="footerIcon"></i>
                FileLight - Bring your files to light
            </div>
        </div>
    </div>

    <!-- Image Preview -->
    <div class="image-preview" id="imagePreview">
        <img src="" alt="Preview">
        <div class="preview-name"></div>
    </div>

    <!-- Login Modal -->
    <div class="login-modal" id="loginModal">
        <form class="login-form" method="post">
            <h2>Admin Login</h2>
            <?php if (isset($login_error)): ?>
                <div class="login-error"><?= htmlspecialchars($login_error) ?></div>
            <?php endif; ?>
            <input type="hidden" name="action" value="login">
            <input type="password" name="password" placeholder="Enter password" autofocus>
            <div class="login-buttons">
                <button type="button" class="login-cancel" onclick="document.getElementById('loginModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="login-submit">Login</button>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Admin button handler
            const adminButton = document.getElementById('adminButton');
            if (adminButton) {
                adminButton.addEventListener('click', function() {
                    document.getElementById('loginModal').classList.add('show');
                    const pwdInput = document.querySelector('.login-form input[type="password"]');
                    if (pwdInput) pwdInput.focus();
                });
            }

            // Close modal on background click
            document.getElementById('loginModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });

            // Theme handling
            const themeToggle = document.getElementById('themeToggle');
            const htmlElement = document.documentElement;
            const icon = themeToggle.querySelector('i');
            const brandIcon = document.getElementById('brandIcon');
            const footerIcon = document.getElementById('footerIcon');

            const savedTheme = localStorage.getItem('theme') || 'light';
            htmlElement.setAttribute('data-theme', savedTheme);
            updateThemeIcon(savedTheme);

            themeToggle.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-theme') || 'light';
                const newTheme = currentTheme === 'light' ? 'dark' : 'light';
                htmlElement.setAttribute('data-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                updateThemeIcon(newTheme);
            });

            function updateThemeIcon(theme) {
                if (theme === 'dark') {
                    icon.classList.remove('bi-moon-fill');
                    icon.classList.add('bi-sun-fill');
                    brandIcon.classList.remove('bi-lightbulb-fill');
                    brandIcon.classList.add('bi-moon-stars-fill');
                    footerIcon.classList.remove('bi-lightbulb');
                    footerIcon.classList.add('bi-moon-stars');
                } else {
                    icon.classList.remove('bi-sun-fill');
                    icon.classList.add('bi-moon-fill');
                    brandIcon.classList.remove('bi-moon-stars-fill');
                    brandIcon.classList.add('bi-lightbulb-fill');
                    footerIcon.classList.remove('bi-moon-stars');
                    footerIcon.classList.add('bi-lightbulb');
                }
            }

            // View toggle
            const listViewBtn = document.getElementById('listViewBtn');
            const gridViewBtn = document.getElementById('gridViewBtn');
            const body = document.body;

            const savedView = localStorage.getItem('fileViewMode') || 'list';
            if (savedView === 'grid') {
                body.classList.add('grid-view');
                gridViewBtn.classList.add('active');
                listViewBtn.classList.remove('active');
            }

            listViewBtn.addEventListener('click', function() {
                body.classList.remove('grid-view');
                this.classList.add('active');
                gridViewBtn.classList.remove('active');
                localStorage.setItem('fileViewMode', 'list');
            });

            gridViewBtn.addEventListener('click', function() {
                body.classList.add('grid-view');
                this.classList.add('active');
                listViewBtn.classList.remove('active');
                localStorage.setItem('fileViewMode', 'grid');
                if (<?= $is_admin ? 'true' : 'false' ?>) {
                    initializeGridDescriptions();
                }
            });

            // Image preview
            const imagePreview = document.getElementById('imagePreview');
            const previewImg = imagePreview.querySelector('img');
            const previewName = imagePreview.querySelector('.preview-name');
            let previewTimer;

            document.querySelectorAll('.file-item.is-image').forEach(function(item) {
                const fileName = item.querySelector('.file-name a');
                const previewUrl = item.dataset.previewUrl;

                fileName.addEventListener('mouseenter', function() {
                    clearTimeout(previewTimer);
                    previewTimer = setTimeout(() => {
                        previewImg.src = previewUrl;
                        previewName.textContent = fileName.textContent;

                        const rect = fileName.getBoundingClientRect();
                        const previewWidth = 316;
                        const previewHeight = 350;

                        let left = rect.right + 10;
                        let top = rect.top;

                        if (left + previewWidth > window.innerWidth) {
                            left = rect.left - previewWidth - 10;
                        }

                        if (top + previewHeight > window.innerHeight) {
                            top = window.innerHeight - previewHeight - 10;
                        }

                        imagePreview.style.left = left + 'px';
                        imagePreview.style.top = top + 'px';
                        imagePreview.style.display = 'block';
                    }, 500);
                });

                fileName.addEventListener('mouseleave', function() {
                    clearTimeout(previewTimer);
                    previewTimer = setTimeout(() => {
                        imagePreview.style.display = 'none';
                        previewImg.src = '';
                    }, 100);
                });
            });

            imagePreview.addEventListener('mouseenter', function() {
                clearTimeout(previewTimer);
            });
            imagePreview.addEventListener('mouseleave', function() {
                imagePreview.style.display = 'none';
                previewImg.src = '';
            });

            <?php if ($is_admin): ?>
            // Description editing (admin)
            function initializeDescriptions(selector) {
                document.querySelectorAll(selector).forEach(function(span) {
                    const newSpan = span.cloneNode(true);
                    span.parentNode.replaceChild(newSpan, span);
                    newSpan.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const descDiv = this.closest('.file-description, .grid-item-description');
                        const editDiv = descDiv.querySelector('.description-edit');
                        const input = editDiv.querySelector('.description-input');
                        this.style.display = 'none';
                        editDiv.style.display = 'block';
                        input.focus();
                        input.select();
                    });
                });
            }

            function initializeGridDescriptions() {
                initializeDescriptions('.grid-item-description .description-text');
            }

            initializeDescriptions('.file-description .description-text');
            if (body.classList.contains('grid-view')) {
                initializeGridDescriptions();
            }

            document.addEventListener('blur', function(e) {
                if (e.target.classList.contains('description-input')) {
                    saveDescription(e.target);
                }
            }, true);

            document.addEventListener('keydown', function(e) {
                if (e.target.classList.contains('description-input')) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        e.target.blur();
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        cancelEdit(e.target);
                    }
                }
            });

            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('description-input')) {
                    e.stopPropagation();
                }
            });

            function saveDescription(input) {
                const descDiv = input.closest('.file-description, .grid-item-description');
                const textSpan = descDiv.querySelector('.description-text');
                const editDiv = descDiv.querySelector('.description-edit');
                const filename = descDiv.dataset.filename;
                const newDescription = input.value.trim();

                fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'update_description',
                        filename: filename,
                        description: newDescription,
                        dir: '<?= htmlspecialchars($rel) ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (newDescription === '') {
                            textSpan.textContent = 'Click to add description...';
                            textSpan.classList.add('empty');
                        } else {
                            textSpan.textContent = newDescription;
                            textSpan.classList.remove('empty');
                        }
                    } else {
                        alert('Error saving description: ' + (data.error || 'Unknown error'));
                        input.value = textSpan.classList.contains('empty') ? '' : textSpan.textContent;
                    }
                })
                .catch(error => {
                    alert('Error saving description: ' + error.message);
                })
                .finally(() => {
                    editDiv.style.display = 'none';
                    textSpan.style.display = '';
                });
            }

            function cancelEdit(input) {
                const descDiv = input.closest('.file-description, .grid-item-description');
                const textSpan = descDiv.querySelector('.description-text');
                const editDiv = descDiv.querySelector('.description-edit');
                input.value = textSpan.classList.contains('empty') ? '' : textSpan.textContent;
                editDiv.style.display = 'none';
                textSpan.style.display = '';
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>

