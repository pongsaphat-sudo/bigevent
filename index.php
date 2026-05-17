<?php
declare(strict_types=1);

session_start();

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

const APP_NAME = 'Bigevent Organizer';
const ADMIN_EMAIL = 'admin@bigevent.local';
const ADMIN_PASSWORD = 'admin12345';
const LINE_OA_URL = 'https://lin.ee/6jFk4df';
const CRM_NOTIFICATION_EMAIL = 'Contact@bigevent.co.th';
const CRM_LINE_WEBHOOK_ENV = 'CRM_LINE_WEBHOOK_URL';
const APP_ENV_ENV = 'APP_ENV';

$appConfig = [];
$configPath = __DIR__ . '/config.php';
if (is_file($configPath)) {
    $loadedConfig = require $configPath;
    if (is_array($loadedConfig)) {
        $appConfig = $loadedConfig;
    }
}

$root = __DIR__;
$storageDir = $root . '/storage';
$uploadDir = $root . '/uploads';
$backupDir = $storageDir . '/backups';

if (!is_dir($storageDir)) {
    mkdir($storageDir, 0775, true);
}
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

if (PHP_SAPI === 'cli-server') {
    $requestedFile = __DIR__ . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (is_file($requestedFile)) {
        return false;
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = strtolower((string) app_config('db_driver', 'sqlite'));
    if ($driver === 'mysql') {
        $host = (string) app_config('db_host', 'localhost');
        $port = (string) app_config('db_port', '3306');
        $name = (string) app_config('db_name', '');
        $charset = (string) app_config('db_charset', 'utf8mb4');
        $user = (string) app_config('db_user', '');
        $password = (string) app_config('db_password', '');
        if ($name === '' || $user === '') {
            throw new RuntimeException('MySQL config is missing db_name or db_user.');
        }
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset={$charset}",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } else {
        $pdo = new PDO('sqlite:' . __DIR__ . '/storage/database.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    migrate($pdo);
    return $pdo;
}

function app_config(string $key, $default = null)
{
    global $appConfig;

    $envKeys = ['DB_' . strtoupper(str_replace('db_', '', $key)), strtoupper($key)];
    if ($key === 'db_name') {
        $envKeys[] = 'DB_DATABASE';
    }
    if ($key === 'db_user') {
        $envKeys[] = 'DB_USERNAME';
    }
    foreach (array_unique($envKeys) as $envKey) {
        $value = getenv($envKey);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }

    return $appConfig[$key] ?? $default;
}

function db_is_mysql(PDO $pdo): bool
{
    return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
}

function db_identifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . $name . '`';
}

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    if (db_is_mysql($pdo)) {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . db_identifier($table) . ' LIKE ?');
        $stmt->execute([$column]);
        return (bool) $stmt->fetch();
    }

    $columns = $pdo->query('PRAGMA table_info(' . db_identifier($table) . ')')->fetchAll();
    return in_array($column, array_column($columns, 'name'), true);
}

function db_create_index(PDO $pdo, string $name, string $table, array $columns): void
{
    if (db_is_mysql($pdo)) {
        $stmt = $pdo->prepare('SHOW INDEX FROM ' . db_identifier($table) . ' WHERE Key_name = ?');
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            return;
        }
        $columnSql = implode(', ', array_map(fn (string $column): string => db_identifier($column), $columns));
        $pdo->exec('CREATE INDEX ' . db_identifier($name) . ' ON ' . db_identifier($table) . ' (' . $columnSql . ')');
        return;
    }

    $columnSql = implode(', ', array_map(fn (string $column): string => db_identifier($column), $columns));
    $pdo->exec('CREATE INDEX IF NOT EXISTS ' . db_identifier($name) . ' ON ' . db_identifier($table) . ' (' . $columnSql . ')');
}

function migrate(PDO $pdo): void
{
    if (db_is_mysql($pdo)) {
        foreach ([
            "CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(40) NOT NULL DEFAULT 'super_admin',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS banners (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title TEXT NOT NULL,
                subtitle TEXT NULL,
                cta_label VARCHAR(255) NULL,
                cta_url TEXT NULL,
                image_path TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS portfolios (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title TEXT NOT NULL,
                slug VARCHAR(255) NULL,
                category VARCHAR(255) NULL,
                client VARCHAR(255) NULL,
                location VARCHAR(255) NULL,
                event_date VARCHAR(40) NULL,
                description MEDIUMTEXT NULL,
                video_url TEXT NULL,
                video_url_en TEXT NULL,
                image_path TEXT NULL,
                is_featured TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_portfolios_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS clients (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                logo_path TEXT NULL,
                website TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS articles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title TEXT NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                excerpt TEXT NULL,
                content MEDIUMTEXT NULL,
                image_path TEXT NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                published_at VARCHAR(40) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS gallery_images (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                owner_type VARCHAR(40) NOT NULL,
                owner_id INT UNSIGNED NOT NULL,
                image_path TEXT NOT NULL,
                caption TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_gallery_owner (owner_type, owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS inquiries (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                phone VARCHAR(80) NULL,
                email VARCHAR(255) NULL,
                event_type VARCHAR(255) NULL,
                event_date VARCHAR(40) NULL,
                venue TEXT NULL,
                guest_count VARCHAR(80) NULL,
                budget VARCHAR(120) NULL,
                message MEDIUMTEXT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'new',
                admin_note MEDIUMTEXT NULL,
                source_path TEXT NULL,
                viewed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(191) NOT NULL PRIMARY KEY,
                setting_value MEDIUMTEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ] as $sql) {
            $pdo->exec($sql);
        }
    } else {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'super_admin',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS banners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            subtitle TEXT,
            cta_label TEXT,
            cta_url TEXT,
            image_path TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS portfolios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT,
            category TEXT,
            client TEXT,
            location TEXT,
            event_date TEXT,
            description TEXT,
            video_url TEXT,
            video_url_en TEXT,
            image_path TEXT,
            is_featured INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            logo_path TEXT,
            website TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            excerpt TEXT,
            content TEXT,
            image_path TEXT,
            is_published INTEGER NOT NULL DEFAULT 1,
            published_at TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS gallery_images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_type TEXT NOT NULL,
            owner_id INTEGER NOT NULL,
            image_path TEXT NOT NULL,
            caption TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS inquiries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT,
            email TEXT,
            event_type TEXT,
            event_date TEXT,
            venue TEXT,
            guest_count TEXT,
            budget TEXT,
            message TEXT,
            status TEXT NOT NULL DEFAULT 'new',
            admin_note TEXT,
            source_path TEXT,
            viewed_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");
    }

    if (!db_column_exists($pdo, 'users', 'role')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(40) NOT NULL DEFAULT 'admin'");
        $firstUserId = (int) $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($firstUserId > 0) {
            $stmt = $pdo->prepare("UPDATE users SET role = 'super_admin' WHERE id = ?");
            $stmt->execute([$firstUserId]);
        }
        $pdo->exec("UPDATE users SET role = 'super_admin' WHERE email = " . $pdo->quote(ADMIN_EMAIL));
    }
    db_create_index($pdo, 'idx_inquiries_status_created', 'inquiries', ['status', 'created_at']);
    db_create_index($pdo, 'idx_inquiries_email', 'inquiries', ['email']);
    db_create_index($pdo, 'idx_inquiries_phone', 'inquiries', ['phone']);
    if (!db_column_exists($pdo, 'inquiries', 'viewed_at')) {
        $pdo->exec("ALTER TABLE inquiries ADD COLUMN viewed_at " . (db_is_mysql($pdo) ? "DATETIME NULL" : "TEXT"));
    }

    if (!db_column_exists($pdo, 'articles', 'sort_order')) {
        $pdo->exec("ALTER TABLE articles ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0");
    }

    if (!db_column_exists($pdo, 'portfolios', 'slug')) {
        $pdo->exec("ALTER TABLE portfolios ADD COLUMN slug " . (db_is_mysql($pdo) ? "VARCHAR(255) NULL" : "TEXT"));
    }

    $localizedColumns = [
        'banners' => [
            'title_en' => 'TEXT',
            'subtitle_en' => 'TEXT',
            'cta_label_en' => 'TEXT',
        ],
        'portfolios' => [
            'slug_en' => 'TEXT',
            'title_en' => 'TEXT',
            'category_en' => 'TEXT',
            'client_en' => 'TEXT',
            'location_en' => 'TEXT',
            'description_en' => 'TEXT',
            'seo_focus_keyphrase' => 'TEXT',
            'seo_focus_keyphrase_en' => 'TEXT',
            'seo_title' => 'TEXT',
            'seo_title_en' => 'TEXT',
            'meta_description' => 'TEXT',
            'meta_description_en' => 'TEXT',
            'video_url' => 'TEXT',
            'video_url_en' => 'TEXT',
        ],
        'clients' => [
            'name_en' => 'TEXT',
        ],
        'articles' => [
            'slug_en' => 'TEXT',
            'title_en' => 'TEXT',
            'excerpt_en' => 'TEXT',
            'content_en' => 'TEXT',
            'seo_focus_keyphrase' => 'TEXT',
            'seo_focus_keyphrase_en' => 'TEXT',
            'seo_title' => 'TEXT',
            'seo_title_en' => 'TEXT',
            'meta_description' => 'TEXT',
            'meta_description_en' => 'TEXT',
        ],
    ];

    foreach ($localizedColumns as $table => $columns) {
        foreach ($columns as $column => $type) {
            if (!db_column_exists($pdo, $table, $column)) {
                $pdo->exec("ALTER TABLE " . db_identifier($table) . " ADD COLUMN " . db_identifier($column) . " {$type}");
            }
        }
    }

    $portfolioRows = $pdo->query("SELECT id, title, slug FROM portfolios")->fetchAll();
    $updatePortfolioSlug = $pdo->prepare("UPDATE portfolios SET slug = ? WHERE id = ?");
    foreach ($portfolioRows as $row) {
        if (trim((string) ($row['slug'] ?? '')) === '') {
            $baseSlug = slugify($row['title'] ?? ('portfolio-' . $row['id']));
            $slug = $baseSlug;
            $i = 2;
            while ((int) $pdo->query("SELECT COUNT(*) FROM portfolios WHERE slug = " . $pdo->quote($slug) . " AND id != " . (int) $row['id'])->fetchColumn() > 0) {
                $slug = $baseSlug . '-' . $i++;
            }
            $updatePortfolioSlug->execute([$slug, (int) $row['id']]);
        }
    }

    $hasUser = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($hasUser === 0) {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->execute(['Administrator', ADMIN_EMAIL, password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT), 'super_admin']);
    }

    $hasBanner = (int) $pdo->query("SELECT COUNT(*) FROM banners")->fetchColumn();
    if ($hasBanner === 0) {
        $stmt = $pdo->prepare("INSERT INTO banners (title, subtitle, cta_label, cta_url, image_path, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'ออกแบบอีเวนต์ให้แบรนด์ของคุณถูกจดจำ',
            'ทีมออแกไนเซอร์ครบวงจรสำหรับ Corporate Event, Product Launch, Exhibition และ Celebration พร้อมดูแลตั้งแต่คอนเซ็ปต์ถึงวันจริง',
            'ดูผลงานของเรา',
            '/portfolio',
            'https://images.unsplash.com/photo-1511795409834-ef04bbd61622?auto=format&fit=crop&w=1800&q=85',
            1,
            1,
        ]);
    }

    $hasPortfolio = (int) $pdo->query("SELECT COUNT(*) FROM portfolios")->fetchColumn();
    if ($hasPortfolio === 0) {
        $stmt = $pdo->prepare("INSERT INTO portfolios (title, category, client, location, event_date, description, image_path, is_featured, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $items = [
            ['Product Launch 2026', 'Product Launch', 'NovaTech', 'Bangkok', '2026-03-08', 'งานเปิดตัวผลิตภัณฑ์พร้อมเวที LED, light design และ media session', 'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&w=1400&q=85', 1, 1],
            ['Annual Conference', 'Corporate Event', 'Apex Group', 'Queen Sirikit Center', '2026-02-14', 'ประชุมใหญ่ประจำปีพร้อมระบบ registration และ hybrid streaming', 'https://images.unsplash.com/photo-1505373877841-8d25f7d46678?auto=format&fit=crop&w=1400&q=85', 1, 2],
            ['Brand Exhibition Booth', 'Exhibition', 'Urban Living', 'BITEC', '2026-01-22', 'ออกแบบและผลิตบูธนิทรรศการแบบ immersive สำหรับเก็บ lead หน้างาน', 'https://images.unsplash.com/photo-1531058020387-3be344556be6?auto=format&fit=crop&w=1400&q=85', 1, 3],
        ];
        foreach ($items as $item) {
            $stmt->execute($item);
        }
    }

    $hasClient = (int) $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    if ($hasClient === 0) {
        $stmt = $pdo->prepare("INSERT INTO clients (name, logo_path, website, is_active, sort_order) VALUES (?, ?, ?, ?, ?)");
        foreach (['NovaTech', 'Apex Group', 'Urban Living', 'Siam Retail', 'Cloud Nine', 'Prime Foods'] as $i => $name) {
            $stmt->execute([$name, '', '', 1, $i + 1]);
        }
    }

    $hasArticle = (int) $pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
    if ($hasArticle === 0) {
        $stmt = $pdo->prepare("INSERT INTO articles (title, slug, excerpt, content, image_path, is_published, published_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'เช็กลิสต์ก่อนเริ่มวางแผนอีเวนต์บริษัท',
            'corporate-event-checklist',
            'สิ่งที่ทีมแบรนด์ควรเตรียมก่อนคุยกับออแกไนเซอร์ เพื่อให้งานเดินเร็วและคุมงบได้ดีขึ้น',
            "เริ่มจากวัตถุประสงค์ของงาน กลุ่มเป้าหมาย งบประมาณโดยประมาณ จำนวนแขก และผลลัพธ์ที่ต้องการหลังจบงาน จากนั้นค่อยพัฒนา mood, production, flow และ touchpoint ให้สอดคล้องกัน",
            'https://images.unsplash.com/photo-1556761175-b413da4baf72?auto=format&fit=crop&w=1400&q=85',
            1,
            date('Y-m-d'),
        ]);
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = urldecode($path);
    return rtrim($path, '/') ?: '/';
}

function current_lang(): string
{
    $path = path();
    return ($path === '/en' || str_starts_with($path, '/en/')) ? 'en' : 'th';
}

function route_path(?string $path = null): string
{
    $path = $path ?? path();
    if ($path === '/en') {
        return '/';
    }
    if (str_starts_with($path, '/en/')) {
        return substr($path, 3) ?: '/';
    }
    return $path;
}

function localized_url(string $lang, ?string $path = null): string
{
    $path = route_path($path ?? path());
    if ($lang === 'en') {
        return $path === '/' ? '/en' : '/en' . $path;
    }
    return $path;
}

function url_for(string $path): string
{
    return localized_url(current_lang(), $path);
}

function set_alternate_paths(string $thaiPath, string $englishPath): void
{
    $GLOBALS['alternate_paths'] = ['th' => $thaiPath, 'en' => $englishPath];
}

function alternate_path(string $lang, string $fallback): string
{
    return $GLOBALS['alternate_paths'][$lang] ?? localized_url($lang, $fallback);
}

function localized(array $row, string $field): string
{
    $value = trim((string) ($row[$field] ?? ''));
    if (current_lang() === 'en') {
        $english = trim((string) ($row[$field . '_en'] ?? ''));
        if ($english !== '') {
            return $english;
        }
    }
    return $value;
}

function t(string $key): string
{
    $lang = current_lang();
    $strings = [
        'th' => [
            'home' => 'หน้าแรก',
            'about' => 'เกี่ยวกับเรา',
            'services' => 'บริการ',
            'portfolio' => 'ผลงาน',
            'clients' => 'ลูกค้า',
            'articles' => 'บทความ',
            'contact' => 'ติดต่อ',
            'quote' => 'ติดต่อสอบถาม',
            'talk_project' => 'คุยโปรเจกต์',
            'view_work' => 'ดูผลงาน',
            'view_all' => 'ดูทั้งหมด',
            'read_all' => 'อ่านทั้งหมด',
            'read_more' => 'อ่านต่อ',
            'details' => 'ดูรายละเอียด',
            'back_portfolio' => 'กลับไปหน้าผลงาน',
            'back_articles' => 'กลับไปหน้าบทความ',
            'gallery_count' => 'ภาพ',
            'footer_desc' => 'บริษัท บิ๊กอีเว้นท์ จำกัด รับจัดงาน Event, ออแกไนเซอร์, เอ็กซิบิชั่น, สัมมนา, คอนเสิร์ต, จัดบูธร้านค้า และผลิตสื่อประชาสัมพันธ์ครบวงจร',
            'default_description' => 'Bigevent บริษัทออแกไนเซอร์ครบวงจร รับจัดงาน Corporate Event, Product Launch, Exhibition, Conference และงานแบรนด์ระดับมืออาชีพ',
        ],
        'en' => [
            'home' => 'Home',
            'about' => 'About',
            'services' => 'Services',
            'portfolio' => 'Portfolio',
            'clients' => 'Clients',
            'articles' => 'Articles',
            'contact' => 'Contact',
            'quote' => 'Contact Us',
            'talk_project' => 'Discuss a Project',
            'view_work' => 'View Work',
            'view_all' => 'View All',
            'read_all' => 'Read All',
            'read_more' => 'Read More',
            'details' => 'View Details',
            'back_portfolio' => 'Back to Portfolio',
            'back_articles' => 'Back to Articles',
            'gallery_count' => 'photos',
            'footer_desc' => 'BIG EVENT CO., LTD. provides full-service event organizing, exhibitions, seminars, concerts, booths, media production, advertising and PR materials.',
            'default_description' => 'Bigevent Organizer is a full-service event company for corporate events, product launches, exhibitions, conferences and professional brand experiences.',
        ],
    ];
    return $strings[$lang][$key] ?? $strings['th'][$key] ?? $key;
}

function base_url(): string
{
    $configured = rtrim(setting('site_url', 'https://www.bigevent.co.th'), '/');
    if ($configured !== '') {
        return $configured;
    }
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function absolute_url(string $path = '/'): string
{
    if (preg_match('#^https?://#', $path)) {
        return $path;
    }
    return rtrim(base_url(), '/') . '/' . ltrim($path, '/');
}

function set_schema_extra(array $items): void
{
    $GLOBALS['schema_extra'] = $items;
}

function schema_extra(): array
{
    return $GLOBALS['schema_extra'] ?? [];
}

function redirect(string $to)
{
    header('Location: ' . $to);
    exit;
}

function json_response(array $payload, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid CSRF token');
    }
}

function is_admin(): bool
{
    return !empty($_SESSION['admin_id']);
}

function current_admin(): ?array
{
    if (!is_admin()) {
        return null;
    }

    static $admin = null;
    if ($admin !== null) {
        return $admin;
    }

    $stmt = db()->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
    $stmt->execute([(int) $_SESSION['admin_id']]);
    $admin = $stmt->fetch() ?: null;
    if (!$admin) {
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
        return null;
    }

    $_SESSION['admin_name'] = $admin['name'];
    $_SESSION['admin_role'] = $admin['role'];
    return $admin;
}

function current_admin_role(): string
{
    return (string) (current_admin()['role'] ?? $_SESSION['admin_role'] ?? 'manager');
}

function admin_display_name(?array $admin = null): string
{
    $admin = $admin ?: current_admin();
    return trim((string) ($admin['name'] ?? 'Admin')) ?: 'Admin';
}

function admin_initials(?array $admin = null): string
{
    $name = admin_display_name($admin);
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= mb_substr($part, 0, 1);
        }
        if (mb_strlen($initials) >= 2) {
            break;
        }
    }

    return mb_strtoupper($initials ?: 'A');
}

function admin_avatar_html(?array $admin = null, string $class = 'h-9 w-9'): string
{
    return '<span class="grid ' . e($class) . ' shrink-0 place-items-center rounded-full bg-slate-950 text-xs font-black text-white shadow-sm ring-2 ring-white">' . e(admin_initials($admin)) . '</span>';
}

function crm_new_count(): int
{
    static $count = null;
    if ($count !== null) {
        return $count;
    }

    $stmt = db()->prepare("SELECT COUNT(*) FROM inquiries WHERE status = ? AND viewed_at IS NULL");
    $stmt->execute(['new']);
    $count = (int) $stmt->fetchColumn();
    return $count;
}

function crm_notification_badge(int $count, string $class = ''): string
{
    if ($count <= 0) {
        return '';
    }

    $label = $count > 99 ? '99+' : (string) $count;
    return '<span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-red-600 px-2 py-1 text-[11px] font-black leading-none text-white shadow-sm ring-2 ring-white ' . e($class) . '" aria-label="CRM ใหม่ ' . e($label) . ' รายการ"><i data-lucide="bell" class="h-3 w-3"></i><span>' . e($label) . '</span></span>';
}

function frontend_admin_dropdown(?array $admin, bool $mobile = false): string
{
    if (!$admin) {
        return '';
    }

    $newCrmCount = crm_new_count();
    $profileUrl = '/admin/users/edit?id=' . (int) ($admin['id'] ?? 0);
    $summaryClass = $mobile
        ? 'flex w-full min-w-0 cursor-pointer list-none items-center justify-center gap-2 rounded-2xl bg-slate-100 px-4 py-3 text-sm font-bold text-slate-700 [&::-webkit-details-marker]:hidden'
        : 'hidden max-w-52 cursor-pointer list-none items-center gap-2 rounded-full bg-white px-2 py-1.5 pr-3 text-sm font-extrabold text-slate-700 shadow-sm ring-1 ring-slate-200 hover:text-coral md:inline-flex [&::-webkit-details-marker]:hidden';
    $dropdownClass = $mobile
        ? 'mt-2 overflow-hidden rounded-2xl border border-slate-100 bg-white p-2 text-left shadow-sm'
        : 'absolute right-0 mt-2 w-56 overflow-hidden rounded-2xl border border-slate-100 bg-white p-2 text-slate-900 shadow-soft';

    ob_start();
    ?>
    <details class="<?= $mobile ? 'relative min-w-0' : 'relative hidden md:block' ?>">
        <summary class="<?= e($summaryClass) ?>">
            <?= admin_avatar_html($admin, 'h-8 w-8') ?>
            <span class="truncate"><?= e(admin_display_name($admin)) ?></span>
            <?= crm_notification_badge($newCrmCount) ?>
            <i data-lucide="chevron-down" class="h-4 w-4 shrink-0 text-slate-400"></i>
        </summary>
        <div class="<?= e($dropdownClass) ?>">
            <a href="/admin" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-extrabold hover:bg-slate-100">
                <i data-lucide="layout-dashboard" class="h-4 w-4 text-slate-500"></i>
                Dashboard
            </a>
            <a href="/admin/crm" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-extrabold hover:bg-slate-100">
                <i data-lucide="message-square-text" class="h-4 w-4 text-slate-500"></i>
                <span class="min-w-0 flex-1">CRM ลูกค้า</span>
                <?= crm_notification_badge($newCrmCount, 'ring-0') ?>
            </a>
            <a href="<?= e($profileUrl) ?>" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-extrabold hover:bg-slate-100">
                <i data-lucide="user-circle" class="h-4 w-4 text-slate-500"></i>
                โปรไฟล์
            </a>
            <form method="post" action="/admin/logout" class="mt-1 border-t border-slate-100 pt-1">
                <?= csrf_field() ?>
                <button class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm font-extrabold text-red-600 hover:bg-red-50">
                    <i data-lucide="log-out" class="h-4 w-4"></i>
                    Logout
                </button>
            </form>
        </div>
    </details>
    <?php
    return ob_get_clean();
}

function role_label(string $role): string
{
    return [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'manager' => 'Manager',
    ][$role] ?? 'Manager';
}

function role_badge_class(string $role): string
{
    return [
        'super_admin' => 'bg-amber-50 text-amber-700 ring-amber-100',
        'admin' => 'bg-sky-50 text-sky-700 ring-sky-100',
        'manager' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    ][$role] ?? 'bg-slate-100 text-slate-600 ring-slate-200';
}

function can_delete_user(array $target): bool
{
    $current = current_admin();
    if (!$current || (int) $current['id'] === (int) $target['id']) {
        return false;
    }

    $currentRole = (string) $current['role'];
    $targetRole = (string) ($target['role'] ?? 'manager');
    if ($currentRole === 'super_admin') {
        return true;
    }
    if ($currentRole === 'admin') {
        return $targetRole !== 'super_admin';
    }
    if ($currentRole === 'manager') {
        return $targetRole === 'manager';
    }

    return false;
}

function default_admin_password_is_active(): bool
{
    $stmt = db()->prepare("SELECT password_hash FROM users WHERE email = ?");
    $stmt->execute([ADMIN_EMAIL]);
    $hash = (string) ($stmt->fetchColumn() ?: '');
    return $hash !== '' && password_verify(ADMIN_PASSWORD, $hash);
}

function require_admin(): void
{
    if (!is_admin() || !current_admin()) {
        redirect('/admin/login');
    }
    $env = strtolower((string) (getenv(APP_ENV_ENV) ?: 'local'));
    if ($env === 'production' && default_admin_password_is_active() && !in_array(path(), ['/admin/account/password', '/admin/logout'], true)) {
        flash('กรุณาเปลี่ยนรหัสผ่าน Admin Default ก่อนใช้งาน Production', 'error');
        redirect('/admin/account/password');
    }
}

function require_super_admin(): void
{
    require_admin();
    if (current_admin_role() !== 'super_admin') {
        http_response_code(403);
        exit('Forbidden');
    }
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function setting_defaults(): array
{
    return [
        'site_url' => 'https://www.bigevent.co.th',
        'project_name' => APP_NAME,
        'company_name' => 'บริษัท บิ๊กอีเว้นท์ จำกัด',
        'admin_contact_email' => 'Contact@bigevent.co.th',
        'default_og_image' => '/assets/img/og-default.png',
        'line_oa_url' => LINE_OA_URL,
        'crm_notification_email' => CRM_NOTIFICATION_EMAIL,
        'crm_line_webhook_url' => getenv(CRM_LINE_WEBHOOK_ENV) ?: '',
        'google_maps_url' => 'https://maps.app.goo.gl/S1gbvFiQm2sfMyjt8',
        'production_note' => 'เปลี่ยนรหัสผ่าน default และตั้งค่า webhook/email ก่อนขึ้น production',
        'seo_home_title_th' => 'Bigevent Organizer | รับจัดงานอีเวนต์ครบวงจร',
        'seo_home_title_en' => 'Bigevent Organizer | Full-service Event Organizer',
        'seo_home_description_th' => 'Bigevent Organizer บริษัทรับจัดงานอีเวนต์ครบวงจร ดูแล Corporate Event, Product Launch, Exhibition และงานองค์กรตั้งแต่คอนเซ็ปต์ถึงวันจริง',
        'seo_home_description_en' => 'Bigevent Organizer is a full-service event company for corporate events, product launches, exhibitions and organizational events from concept to show day.',
        'seo_about_title_th' => 'เกี่ยวกับเรา | Bigevent Organizer',
        'seo_about_title_en' => 'About Us | Bigevent Organizer',
        'seo_about_description_th' => 'รู้จักบริษัท บิ๊กอีเว้นท์ จำกัด ทีมออแกไนเซอร์ที่ผสมกลยุทธ์แบรนด์ ความคิดสร้างสรรค์ โปรดักชัน และเทคโนโลยีสำหรับงานอีเวนต์',
        'seo_about_description_en' => 'Learn about Bigevent Organizer, an event team combining brand strategy, creative direction, production and technology for professional events.',
        'seo_services_title_th' => 'บริการรับจัดงานอีเว้นท์ | Bigevent Organizer',
        'seo_services_title_en' => 'Event Organizer Services | Bigevent Organizer',
        'seo_services_description_th' => 'บริการรับจัดงานอีเว้นท์ครบวงจร ครอบคลุมงานองค์กร เปิดตัวสินค้า สัมมนา นิทรรศการ คอนเสิร์ต บูธ และสื่อประชาสัมพันธ์',
        'seo_services_description_en' => 'Full-service event organizer for corporate events, product launches, seminars, exhibitions, concerts, booths and media production.',
        'seo_portfolio_title_th' => 'ผลงานบริษัท | Bigevent Organizer',
        'seo_portfolio_title_en' => 'Portfolio | Bigevent Organizer',
        'seo_portfolio_description_th' => 'รวมผลงานจัดงานอีเวนต์ของ Bigevent ทั้ง Product Launch, Corporate Event, Exhibition และงานองค์กร',
        'seo_portfolio_description_en' => 'Explore Bigevent event portfolio, including product launches, corporate events, exhibitions and organizational events.',
        'seo_clients_title_th' => 'โลโก้ลูกค้าและพาร์ทเนอร์ | Bigevent Organizer',
        'seo_clients_title_en' => 'Clients and Partners | Bigevent Organizer',
        'seo_clients_description_th' => 'รวมโลโก้ลูกค้า องค์กร แบรนด์ และพาร์ทเนอร์ที่ไว้วางใจ Bigevent Organizer',
        'seo_clients_description_en' => 'Client and partner logo collection for Bigevent Organizer.',
        'seo_articles_title_th' => 'บทความและไอเดียจัดงาน | Bigevent Organizer',
        'seo_articles_title_en' => 'Articles and Event Ideas | Bigevent Organizer',
        'seo_articles_description_th' => 'บทความและไอเดียจัดงานอีเวนต์สำหรับแบรนด์และองค์กร จากทีม Bigevent Organizer',
        'seo_articles_description_en' => 'Event planning articles and ideas for brands and organizations from Bigevent Organizer.',
        'seo_contact_title_th' => 'ติดต่อเรา | Bigevent Organizer',
        'seo_contact_title_en' => 'Contact Us | Bigevent Organizer',
        'seo_contact_description_th' => 'ติดต่อ Bigevent เพื่อขอใบเสนอราคาและปรึกษาการจัดงานอีเวนต์ Product Launch, Corporate Event, Exhibition และงานองค์กร',
        'seo_contact_description_en' => 'Contact Bigevent for event quotations and consultation for product launches, corporate events, exhibitions and organizational events.',
    ];
}

function setting(string $key, ?string $fallback = null): string
{
    static $settings = null;
    if ($settings === null) {
        $settings = setting_defaults();
        $rows = db()->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
    }
    return (string) ($settings[$key] ?? $fallback ?? '');
}

function save_setting(string $key, string $value): void
{
    $pdo = db();
    if (db_is_mysql($pdo)) {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
        ");
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP
        ");
    }
    $stmt->execute([$key, $value]);
}

function settings_schema(): array
{
    return [
        'project' => [
            'title' => 'โปรเจกต์',
            'icon' => 'sliders-horizontal',
            'description' => 'ข้อมูลหลักของเว็บไซต์และบริษัท',
            'fields' => [
                'site_url' => ['Site URL', 'url', 'โดเมนจริง เช่น https://www.bigevent.co.th'],
                'project_name' => ['ชื่อโปรเจกต์', 'text', 'เช่น Bigevent Organizer'],
                'company_name' => ['ชื่อบริษัท', 'text', 'ชื่อบริษัทที่ใช้ในหน้าเว็บ'],
                'admin_contact_email' => ['อีเมลหลัก', 'email', 'อีเมลกลางสำหรับติดต่อ'],
                'default_og_image' => ['Default OG Image', 'text', 'เช่น /assets/img/og-default.png'],
                'google_maps_url' => ['Google Maps URL', 'url', 'ลิงก์แผนที่บริษัท'],
            ],
        ],
        'seo' => [
            'title' => 'SEO รายหน้า',
            'icon' => 'search-check',
            'description' => 'ปรับ Title และ Description ของหน้าหลักสองภาษา',
            'fields' => [
                'seo_home_title_th' => ['Home Title TH', 'text', 'Title หน้าแรกภาษาไทย'],
                'seo_home_title_en' => ['Home Title EN', 'text', 'Title หน้าแรกภาษาอังกฤษ'],
                'seo_home_description_th' => ['Home Description TH', 'textarea', 'Description หน้าแรกภาษาไทย'],
                'seo_home_description_en' => ['Home Description EN', 'textarea', 'Description หน้าแรกภาษาอังกฤษ'],
                'seo_about_title_th' => ['About Title TH', 'text', 'Title หน้าเกี่ยวกับภาษาไทย'],
                'seo_about_title_en' => ['About Title EN', 'text', 'Title หน้าเกี่ยวกับภาษาอังกฤษ'],
                'seo_about_description_th' => ['About Description TH', 'textarea', 'Description หน้าเกี่ยวกับภาษาไทย'],
                'seo_about_description_en' => ['About Description EN', 'textarea', 'Description หน้าเกี่ยวกับภาษาอังกฤษ'],
                'seo_services_title_th' => ['Services Title TH', 'text', 'Title หน้าบริการภาษาไทย'],
                'seo_services_title_en' => ['Services Title EN', 'text', 'Title หน้าบริการภาษาอังกฤษ'],
                'seo_services_description_th' => ['Services Description TH', 'textarea', 'Description หน้าบริการภาษาไทย'],
                'seo_services_description_en' => ['Services Description EN', 'textarea', 'Description หน้าบริการภาษาอังกฤษ'],
                'seo_portfolio_title_th' => ['Portfolio Title TH', 'text', 'Title หน้าผลงานภาษาไทย'],
                'seo_portfolio_title_en' => ['Portfolio Title EN', 'text', 'Title หน้าผลงานภาษาอังกฤษ'],
                'seo_portfolio_description_th' => ['Portfolio Description TH', 'textarea', 'Description หน้าผลงานภาษาไทย'],
                'seo_portfolio_description_en' => ['Portfolio Description EN', 'textarea', 'Description หน้าผลงานภาษาอังกฤษ'],
                'seo_clients_title_th' => ['Clients Title TH', 'text', 'Title หน้าโลโก้ลูกค้าภาษาไทย'],
                'seo_clients_title_en' => ['Clients Title EN', 'text', 'Title หน้าโลโก้ลูกค้าภาษาอังกฤษ'],
                'seo_clients_description_th' => ['Clients Description TH', 'textarea', 'Description หน้าโลโก้ลูกค้าภาษาไทย'],
                'seo_clients_description_en' => ['Clients Description EN', 'textarea', 'Description หน้าโลโก้ลูกค้าภาษาอังกฤษ'],
                'seo_articles_title_th' => ['Articles Title TH', 'text', 'Title หน้าบทความภาษาไทย'],
                'seo_articles_title_en' => ['Articles Title EN', 'text', 'Title หน้าบทความภาษาอังกฤษ'],
                'seo_articles_description_th' => ['Articles Description TH', 'textarea', 'Description หน้าบทความภาษาไทย'],
                'seo_articles_description_en' => ['Articles Description EN', 'textarea', 'Description หน้าบทความภาษาอังกฤษ'],
                'seo_contact_title_th' => ['Contact Title TH', 'text', 'Title หน้าติดต่อภาษาไทย'],
                'seo_contact_title_en' => ['Contact Title EN', 'text', 'Title หน้าติดต่อภาษาอังกฤษ'],
                'seo_contact_description_th' => ['Contact Description TH', 'textarea', 'Description หน้าติดต่อภาษาไทย'],
                'seo_contact_description_en' => ['Contact Description EN', 'textarea', 'Description หน้าติดต่อภาษาอังกฤษ'],
            ],
        ],
        'api' => [
            'title' => 'API & Integration',
            'icon' => 'plug-zap',
            'description' => 'รวมค่าการเชื่อมต่อภายนอก',
            'fields' => [
                'line_oa_url' => ['LINE OA URL', 'url', 'ลิงก์ LINE OA ที่ลูกค้ากดติดต่อ'],
                'crm_line_webhook_url' => ['CRM Webhook URL', 'url', 'Webhook สำหรับส่งแจ้งเตือน CRM เข้า LINE หรือระบบอื่น'],
            ],
        ],
        'notification' => [
            'title' => 'การแจ้งเตือน',
            'icon' => 'bell-ring',
            'description' => 'ช่องทางแจ้งเตือนเมื่อมีลูกค้ากรอกฟอร์ม',
            'fields' => [
                'crm_notification_email' => ['อีเมลรับ Lead', 'email', 'อีเมลที่จะได้รับแจ้งเตือนจากฟอร์ม Contact'],
            ],
        ],
        'security' => [
            'title' => 'Security',
            'icon' => 'shield-check',
            'description' => 'โน้ตสำหรับ production และความปลอดภัย',
            'fields' => [
                'production_note' => ['Production Checklist', 'textarea', 'รายการที่ต้องตรวจสอบก่อนขึ้นระบบจริง'],
            ],
        ],
    ];
}

function seo_page_key(string $path): ?string
{
    return [
        '/' => 'home',
        '/about' => 'about',
        '/services' => 'services',
        '/portfolio' => 'portfolio',
        '/clients' => 'clients',
        '/articles' => 'articles',
        '/contact' => 'contact',
        '/privacy-policy' => 'privacy',
        '/cookie-policy' => 'cookie',
    ][$path] ?? null;
}

function seo_setting(string $pageKey, string $field, string $lang, string $fallback): string
{
    $value = trim(setting('seo_' . $pageKey . '_' . $field . '_' . ($lang === 'en' ? 'en' : 'th')));
    return $value !== '' ? $value : $fallback;
}

function current_page(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

function per_page(): int
{
    return 12;
}

function pagination_html(int $total, int $page, int $perPage): string
{
    $pages = (int) ceil($total / max(1, $perPage));
    if ($pages <= 1) {
        return '';
    }

    $base = $_GET;
    unset($base['page']);
    ob_start();
    ?>
    <nav class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-[1.25rem] bg-white p-3 text-sm font-bold shadow-sm">
        <span class="text-slate-500">หน้า <?= $page ?> / <?= $pages ?> จากทั้งหมด <?= $total ?> รายการ</span>
        <div class="flex flex-wrap gap-2">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <?php $query = http_build_query([...$base, 'page' => $i]); ?>
                <a href="?<?= e($query) ?>" class="grid h-10 min-w-10 place-items-center rounded-xl px-3 <?= $i === $page ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    </nav>
    <?php
    return ob_get_clean();
}

function slugify(string $text): string
{
    $text = trim(mb_strtolower($text));
    $text = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $text) ?: '';
    $text = trim($text, '-');
    return $text !== '' ? $text : 'article-' . time();
}

function image_upload_profiles(): array
{
    return [
        'banner' => ['mode' => 'cover', 'width' => 1920, 'height' => 1080, 'quality' => 82],
        'work' => ['mode' => 'cover', 'width' => 1600, 'height' => 1000, 'quality' => 82],
        'article' => ['mode' => 'cover', 'width' => 1600, 'height' => 900, 'quality' => 82],
        'gallery' => ['mode' => 'fit', 'width' => 1800, 'height' => 1800, 'quality' => 80],
        'logo' => ['mode' => 'contain', 'width' => 800, 'height' => 450, 'quality' => 86],
        'default' => ['mode' => 'fit', 'width' => 1600, 'height' => 1600, 'quality' => 82],
    ];
}

function upload_image(string $field, ?string $current = null, string $profile = 'default'): ?string
{
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $current;
    }

    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return $current;
    }

    $path = optimize_uploaded_image($_FILES[$field]['tmp_name'], (string) $_FILES[$field]['name'], $profile);
    if ($path) {
        return $path;
    }

    if (!is_supported_upload_image($_FILES[$field]['tmp_name'])) {
        flash('รองรับเฉพาะไฟล์รูปภาพเท่านั้น', 'error');
    }

    return $current;
}

function upload_image_file(string $tmp, string $originalName = '', string $profile = 'gallery'): ?string
{
    if (!is_uploaded_file($tmp) && !is_file($tmp)) {
        return null;
    }

    return optimize_uploaded_image($tmp, $originalName, $profile);
}

function is_supported_upload_image(string $tmp): bool
{
    $mime = mime_content_type($tmp);
    $allowed = [
        'image/svg+xml' => 'svg',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    return isset($allowed[$mime]);
}

function optimize_uploaded_image(string $tmp, string $originalName = '', string $profile = 'default'): ?string
{
    if (!is_supported_upload_image($tmp)) {
        return null;
    }

    $mime = mime_content_type($tmp);
    if (in_array($mime, ['image/svg+xml', 'image/gif'], true)) {
        return store_original_upload($tmp, $originalName, $mime);
    }

    $profiles = image_upload_profiles();
    $settings = $profiles[$profile] ?? $profiles['default'];
    $source = create_image_from_upload($tmp, $mime);
    if (!$source) {
        return store_original_upload($tmp, $originalName, $mime);
    }

    $source = orient_image($source, $tmp, $mime);
    $optimized = resize_image_resource($source, (int) $settings['width'], (int) $settings['height'], (string) $settings['mode']);
    imagedestroy($source);
    if (!$optimized) {
        return null;
    }

    $safeBase = safe_upload_basename($originalName ?: $profile);
    $name = date('YmdHis') . '-' . $safeBase . '-' . bin2hex(random_bytes(4)) . '.webp';
    $target = __DIR__ . '/uploads/' . $name;
    $saved = imagewebp($optimized, $target, (int) $settings['quality']);
    imagedestroy($optimized);

    if ($saved && is_file($target)) {
        return '/uploads/' . $name;
    }

    return null;
}

function store_original_upload(string $tmp, string $originalName, string $mime): ?string
{
    $extensions = [
        'image/svg+xml' => 'svg',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $extension = $extensions[$mime] ?? pathinfo($originalName, PATHINFO_EXTENSION) ?: 'img';
    $name = date('YmdHis') . '-' . safe_upload_basename($originalName) . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $target = __DIR__ . '/uploads/' . $name;
    if (move_uploaded_file($tmp, $target) || rename($tmp, $target)) {
        return '/uploads/' . $name;
    }

    return null;
}

function safe_upload_basename(string $originalName): string
{
    return preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'image';
}

function create_image_from_upload(string $tmp, string $mime): GdImage|false
{
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png' => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
        default => false,
    };
}

function orient_image(GdImage $image, string $tmp, string $mime): GdImage
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return $image;
    }

    $exif = @exif_read_data($tmp);
    $orientation = (int) ($exif['Orientation'] ?? 1);
    $rotated = match ($orientation) {
        3 => imagerotate($image, 180, 0),
        6 => imagerotate($image, -90, 0),
        8 => imagerotate($image, 90, 0),
        default => false,
    };

    if ($rotated instanceof GdImage) {
        imagedestroy($image);
        return $rotated;
    }

    return $image;
}

function resize_image_resource(GdImage $source, int $targetWidth, int $targetHeight, string $mode): ?GdImage
{
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        return null;
    }

    if ($mode === 'cover') {
        $scale = max($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
        $resizeWidth = max(1, (int) ceil($sourceWidth * $scale));
        $resizeHeight = max(1, (int) ceil($sourceHeight * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagecopyresampled(
            $canvas,
            $source,
            (int) floor(($targetWidth - $resizeWidth) / 2),
            (int) floor(($targetHeight - $resizeHeight) / 2),
            0,
            0,
            $resizeWidth,
            $resizeHeight,
            $sourceWidth,
            $sourceHeight
        );
        return $canvas;
    }

    $scale = min(1, $targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
    if ($mode === 'contain') {
        $scale = min($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
    }
    $resizeWidth = max(1, (int) round($sourceWidth * $scale));
    $resizeHeight = max(1, (int) round($sourceHeight * $scale));

    if ($mode === 'contain') {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagecopyresampled(
            $canvas,
            $source,
            (int) floor(($targetWidth - $resizeWidth) / 2),
            (int) floor(($targetHeight - $resizeHeight) / 2),
            0,
            0,
            $resizeWidth,
            $resizeHeight,
            $sourceWidth,
            $sourceHeight
        );
        return $canvas;
    }

    $canvas = imagecreatetruecolor($resizeWidth, $resizeHeight);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefilledrectangle($canvas, 0, 0, $resizeWidth, $resizeHeight, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $resizeWidth, $resizeHeight, $sourceWidth, $sourceHeight);
    return $canvas;
}

function save_gallery_uploads(string $ownerType, int $ownerId, string $field = 'gallery_images'): array
{
    if ($ownerId <= 0 || empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) {
        return [];
    }

    $pdo = db();
    $maxSortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM gallery_images WHERE owner_type = ? AND owner_id = ?");
    $maxSortStmt->execute([$ownerType, $ownerId]);
    $sort = (int) $maxSortStmt->fetchColumn();
    $insert = $pdo->prepare("INSERT INTO gallery_images (owner_type, owner_id, image_path, caption, sort_order) VALUES (?, ?, ?, ?, ?)");
    $uploaded = [];

    foreach ($_FILES[$field]['name'] as $i => $name) {
        if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $path = upload_image_file($_FILES[$field]['tmp_name'][$i], (string) $name, 'gallery');
        if ($path) {
            $insert->execute([$ownerType, $ownerId, $path, '', ++$sort]);
            $uploaded[] = $path;
        }
    }

    return $uploaded;
}

function gallery_items(string $ownerType, int $ownerId): array
{
    $stmt = db()->prepare("SELECT * FROM gallery_images WHERE owner_type = ? AND owner_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$ownerType, $ownerId]);
    return $stmt->fetchAll();
}

function apply_gallery_order(string $ownerType, int $ownerId, string $order): ?string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $order)))));
    if (!$ids) {
        return null;
    }

    $select = db()->prepare("SELECT id, image_path FROM gallery_images WHERE id = ? AND owner_type = ? AND owner_id = ?");
    $update = db()->prepare("UPDATE gallery_images SET sort_order = ? WHERE id = ?");
    $cover = null;
    $sort = 0;
    foreach ($ids as $id) {
        $select->execute([$id, $ownerType, $ownerId]);
        $row = $select->fetch();
        if (!$row) {
            continue;
        }
        $update->execute([++$sort, $id]);
        $cover ??= (string) $row['image_path'];
    }

    return $cover;
}

function image_src(?string $path): string
{
    return $path ?: 'https://images.unsplash.com/photo-1517457373958-b7bdd4587205?auto=format&fit=crop&w=1200&q=80';
}

function portfolio_url(array $item): string
{
    $slug = trim((string) ($item['slug'] ?? ''));
    if (current_lang() === 'en') {
        $slug = trim((string) ($item['slug_en'] ?? '')) ?: $slug;
    }
    return url_for('/portfolio/' . ($slug !== '' ? $slug : (string) $item['id']));
}

function article_url(array $article): string
{
    $slug = trim((string) ($article['slug'] ?? ''));
    if (current_lang() === 'en') {
        $slug = trim((string) ($article['slug_en'] ?? '')) ?: $slug;
    }
    return url_for('/articles/' . $slug);
}

function short_url(string $type, int $id, ?string $lang = null): string
{
    $lang = $lang ?: current_lang();
    return '/s/' . ($lang === 'en' ? 'en' : 'th') . '/' . $type . '/' . $id;
}

function admin_front_edit_menu(string $editUrl): string
{
    if (!is_admin()) {
        return '';
    }

    ob_start();
    ?>
    <details class="group absolute right-4 top-4 z-20 sm:right-6 sm:top-6">
        <summary class="grid h-11 w-11 cursor-pointer list-none place-items-center rounded-full border border-white/20 bg-white/15 text-white shadow-soft backdrop-blur transition hover:bg-white hover:text-slate-950 [&::-webkit-details-marker]:hidden" aria-label="เมนูจัดการ">
            <i data-lucide="ellipsis" class="h-5 w-5"></i>
        </summary>
        <div class="absolute right-0 mt-2 w-40 overflow-hidden rounded-2xl border border-slate-100 bg-white p-2 text-slate-900 shadow-soft">
            <a href="<?= e($editUrl) ?>" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-extrabold hover:bg-slate-100">
                <i data-lucide="pencil" class="h-4 w-4 text-coral"></i>
                <?= current_lang() === 'en' ? 'Edit' : 'แก้ไข' ?>
            </a>
        </div>
    </details>
    <?php
    return ob_get_clean();
}

function share_panel(string $title, string $shareUrl, string $shortUrl): string
{
    $encodedUrl = rawurlencode($shareUrl);
    $encodedTitle = rawurlencode($title);
    $facebookUrl = 'https://www.facebook.com/sharer/sharer.php?u=' . $encodedUrl;
    $xUrl = 'https://twitter.com/intent/tweet?url=' . $encodedUrl . '&text=' . $encodedTitle;
    $lineUrl = 'https://social-plugins.line.me/lineit/share?url=' . $encodedUrl;
    $shareLabel = current_lang() === 'en' ? 'Share this page' : 'แชร์หน้านี้';
    $copyLabel = current_lang() === 'en' ? 'Copy short link' : 'คัดลอกลิงก์ย่อ';
    $shortLabel = current_lang() === 'en' ? 'Short link' : 'ลิงก์ย่อ';

    ob_start();
    ?>
    <div class="share-panel rounded-[1.5rem] bg-white p-6 shadow-sm ring-1 ring-slate-100">
        <p class="text-xs font-extrabold uppercase tracking-[0.22em] text-coral"><?= e($shareLabel) ?></p>
        <div class="mt-4 grid grid-cols-3 gap-2">
            <a href="<?= e($facebookUrl) ?>" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-2xl bg-[#1877f2] px-3 py-3 text-xs font-extrabold text-white hover:opacity-90">Facebook</a>
            <a href="<?= e($xUrl) ?>" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-3 py-3 text-xs font-extrabold text-white hover:bg-slate-800">X</a>
            <a href="<?= e($lineUrl) ?>" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-2xl bg-[#06c755] px-3 py-3 text-xs font-extrabold text-white hover:opacity-90">LINE</a>
        </div>
        <label class="mt-5 block text-xs font-extrabold uppercase tracking-[0.18em] text-slate-400"><?= e($shortLabel) ?></label>
        <div class="mt-2 flex gap-2">
            <input class="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-600" value="<?= e($shortUrl) ?>" readonly>
            <button type="button" class="copy-link-button inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-slate-950 text-white hover:bg-coral" data-copy-link="<?= e($shortUrl) ?>" aria-label="<?= e($copyLabel) ?>" title="<?= e($copyLabel) ?>">
                <i data-lucide="copy" class="h-4 w-4"></i>
            </button>
        </div>
        <p class="copy-link-status mt-2 hidden text-xs font-bold text-emerald-600"><?= current_lang() === 'en' ? 'Copied' : 'คัดลอกแล้ว' ?></p>
    </div>
    <?php
    return ob_get_clean();
}

function youtube_embed_url(?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return '';
    }

    $host = strtolower((string) $parts['host']);
    $path = trim((string) ($parts['path'] ?? ''), '/');
    $videoId = '';

    if (str_contains($host, 'youtu.be')) {
        $videoId = explode('/', $path)[0] ?? '';
    } elseif (str_contains($host, 'youtube.com')) {
        if ($path === 'watch') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $videoId = (string) ($query['v'] ?? '');
        } elseif (str_starts_with($path, 'embed/')) {
            $videoId = substr($path, 6);
        } elseif (str_starts_with($path, 'shorts/')) {
            $videoId = substr($path, 7);
        }
    }

    $videoId = preg_replace('/[^a-zA-Z0-9_-]/', '', $videoId) ?: '';
    return $videoId !== '' ? 'https://www.youtube.com/embed/' . $videoId : '';
}

function video_section(?string $url, string $title): string
{
    $embedUrl = youtube_embed_url($url);
    if ($embedUrl === '') {
        return '';
    }

    ob_start();
    ?>
    <section class="bg-white px-4 py-14 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="mb-7 max-w-3xl">
                <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral"><?= current_lang() === 'en' ? 'Event recap video' : 'วิดีโอสรุปงาน' ?></p>
                <h2 class="mt-3 text-3xl font-extrabold"><?= e($title) ?></h2>
            </div>
            <div class="overflow-hidden rounded-[2rem] bg-slate-950 shadow-soft">
                <iframe class="aspect-video w-full" src="<?= e($embedUrl) ?>" title="<?= e($title) ?>" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

function gallery_section(array $images, string $title = 'Gallery'): string
{
    if (!$images) {
        return '';
    }

    ob_start();
    $featured = $images[0];
    $rest = array_slice($images, 1);
    ?>
    <section class="px-4 pb-14 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="mb-7 flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                <div>
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">Gallery</p>
                    <h2 class="mt-3 text-3xl font-extrabold"><?= e($title) ?></h2>
                </div>
                <p class="text-sm font-semibold text-slate-500"><?= count($images) ?> <?= e(t('gallery_count')) ?></p>
            </div>
            <div class="grid gap-4 lg:grid-cols-[1.4fr_.9fr]">
                <button type="button" class="gallery-trigger group overflow-hidden rounded-[2rem] bg-slate-100 text-left shadow-soft" data-gallery-group="<?= e(slugify($title)) ?>" data-gallery-index="0" data-image="<?= e($featured['image_path']) ?>" data-caption="<?= e($featured['caption'] ?: $title) ?>">
                    <img src="<?= e($featured['image_path']) ?>" alt="<?= e($featured['caption'] ?: $title) ?>" class="h-[420px] w-full object-cover transition duration-500 group-hover:scale-105">
                </button>
                <div class="grid grid-cols-2 gap-4">
                    <?php foreach (array_slice($rest, 0, 4) as $i => $image): ?>
                        <button type="button" class="gallery-trigger group overflow-hidden rounded-[1.5rem] bg-slate-100 text-left" data-gallery-group="<?= e(slugify($title)) ?>" data-gallery-index="<?= $i + 1 ?>" data-image="<?= e($image['image_path']) ?>" data-caption="<?= e($image['caption'] ?: $title) ?>">
                            <img src="<?= e($image['image_path']) ?>" alt="<?= e($image['caption'] ?: $title) ?>" class="h-48 w-full object-cover transition duration-500 group-hover:scale-105">
                        </button>
                    <?php endforeach; ?>
                    <?php if (count($rest) > 4): ?>
                        <button type="button" class="gallery-trigger relative overflow-hidden rounded-[1.5rem] bg-slate-950 text-white" data-gallery-group="<?= e(slugify($title)) ?>" data-gallery-index="5" data-image="<?= e($rest[4]['image_path']) ?>" data-caption="<?= e($rest[4]['caption'] ?: $title) ?>">
                            <img src="<?= e($rest[4]['image_path']) ?>" alt="<?= e($rest[4]['caption'] ?: $title) ?>" class="h-48 w-full object-cover opacity-45">
                            <span class="absolute inset-0 grid place-items-center text-xl font-extrabold">+<?= count($rest) - 4 ?> ภาพ</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

function contact_links_html(string $mode = 'light'): string
{
    $muted = $mode === 'dark' ? 'text-slate-400 hover:text-white' : 'text-slate-600 hover:text-coral';
    $plain = $mode === 'dark' ? 'text-slate-400' : 'text-slate-600';
    $icon = $mode === 'dark' ? 'text-gold' : 'text-coral';
    $address = current_lang() === 'en' ? '131 Moo 5, Phon Kho Subdistrict, Mueang Sisaket District, Sisaket 33000, Thailand' : '131 หมู่ 5 ตำบลโพนค้อ อำเภอเมืองศรีสะเกษ จังหวัดศรีสะเกษ 33000';
    $pongName = current_lang() === 'en' ? 'K.Pong' : 'คุณป้อง';
    $nutName = current_lang() === 'en' ? 'K.Nut' : 'คุณณัฐฏ์';
    $taxId = '0335564000070';

    ob_start();
    ?>
    <div class="space-y-3 text-sm font-semibold">
        <a class="flex items-start gap-3 <?= $muted ?>" href="mailto:Contact@bigevent.co.th">
            <i data-lucide="mail" class="mt-0.5 h-5 w-5 shrink-0 <?= $icon ?>"></i>
            <span>Contact@bigevent.co.th</span>
        </a>
        <a class="flex items-start gap-3 <?= $muted ?>" href="tel:0616152532">
            <i data-lucide="phone" class="mt-0.5 h-5 w-5 shrink-0 <?= $icon ?>"></i>
            <span>061-615-2532 <?= e($pongName) ?></span>
        </a>
        <a class="flex items-start gap-3 <?= $muted ?>" href="tel:0855549141">
            <i data-lucide="phone" class="mt-0.5 h-5 w-5 shrink-0 <?= $icon ?>"></i>
            <span>085-554-9141 <?= e($nutName) ?></span>
        </a>
        <div class="flex items-start gap-3 <?= $plain ?>">
            <i data-lucide="map-pin" class="mt-0.5 h-5 w-5 shrink-0 <?= $icon ?>"></i>
            <span><?= e($address) ?></span>
        </div>
        <div class="flex items-start gap-3 <?= $plain ?>">
            <i data-lucide="file-badge" class="mt-0.5 h-5 w-5 shrink-0 <?= $icon ?>"></i>
            <span><?= current_lang() === 'en' ? 'Tax ID ' : 'เลขที่เสียภาษี ' ?><?= e($taxId) ?></span>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function all(string $table, string $where = '1=1', string $order = 'sort_order ASC, id DESC'): array
{
    $stmt = db()->query("SELECT * FROM {$table} WHERE {$where} ORDER BY {$order}");
    return $stmt->fetchAll();
}

function find_row(string $table, int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function layout(string $title, callable $content, string $description = '', string $image = ''): void
{
    $lang = current_lang();
    $current = route_path();
    $flash = flash();
    $description = $description ?: t('default_description');
    $siteName = setting('project_name', 'Bigevent Organizer');
    $homeTitle = $lang === 'en' ? 'Home' : 'หน้าแรก';
    $pageTitle = $title === $homeTitle ? ($lang === 'en' ? $siteName . ' | Full-service Event Organizer' : $siteName . ' | รับจัดงานอีเวนต์ครบวงจร') : $title . ' | ' . $siteName;
    if ($pageKey = seo_page_key($current)) {
        $pageTitle = seo_setting($pageKey, 'title', $lang, $pageTitle);
        $description = seo_setting($pageKey, 'description', $lang, $description);
    }
    $canonical = absolute_url(localized_url($lang, $current));
    $thaiUrl = absolute_url(alternate_path('th', $current));
    $englishUrl = absolute_url(alternate_path('en', $current));
    $ogImage = absolute_url($image ?: setting('default_og_image', '/assets/img/og-default.png'));
    $navItems = ['/' => t('home'), '/about' => t('about'), '/services' => t('services'), '/portfolio' => t('portfolio'), '/clients' => t('clients'), '/articles' => t('articles')];
    $adminUser = current_admin();
    $breadcrumbItems = [
        [
            '@type' => 'ListItem',
            'position' => 1,
            'name' => $lang === 'en' ? 'Home' : 'หน้าแรก',
            'item' => absolute_url(localized_url($lang, '/')),
        ],
    ];
    if ($current !== '/') {
        $label = $navItems[$current] ?? $title;
        $breadcrumbItems[] = [
            '@type' => 'ListItem',
            'position' => 2,
            'name' => $label,
            'item' => $canonical,
        ];
    }
    $schema = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => ['Organization', 'LocalBusiness'],
                '@id' => absolute_url('/#organization'),
                'name' => setting('company_name', 'บริษัท บิ๊กอีเว้นท์ จำกัด'),
                'alternateName' => $siteName,
                'url' => absolute_url('/'),
                'email' => setting('admin_contact_email', 'Contact@bigevent.co.th'),
                'telephone' => ['061-615-2532', '085-554-9141'],
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => '131 หมู่ 5 ตำบลโพนค้อ',
                    'addressLocality' => 'อำเภอเมืองศรีสะเกษ',
                    'addressRegion' => 'ศรีสะเกษ',
                    'postalCode' => '33000',
                    'addressCountry' => 'TH',
                ],
                'sameAs' => array_values(array_filter([setting('line_oa_url', LINE_OA_URL)])),
            ],
            [
                '@type' => 'WebSite',
                '@id' => absolute_url('/#website'),
                'url' => absolute_url('/'),
                'name' => 'Bigevent Organizer',
                'publisher' => ['@id' => absolute_url('/#organization')],
                'inLanguage' => $lang === 'en' ? 'en' : 'th-TH',
            ],
            [
                '@type' => 'WebPage',
                '@id' => $canonical . '#webpage',
                'url' => $canonical,
                'name' => $pageTitle,
                'description' => $description,
                'isPartOf' => ['@id' => absolute_url('/#website')],
                'inLanguage' => $lang === 'en' ? 'en' : 'th-TH',
            ],
            [
                '@type' => 'Service',
                '@id' => absolute_url('/services#service'),
                'name' => $lang === 'en' ? 'Full-service Event Organizer' : 'บริการรับจัดงานอีเว้นท์ครบวงจร',
                'serviceType' => ['Event Organizer', 'Corporate Event', 'Product Launch', 'Exhibition', 'Seminar', 'Media Production'],
                'provider' => ['@id' => absolute_url('/#organization')],
                'areaServed' => ['@type' => 'Country', 'name' => 'Thailand'],
                'url' => absolute_url(localized_url($lang, '/services')),
            ],
            [
                '@type' => 'BreadcrumbList',
                '@id' => $canonical . '#breadcrumb',
                'itemListElement' => $breadcrumbItems,
            ],
        ],
    ];
    foreach (schema_extra() as $extra) {
        $schema['@graph'][] = $extra;
    }
    ?>
    <!doctype html>
    <html lang="<?= $lang === 'en' ? 'en' : 'th' ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="<?= e($description) ?>">
        <meta name="robots" content="index, follow, max-image-preview:large">
        <meta name="theme-color" content="#ffffff">
        <link rel="canonical" href="<?= e($canonical) ?>">
        <link rel="alternate" hreflang="th-TH" href="<?= e($thaiUrl) ?>">
        <link rel="alternate" hreflang="en" href="<?= e($englishUrl) ?>">
        <link rel="alternate" hreflang="x-default" href="<?= e($thaiUrl) ?>">
        <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
        <title><?= e($pageTitle) ?></title>
        <meta name="keywords" content="รับจัดงานอีเวนต์, ออแกไนเซอร์, event organizer, product launch, corporate event, exhibition, รับจัดงานบริษัท">
        <meta property="og:locale" content="<?= $lang === 'en' ? 'en_US' : 'th_TH' ?>">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="<?= e($siteName) ?>">
        <meta property="og:title" content="<?= e($pageTitle) ?>">
        <meta property="og:description" content="<?= e($description) ?>">
        <meta property="og:url" content="<?= e($canonical) ?>">
        <meta property="og:image" content="<?= e($ogImage) ?>">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="<?= e($pageTitle) ?>">
        <meta name="twitter:description" content="<?= e($description) ?>">
        <meta name="twitter:image" content="<?= e($ogImage) ?>">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            ink: '#111827',
                            gold: '#c89b3c',
                            coral: '#e15b4f',
                            mist: '#f6f4ef'
                        },
                        boxShadow: {
                            soft: '0 24px 80px rgba(15, 23, 42, 0.12)'
                        }
                    }
                }
            }
        </script>
        <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
        <link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
        <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    </head>
    <body class="flex min-h-screen flex-col bg-mist text-slate-900 antialiased">
        <header id="siteHeader" class="fixed inset-x-0 top-0 z-50 border-b border-slate-200 bg-white shadow-sm">
            <nav class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
                <a href="<?= e(url_for('/')) ?>" class="flex items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
                        <img src="https://www.bigevent.co.th/wp-content/uploads/2024/04/cropped-Big-Event-512p.png" alt="Big Event Logo" class="h-10 w-10 object-contain">
                    </span>
                    <span>
                        <span class="block text-sm font-extrabold tracking-wide">Bigevent</span>
                        <span class="block text-[11px] font-medium text-slate-500">Event Organizer</span>
                    </span>
                </a>
                <div class="hidden items-center gap-7 md:flex">
                    <?php foreach ($navItems as $href => $label): ?>
                        <a class="text-sm font-semibold <?= $current === $href ? 'text-coral' : 'text-slate-600 hover:text-slate-950' ?>" href="<?= e(url_for($href)) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="flex items-center gap-2">
                    <div class="hidden items-center rounded-full bg-slate-100 p-1 text-xs font-extrabold md:flex">
                        <a href="<?= e(localized_url('th', $current)) ?>" class="rounded-full px-3 py-1.5 <?= $lang === 'th' ? 'bg-white text-coral shadow-sm' : 'text-slate-500 hover:text-slate-900' ?>">TH</a>
                        <a href="<?= e(localized_url('en', $current)) ?>" class="rounded-full px-3 py-1.5 <?= $lang === 'en' ? 'bg-white text-coral shadow-sm' : 'text-slate-500 hover:text-slate-900' ?>">EN</a>
                    </div>
                    <?php if ($adminUser): ?>
                        <?= frontend_admin_dropdown($adminUser) ?>
                    <?php else: ?>
                        <button type="button" data-admin-login-open class="hidden rounded-full px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-white md:inline-flex">Admin</button>
                    <?php endif; ?>
                    <a href="<?= e(url_for('/contact')) ?>" class="hidden items-center gap-2 rounded-full bg-slate-950 px-4 py-2 text-sm font-bold text-white shadow-soft hover:bg-coral sm:inline-flex">
                        <i data-lucide="calendar-check" class="h-4 w-4"></i>
                        <?= e(t('quote')) ?>
                    </a>
                    <button id="mobileMenuButton" type="button" class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-950 text-white md:hidden" aria-controls="mobileMenu" aria-expanded="false" aria-label="เปิดเมนู">
                        <i data-lucide="menu" class="h-5 w-5"></i>
                    </button>
                </div>
            </nav>
            <div id="mobileMenu" class="hidden border-t border-slate-100 bg-white px-4 pb-4 shadow-sm md:hidden">
                <div class="mx-auto flex max-w-7xl flex-col gap-2 pt-3">
                    <?php foreach ($navItems as $href => $label): ?>
                        <a class="rounded-2xl px-4 py-3 text-sm font-bold <?= $current === $href ? 'bg-coral/10 text-coral' : 'text-slate-700 hover:bg-slate-100' ?>" href="<?= e(url_for($href)) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                    <div class="grid grid-cols-2 gap-2 pt-2">
                        <a href="<?= e(localized_url('th', $current)) ?>" class="rounded-2xl <?= $lang === 'th' ? 'bg-coral/10 text-coral' : 'bg-slate-100 text-slate-700' ?> px-4 py-3 text-center text-sm font-bold">TH</a>
                        <a href="<?= e(localized_url('en', $current)) ?>" class="rounded-2xl <?= $lang === 'en' ? 'bg-coral/10 text-coral' : 'bg-slate-100 text-slate-700' ?> px-4 py-3 text-center text-sm font-bold">EN</a>
                    </div>
                    <div class="grid grid-cols-2 gap-2 pt-2">
                        <?php if ($adminUser): ?>
                            <?= frontend_admin_dropdown($adminUser, true) ?>
                        <?php else: ?>
                            <button type="button" data-admin-login-open class="rounded-2xl bg-slate-100 px-4 py-3 text-center text-sm font-bold text-slate-700">Admin</button>
                        <?php endif; ?>
                        <a href="<?= e(url_for('/contact')) ?>" class="rounded-2xl bg-slate-950 px-4 py-3 text-center text-sm font-bold text-white"><?= e(t('quote')) ?></a>
                    </div>
                </div>
            </div>
        </header>

        <?php $adminLoginFlash = $flash && (($_GET['admin_login'] ?? '') === '1'); ?>
        <?php if ($flash && !$adminLoginFlash): ?>
            <div class="fixed right-4 top-20 z-[60] rounded-2xl border border-white/70 bg-white px-5 py-3 text-sm font-semibold shadow-soft <?= $flash['type'] === 'error' ? 'text-coral' : 'text-emerald-700' ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <main class="flex-1 pt-16">
            <?php $content(); ?>
        </main>

        <footer class="mt-auto bg-slate-950 px-4 py-12 text-white">
            <div class="mx-auto grid max-w-7xl gap-8 md:grid-cols-2 lg:grid-cols-[1.25fr_.7fr_.9fr_1.05fr]">
                <div>
                    <div class="mb-4 flex items-center gap-3">
                        <span class="grid h-11 w-11 place-items-center overflow-hidden rounded-2xl bg-white">
                            <img src="https://www.bigevent.co.th/wp-content/uploads/2024/04/cropped-Big-Event-512p.png" alt="Big Event Logo" class="h-11 w-11 object-contain">
                        </span>
                        <div>
                            <div class="font-extrabold">Bigevent Organizer</div>
                            <div class="text-sm text-slate-400">Design. Produce. Deliver.</div>
                        </div>
                    </div>
                    <p class="max-w-md text-sm leading-7 text-slate-400"><?= e(t('footer_desc')) ?></p>
                    <div class="mt-5 flex flex-wrap gap-3">
                        <a href="<?= e(setting('line_oa_url', LINE_OA_URL)) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-full bg-[#06c755] px-4 py-2.5 text-sm font-extrabold text-white hover:opacity-90">
                            <i data-lucide="message-circle" class="h-4 w-4"></i>
                            LINE OA
                        </a>
                        <a href="<?= e(url_for('/contact')) ?>" class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2.5 text-sm font-extrabold text-slate-950 hover:bg-gold">
                            <i data-lucide="calendar-check" class="h-4 w-4"></i>
                            <?= e(t('quote')) ?>
                        </a>
                    </div>
                </div>
                <div>
                    <h3 class="mb-3 text-sm font-bold uppercase tracking-wider text-slate-300">Quick Links</h3>
                    <div class="space-y-2 text-sm text-slate-400">
                        <?php foreach (['/about' => t('about'), '/portfolio' => t('portfolio'), '/articles' => t('articles'), '/contact' => t('contact')] as $href => $label): ?>
                            <a href="<?= e(url_for($href)) ?>" class="block transition hover:translate-x-1 hover:text-white"><?= e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <h3 class="mb-3 text-sm font-bold uppercase tracking-wider text-slate-300">Services</h3>
                    <div class="space-y-2 text-sm text-slate-400">
                        <?php foreach ([
                            '#event-organizer' => 'Event Organizer',
                            '#exhibition-seminar' => 'Exhibition & Seminar',
                            '#concert-booth' => 'Concert & Booth',
                            '#media-production' => 'Media Production',
                        ] as $anchor => $service): ?>
                            <a href="<?= e(url_for('/services') . $anchor) ?>" class="block transition hover:translate-x-1 hover:text-white"><?= e($service) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <h3 class="mb-3 text-sm font-bold uppercase tracking-wider text-slate-300">Contact</h3>
                    <?= contact_links_html('dark') ?>
                    <a href="<?= e(setting('google_maps_url', 'https://maps.app.goo.gl/S1gbvFiQm2sfMyjt8')) ?>" target="_blank" rel="noopener" class="mt-4 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-extrabold text-slate-200 hover:bg-white hover:text-slate-950">
                        <i data-lucide="map" class="h-4 w-4"></i>
                        <?= current_lang() === 'en' ? 'Open Google Maps' : 'เปิดแผนที่ Google Maps' ?>
                    </a>
                </div>
            </div>
            <div class="mx-auto mt-10 flex max-w-7xl flex-col gap-3 border-t border-white/10 pt-6 text-xs font-semibold text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                <p>&copy; <?= date('Y') ?> Bigevent Organizer. <?= current_lang() === 'en' ? 'All rights reserved.' : 'สงวนลิขสิทธิ์' ?></p>
                <div class="flex flex-wrap gap-4">
                    <a href="<?= e(url_for('/privacy-policy')) ?>" class="hover:text-white"><?= current_lang() === 'en' ? 'Privacy Policy' : 'นโยบายความเป็นส่วนตัว' ?></a>
                    <a href="<?= e(url_for('/cookie-policy')) ?>" class="hover:text-white"><?= current_lang() === 'en' ? 'Cookie Policy' : 'นโยบายคุกกี้' ?></a>
                </div>
            </div>
        </footer>
        <div id="adminLoginModal" class="fixed inset-0 z-[90] <?= ($_GET['admin_login'] ?? '') === '1' ? '' : 'hidden' ?> bg-slate-950/70 p-4 backdrop-blur-sm">
            <div class="flex min-h-full items-center justify-center">
                <form method="post" action="/admin/login" class="w-full max-w-md rounded-[2rem] bg-white p-6 text-slate-950 shadow-2xl ring-1 ring-white/20 sm:p-8">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_login_modal" value="1">
                    <input type="hidden" name="redirect_to" value="<?= e(path()) ?>">
                    <div class="mb-6 flex items-start justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <span class="grid h-12 w-12 place-items-center rounded-2xl bg-slate-950 text-sm font-black text-white">BE</span>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.25em] text-coral">Admin</p>
                                <h2 class="text-2xl font-extrabold">เข้าสู่ระบบ</h2>
                            </div>
                        </div>
                        <button type="button" data-admin-login-close class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-600 hover:bg-slate-200" aria-label="ปิดหน้าต่างเข้าสู่ระบบ">
                            <i data-lucide="x" class="h-5 w-5"></i>
                        </button>
                    </div>
                    <?php if ($adminLoginFlash): ?>
                        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-red-100 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                            <i data-lucide="alert-circle" class="mt-0.5 h-5 w-5 shrink-0"></i>
                            <span><?= e($flash['message']) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="space-y-4">
                        <div>
                            <label class="admin-label">Email</label>
                            <input class="admin-field" name="email" type="email" placeholder="you@company.com" autocomplete="username" required>
                        </div>
                        <div>
                            <label class="admin-label">Password</label>
                            <input class="admin-field" name="password" type="password" placeholder="••••••••" autocomplete="current-password" required>
                        </div>
                    </div>
                    <button class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-slate-950 px-5 py-4 text-sm font-extrabold text-white hover:bg-coral">
                        เข้าสู่ระบบ <i data-lucide="log-in" class="h-4 w-4"></i>
                    </button>
                    <p class="mt-4 text-center text-xs font-semibold leading-5 text-slate-400">สำหรับผู้ดูแลระบบเท่านั้น</p>
                </form>
            </div>
        </div>
        <div id="bigChatWidget" class="fixed bottom-3 right-3 z-[70] flex max-w-[calc(100vw-1.5rem)] flex-col items-end gap-2 sm:bottom-6 sm:right-6 sm:max-w-[calc(100vw-2rem)] sm:gap-3">
            <div id="bigChatPanel" class="hidden w-[min(20rem,calc(100vw-1.5rem))] overflow-hidden rounded-[1.25rem] border border-white/70 bg-white shadow-soft ring-1 ring-slate-100 sm:w-[min(22rem,calc(100vw-2rem))] sm:rounded-[1.5rem]">
                <div class="bg-slate-950 p-5 text-white">
                    <div class="flex items-start gap-3">
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-gold text-sm font-extrabold text-slate-950">BE</span>
                        <div>
                            <p class="text-sm font-extrabold">น้องบิ๊ก</p>
                            <p class="mt-1 text-sm leading-6 text-slate-300">สวัสดีครับน้องบิ๊กให้บริการ สนใจติดต่อน้องบิ๊กได้เลยครับ</p>
                        </div>
                    </div>
                </div>
                <div class="grid gap-2 p-4">
                    <a href="<?= e(setting('line_oa_url', LINE_OA_URL)) ?>" target="_blank" rel="noopener" class="inline-flex items-center justify-between rounded-2xl bg-[#06c755] px-4 py-3 text-sm font-extrabold text-white hover:opacity-90">
                        <span class="inline-flex items-center gap-2"><i data-lucide="message-circle" class="h-4 w-4"></i> LINE OA</span>
                        <i data-lucide="arrow-up-right" class="h-4 w-4"></i>
                    </a>
                    <a href="tel:0616152532" class="inline-flex items-center justify-between rounded-2xl bg-slate-100 px-4 py-3 text-sm font-extrabold text-slate-900 hover:bg-slate-200">
                        <span class="inline-flex items-center gap-2"><i data-lucide="phone-call" class="h-4 w-4 text-coral"></i> โทรศัพท์</span>
                        <span class="text-xs text-slate-500">061-615-2532</span>
                    </a>
                </div>
            </div>
            <div id="bigChatGreeting" class="max-sm:hidden max-w-72 rounded-[1.35rem] border border-white/80 bg-white px-4 py-3 text-sm font-bold leading-6 text-slate-700 shadow-soft ring-1 ring-slate-100">
                สวัสดีครับน้องบิ๊กให้บริการ สนใจติดต่อน้องบิ๊กได้เลยครับ
            </div>
            <button id="bigChatButton" type="button" class="group relative grid h-14 w-14 place-items-center rounded-full bg-slate-950 text-white shadow-soft ring-2 ring-gold/70 transition hover:-translate-y-1 hover:bg-coral sm:flex sm:h-auto sm:w-auto sm:items-center sm:gap-3 sm:py-2 sm:pl-2 sm:pr-5 sm:ring-1 sm:ring-white/20" aria-controls="bigChatPanel" aria-expanded="false" aria-label="เปิดช่องทางติดต่อน้องบิ๊ก">
                <span class="grid h-11 w-11 place-items-center rounded-full bg-gold text-slate-950 transition group-hover:bg-white sm:h-12 sm:w-12">
                    <i data-lucide="message-circle" class="h-5 w-5 sm:hidden"></i>
                    <span class="hidden text-sm font-extrabold sm:block">BE</span>
                </span>
                <span class="hidden text-sm font-extrabold sm:inline">แชทกับน้องบิ๊ก</span>
                <span class="absolute -right-1 -top-1 h-4 w-4 rounded-full border-2 border-white bg-coral sm:hidden" aria-hidden="true"></span>
            </button>
        </div>
        <div id="cookieConsentBanner" class="fixed inset-x-3 bottom-3 z-[75] hidden rounded-[1.5rem] border border-white/70 bg-white p-4 text-slate-900 shadow-soft ring-1 ring-slate-100 sm:left-6 sm:right-6 sm:bottom-6 lg:left-auto lg:max-w-xl">
            <div class="flex gap-3">
                <div class="hidden h-11 w-11 shrink-0 place-items-center rounded-2xl bg-slate-950 text-white sm:grid">
                    <i data-lucide="cookie" class="h-5 w-5"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-sm font-extrabold"><?= current_lang() === 'en' ? 'Cookie consent' : 'การยินยอมคุกกี้' ?></h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        <?= current_lang() === 'en'
                            ? 'We use necessary cookies for site operation and may use analytics or marketing cookies after your consent.'
                            : 'เว็บไซต์นี้ใช้คุกกี้ที่จำเป็นต่อการทำงาน และอาจใช้คุกกี้วิเคราะห์หรือการตลาดเมื่อคุณยินยอม' ?>
                    </p>
                    <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                        <button type="button" data-cookie-accept="all" class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-4 py-2.5 text-sm font-extrabold text-white hover:bg-coral">
                            <?= current_lang() === 'en' ? 'Accept all' : 'ยอมรับทั้งหมด' ?>
                        </button>
                        <button type="button" data-cookie-accept="necessary" class="inline-flex items-center justify-center rounded-2xl bg-slate-100 px-4 py-2.5 text-sm font-extrabold text-slate-700 hover:bg-slate-200">
                            <?= current_lang() === 'en' ? 'Necessary only' : 'เฉพาะที่จำเป็น' ?>
                        </button>
                        <a href="<?= e(url_for('/cookie-policy')) ?>" class="inline-flex items-center justify-center rounded-2xl px-4 py-2.5 text-sm font-extrabold text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                            <?= current_lang() === 'en' ? 'Cookie policy' : 'อ่านนโยบายคุกกี้' ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div id="galleryLightbox" class="fixed inset-0 z-[80] hidden bg-slate-950/90 p-4 backdrop-blur-sm">
            <button type="button" id="galleryLightboxClose" class="absolute right-4 top-4 grid h-11 w-11 place-items-center rounded-full bg-white text-slate-950 shadow-soft" aria-label="ปิดรูปภาพ">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
            <button type="button" id="galleryLightboxPrev" class="absolute left-4 top-1/2 hidden h-12 w-12 -translate-y-1/2 place-items-center rounded-full bg-white/95 text-slate-950 shadow-soft hover:bg-gold md:grid" aria-label="รูปก่อนหน้า">
                <i data-lucide="chevron-left" class="h-6 w-6"></i>
            </button>
            <button type="button" id="galleryLightboxNext" class="absolute right-4 top-1/2 hidden h-12 w-12 -translate-y-1/2 place-items-center rounded-full bg-white/95 text-slate-950 shadow-soft hover:bg-gold md:grid" aria-label="รูปถัดไป">
                <i data-lucide="chevron-right" class="h-6 w-6"></i>
            </button>
            <div class="flex h-full items-center justify-center">
                <figure class="flex max-h-full w-full max-w-6xl flex-col items-center">
                    <div class="relative flex min-h-0 w-full flex-1 items-center justify-center">
                        <img id="galleryLightboxImage" src="" alt="" class="max-h-[68vh] w-auto rounded-[1.5rem] object-contain shadow-soft">
                    </div>
                    <figcaption class="mt-4 text-center">
                        <div id="galleryLightboxCaption" class="text-sm font-semibold text-white"></div>
                        <div id="galleryLightboxCounter" class="mt-1 text-xs font-bold text-slate-400"></div>
                    </figcaption>
                    <div id="galleryLightboxThumbs" class="mt-5 flex max-w-full gap-3 overflow-x-auto rounded-2xl bg-white/10 p-3 backdrop-blur"></div>
                </figure>
            </div>
        </div>
        <script>
            lucide.createIcons();
            const siteHeader = document.getElementById('siteHeader');
            const mobileMenu = document.getElementById('mobileMenu');
            const mobileMenuButton = document.getElementById('mobileMenuButton');
            const syncHeader = () => {
                siteHeader?.classList.toggle('is-scrolled', window.scrollY > 8);
            };
            mobileMenuButton?.addEventListener('click', () => {
                const isOpen = !mobileMenu?.classList.contains('hidden');
                mobileMenu?.classList.toggle('hidden', isOpen);
                mobileMenuButton.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            });
            const adminLoginModal = document.getElementById('adminLoginModal');
            const setAdminLoginOpen = (open) => {
                adminLoginModal?.classList.toggle('hidden', !open);
                document.body.classList.toggle('overflow-hidden', open);
                if (open) {
                    mobileMenu?.classList.add('hidden');
                    mobileMenuButton?.setAttribute('aria-expanded', 'false');
                    window.setTimeout(() => adminLoginModal?.querySelector('input[name="email"]')?.focus(), 50);
                }
            };
            document.querySelectorAll('[data-admin-login-open]').forEach((button) => {
                button.addEventListener('click', () => setAdminLoginOpen(true));
            });
            document.querySelectorAll('[data-admin-login-close]').forEach((button) => {
                button.addEventListener('click', () => setAdminLoginOpen(false));
            });
            adminLoginModal?.addEventListener('click', (event) => {
                if (event.target === adminLoginModal) setAdminLoginOpen(false);
            });
            const bigChatWidget = document.getElementById('bigChatWidget');
            const bigChatButton = document.getElementById('bigChatButton');
            const bigChatPanel = document.getElementById('bigChatPanel');
            const bigChatGreeting = document.getElementById('bigChatGreeting');
            const cookieConsentBanner = document.getElementById('cookieConsentBanner');
            const cookieConsentKey = 'bigevent_cookie_consent';
            const setBigChatOpen = (open) => {
                bigChatPanel?.classList.toggle('hidden', !open);
                bigChatGreeting?.classList.toggle('hidden', open);
                bigChatButton?.setAttribute('aria-expanded', open ? 'true' : 'false');
            };
            bigChatButton?.addEventListener('click', () => {
                const isOpen = !bigChatPanel?.classList.contains('hidden');
                setBigChatOpen(!isOpen);
            });
            document.addEventListener('click', (event) => {
                if (!bigChatWidget || bigChatPanel?.classList.contains('hidden')) return;
                if (!bigChatWidget.contains(event.target)) setBigChatOpen(false);
            });
            if (cookieConsentBanner && !localStorage.getItem(cookieConsentKey)) {
                cookieConsentBanner.classList.remove('hidden');
            }
            document.querySelectorAll('[data-cookie-accept]').forEach((button) => {
                button.addEventListener('click', () => {
                    const value = button.getAttribute('data-cookie-accept') || 'necessary';
                    const payload = JSON.stringify({ value, acceptedAt: new Date().toISOString() });
                    localStorage.setItem(cookieConsentKey, payload);
                    document.cookie = `${cookieConsentKey}=${encodeURIComponent(value)}; Max-Age=31536000; Path=/; SameSite=Lax`;
                    cookieConsentBanner?.classList.add('hidden');
                });
            });
            const galleryLightbox = document.getElementById('galleryLightbox');
            const galleryLightboxImage = document.getElementById('galleryLightboxImage');
            const galleryLightboxCaption = document.getElementById('galleryLightboxCaption');
            const galleryLightboxCounter = document.getElementById('galleryLightboxCounter');
            const galleryLightboxThumbs = document.getElementById('galleryLightboxThumbs');
            const galleryLightboxPrev = document.getElementById('galleryLightboxPrev');
            const galleryLightboxNext = document.getElementById('galleryLightboxNext');
            const galleryGroups = {};
            let activeGalleryGroup = '';
            let activeGalleryIndex = 0;
            document.querySelectorAll('.gallery-trigger').forEach((button) => {
                const group = button.getAttribute('data-gallery-group') || 'default';
                if (!galleryGroups[group]) galleryGroups[group] = [];
                button.setAttribute('data-gallery-index', String(galleryGroups[group].length));
                galleryGroups[group].push({
                    image: button.getAttribute('data-image') || '',
                    caption: button.getAttribute('data-caption') || '',
                });
            });
            const renderGalleryThumbs = () => {
                if (!galleryLightboxThumbs) return;
                const images = galleryGroups[activeGalleryGroup] || [];
                galleryLightboxThumbs.innerHTML = images.map((item, index) => `
                    <button type="button" class="gallery-thumb h-16 w-20 shrink-0 overflow-hidden rounded-xl ring-2 ${index === activeGalleryIndex ? 'ring-gold' : 'ring-transparent'}" data-index="${index}" aria-label="ดูรูปที่ ${index + 1}">
                        <img src="${item.image}" alt="" class="h-full w-full object-cover">
                    </button>
                `).join('');
                galleryLightboxThumbs.querySelectorAll('.gallery-thumb').forEach((thumb) => {
                    thumb.addEventListener('click', () => {
                        showGalleryImage(Number(thumb.getAttribute('data-index') || 0));
                    });
                });
            };
            const showGalleryImage = (index) => {
                const images = galleryGroups[activeGalleryGroup] || [];
                if (!images.length || !galleryLightboxImage || !galleryLightboxCaption) return;
                activeGalleryIndex = (index + images.length) % images.length;
                const item = images[activeGalleryIndex];
                galleryLightboxImage.src = item.image;
                galleryLightboxImage.alt = item.caption || '';
                galleryLightboxCaption.textContent = item.caption || '';
                if (galleryLightboxCounter) {
                    galleryLightboxCounter.textContent = `${activeGalleryIndex + 1} / ${images.length}`;
                }
                const many = images.length > 1;
                galleryLightboxPrev?.classList.toggle('hidden', !many);
                galleryLightboxNext?.classList.toggle('hidden', !many);
                renderGalleryThumbs();
            };
            const closeGalleryLightbox = () => {
                galleryLightbox?.classList.add('hidden');
                if (galleryLightboxImage) galleryLightboxImage.src = '';
            };
            document.querySelectorAll('.gallery-trigger').forEach((button) => {
                button.addEventListener('click', () => {
                    if (!galleryLightbox) return;
                    activeGalleryGroup = button.getAttribute('data-gallery-group') || 'default';
                    activeGalleryIndex = Number(button.getAttribute('data-gallery-index') || 0);
                    showGalleryImage(activeGalleryIndex);
                    galleryLightbox.classList.remove('hidden');
                });
            });
            galleryLightboxPrev?.addEventListener('click', (event) => {
                event.stopPropagation();
                showGalleryImage(activeGalleryIndex - 1);
            });
            galleryLightboxNext?.addEventListener('click', (event) => {
                event.stopPropagation();
                showGalleryImage(activeGalleryIndex + 1);
            });
            document.getElementById('galleryLightboxClose')?.addEventListener('click', closeGalleryLightbox);
            galleryLightbox?.addEventListener('click', (event) => {
                if (event.target === galleryLightbox) closeGalleryLightbox();
            });
            window.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && adminLoginModal && !adminLoginModal.classList.contains('hidden')) {
                    setAdminLoginOpen(false);
                    return;
                }
                if (galleryLightbox?.classList.contains('hidden')) return;
                if (event.key === 'Escape') closeGalleryLightbox();
                if (event.key === 'ArrowLeft') showGalleryImage(activeGalleryIndex - 1);
                if (event.key === 'ArrowRight') showGalleryImage(activeGalleryIndex + 1);
            });
            document.querySelectorAll('.copy-link-button').forEach((button) => {
                button.addEventListener('click', async () => {
                    const link = button.getAttribute('data-copy-link') || '';
                    const panel = button.closest('.share-panel');
                    const status = panel?.querySelector('.copy-link-status');
                    try {
                        await navigator.clipboard.writeText(link);
                    } catch (error) {
                        const fallback = document.createElement('textarea');
                        fallback.value = link;
                        fallback.setAttribute('readonly', 'readonly');
                        fallback.style.position = 'fixed';
                        fallback.style.left = '-9999px';
                        document.body.appendChild(fallback);
                        fallback.select();
                        document.execCommand('copy');
                        fallback.remove();
                    }
                    status?.classList.remove('hidden');
                    setTimeout(() => status?.classList.add('hidden'), 1800);
                });
            });
            document.querySelectorAll('[data-home-hero]').forEach((hero) => {
                const slides = Array.from(hero.querySelectorAll('[data-home-hero-slide]'));
                const copies = Array.from(hero.querySelectorAll('[data-home-hero-copy]'));
                const dots = Array.from(hero.querySelectorAll('[data-home-hero-dot]'));
                const strip = hero.querySelector('[data-home-work-strip]');
                let current = 0;
                let timer = null;
                const show = (index) => {
                    if (!slides.length) return;
                    current = (index + slides.length) % slides.length;
                    slides.forEach((slide, i) => {
                        slide.classList.toggle('opacity-100', i === current);
                        slide.classList.toggle('opacity-0', i !== current);
                    });
                    copies.forEach((copy, i) => copy.classList.toggle('hidden', i !== current));
                    dots.forEach((dot, i) => {
                        dot.classList.toggle('w-10', i === current);
                        dot.classList.toggle('w-2.5', i !== current);
                        dot.classList.toggle('bg-white', i === current);
                        dot.classList.toggle('bg-white/40', i !== current);
                    });
                };
                const restart = () => {
                    if (timer) window.clearInterval(timer);
                    if (slides.length > 1) {
                        timer = window.setInterval(() => show(current + 1), 6500);
                    }
                };
                hero.querySelector('[data-home-hero-prev]')?.addEventListener('click', () => {
                    show(current - 1);
                    restart();
                });
                hero.querySelector('[data-home-hero-next]')?.addEventListener('click', () => {
                    show(current + 1);
                    restart();
                });
                dots.forEach((dot) => dot.addEventListener('click', () => {
                    show(Number(dot.dataset.homeHeroDot || 0));
                    restart();
                }));
                hero.querySelector('[data-home-strip-prev]')?.addEventListener('click', () => strip?.scrollBy({ left: -320, behavior: 'smooth' }));
                hero.querySelector('[data-home-strip-next]')?.addEventListener('click', () => strip?.scrollBy({ left: 320, behavior: 'smooth' }));
                restart();
            });
            syncHeader();
            window.addEventListener('scroll', syncHeader, { passive: true });
        </script>
    </body>
    </html>
    <?php
}

function admin_layout(string $title, callable $content): void
{
    require_admin();
    $current = path();
    $flash = flash();
    $admin = current_admin();
    $menu = [
        '/admin' => ['ภาพรวม', 'layout-dashboard'],
        '/admin/banners' => ['แบนเนอร์', 'image'],
        '/admin/portfolio' => ['ผลงาน', 'briefcase-business'],
        '/admin/clients' => ['โลโก้ลูกค้า', 'handshake'],
        '/admin/articles' => ['บทความ', 'newspaper'],
        '/admin/crm' => ['CRM ลูกค้า', 'message-square-text'],
        '/admin/tools' => ['เครื่องมือ', 'wrench'],
        '/admin/users' => ['ผู้ใช้งาน', 'users'],
        '/admin/backup' => ['Backup', 'database-backup'],
    ];
    if (($admin['role'] ?? '') === 'super_admin') {
        $menu['/admin/settings'] = ['ตั้งค่า', 'settings'];
    }
    $newCrmCount = crm_new_count();
    $needsPasswordChange = default_admin_password_is_active();
    ?>
    <!doctype html>
    <html lang="th">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> | Admin</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
        <link rel="stylesheet" href="/assets/css/app.css">
    </head>
    <body class="bg-slate-100 text-slate-900 antialiased">
        <div class="min-h-screen lg:grid lg:grid-cols-[260px_minmax(0,1fr)] xl:grid-cols-[280px_minmax(0,1fr)]">
            <aside class="border-b border-slate-200 bg-white lg:sticky lg:top-0 lg:min-h-screen lg:self-start lg:border-b-0 lg:border-r">
                <div class="flex items-center justify-between px-5 py-5">
                    <a href="/admin" class="flex min-w-0 items-center gap-3">
                        <?= admin_avatar_html($admin, 'h-11 w-11') ?>
                        <span class="min-w-0">
                            <span class="block truncate font-extrabold"><?= e(admin_display_name($admin)) ?></span>
                            <span class="block truncate text-xs text-slate-500"><?= e(role_label((string) ($admin['role'] ?? 'manager'))) ?></span>
                        </span>
                    </a>
                    <a href="/" class="shrink-0 rounded-full bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-200">ดูเว็บ</a>
                </div>
                <nav class="flex gap-2 overflow-x-auto px-4 pb-4 lg:block lg:space-y-1 lg:overflow-visible">
                    <?php foreach ($menu as $href => [$label, $icon]): ?>
                        <?php $active = $current === $href || ($href !== '/admin' && str_starts_with($current, $href)); ?>
                        <a href="<?= $href ?>" class="flex shrink-0 items-center gap-3 rounded-2xl px-4 py-3 text-sm font-bold lg:w-full <?= $active ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-100' ?>">
                            <i data-lucide="<?= $icon ?>" class="h-4 w-4 shrink-0"></i>
                            <span class="truncate"><?= $label ?></span>
                            <?php if ($href === '/admin/crm'): ?>
                                <?= crm_notification_badge($newCrmCount, $active ? 'ml-auto ring-slate-950' : 'ml-auto') ?>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                    <form method="post" action="/admin/logout" class="lg:pt-6">
                        <?= csrf_field() ?>
                        <button class="flex w-full items-center gap-3 rounded-2xl px-4 py-3 text-sm font-bold text-red-600 hover:bg-red-50">
                            <i data-lucide="log-out" class="h-4 w-4 shrink-0"></i>
                            <span class="truncate">ออกจากระบบ</span>
                        </button>
                    </form>
                </nav>
            </aside>
            <section class="min-w-0">
                <header class="border-b border-slate-200 bg-white px-4 py-5 sm:px-6 lg:px-8">
                    <div class="flex w-full min-w-0 items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Bigevent CMS</p>
                            <h1 class="mt-1 truncate text-2xl font-extrabold"><?= e($title) ?></h1>
                        </div>
                        <div class="hidden shrink-0 items-center gap-3 sm:flex">
                            <div class="text-right">
                                <div class="max-w-56 truncate text-sm font-extrabold"><?= e(admin_display_name($admin)) ?></div>
                                <div class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-bold ring-1 <?= e(role_badge_class((string) ($admin['role'] ?? 'manager'))) ?>">
                                    <?= e(role_label((string) ($admin['role'] ?? 'manager'))) ?>
                                </div>
                            </div>
                            <?= admin_avatar_html($admin, 'h-11 w-11') ?>
                        </div>
                    </div>
                </header>
                <?php if ($flash): ?>
                    <div class="mt-6 px-4 sm:px-6 lg:px-8">
                        <div class="rounded-2xl border bg-white px-5 py-4 text-sm font-semibold shadow-sm <?= $flash['type'] === 'error' ? 'border-red-100 text-red-700' : 'border-emerald-100 text-emerald-700' ?>">
                            <?= e($flash['message']) ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($needsPasswordChange): ?>
                    <div class="mt-6 px-4 sm:px-6 lg:px-8">
                        <div class="flex flex-col gap-3 rounded-2xl border border-amber-100 bg-amber-50 px-5 py-4 text-sm font-bold text-amber-800 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                            <span>ระบบยังใช้รหัสผ่านเริ่มต้นอยู่ ควรเปลี่ยนก่อนขึ้น Production</span>
                            <a href="/admin/account/password" class="rounded-xl bg-amber-600 px-4 py-2 text-center text-xs font-extrabold text-white hover:bg-amber-700">เปลี่ยนรหัสผ่าน</a>
                        </div>
                    </div>
                <?php endif; ?>
                <main class="w-full min-w-0 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    <?php $content(); ?>
                </main>
            </section>
        </div>
        <script>
            lucide.createIcons();
            window.confirmBulkDelete = (form, label) => {
                const checked = document.querySelectorAll(`input[form="${form.id}"][name="ids[]"]:checked`).length;
                if (!checked) {
                    alert('กรุณาเลือกรายการก่อนลบ');
                    return false;
                }
                return confirm(`ยืนยันการลบ${label} ${checked} รายการ?`);
            };
            document.querySelectorAll('.bulk-select-all').forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    document.querySelectorAll(checkbox.dataset.target || '').forEach((item) => {
                        item.checked = checkbox.checked;
                    });
                });
            });
            document.querySelectorAll('.sortable-logo-list').forEach((list) => {
                let dragging = null;
                const syncOrder = () => {
                    const input = document.getElementById('clientOrderInput');
                    if (!input) return;
                    input.value = Array.from(list.querySelectorAll('[data-sort-id]')).map((row) => row.dataset.sortId).join(',');
                };
                list.querySelectorAll('[draggable="true"]').forEach((row) => {
                    row.querySelector('.move-logo-up')?.addEventListener('click', () => {
                        const previous = row.previousElementSibling;
                        if (previous) list.insertBefore(row, previous);
                        syncOrder();
                    });
                    row.querySelector('.move-logo-down')?.addEventListener('click', () => {
                        const next = row.nextElementSibling;
                        if (next) list.insertBefore(next, row);
                        syncOrder();
                    });
                    row.addEventListener('dragstart', (event) => {
                        dragging = row;
                        row.classList.add('opacity-50');
                        event.dataTransfer.effectAllowed = 'move';
                    });
                    row.addEventListener('dragend', () => {
                        row.classList.remove('opacity-50');
                        dragging = null;
                        syncOrder();
                    });
                    row.addEventListener('dragover', (event) => {
                        event.preventDefault();
                        if (!dragging || dragging === row) return;
                        const rect = row.getBoundingClientRect();
                        const after = event.clientY > rect.top + rect.height / 2;
                        list.insertBefore(dragging, after ? row.nextSibling : row);
                    });
                });
                syncOrder();
            });
            document.querySelectorAll('.admin-upload-zone').forEach((zone) => {
                const input = zone.querySelector('.admin-upload-input');
                const preview = zone.querySelector('.admin-upload-preview');
                const renderFiles = () => {
                    if (!input || !preview) return;
                    const files = Array.from(input.files || []);
                    preview.innerHTML = '';
                    preview.classList.toggle('hidden', files.length === 0);
                    files.forEach((file, index) => {
                        const item = document.createElement('div');
                        item.className = 'relative overflow-hidden rounded-2xl bg-slate-100 ring-1 ring-slate-200';
                        const img = document.createElement('img');
                        img.className = 'h-28 w-full object-cover';
                        img.src = URL.createObjectURL(file);
                        img.onload = () => URL.revokeObjectURL(img.src);
                        const badge = document.createElement('span');
                        badge.className = `absolute left-2 top-2 rounded-lg px-2 py-1 text-xs font-extrabold text-white ${index === 0 && zone.dataset.coverFirst === '1' ? 'bg-coral' : 'bg-slate-950/70'}`;
                        badge.textContent = index === 0 && zone.dataset.coverFirst === '1' ? 'ปก' : `#${index + 1}`;
                        item.append(img, badge);
                        preview.appendChild(item);
                    });
                };
                input?.addEventListener('change', renderFiles);
                ['dragenter', 'dragover'].forEach((eventName) => {
                    zone.addEventListener(eventName, (event) => {
                        event.preventDefault();
                        zone.classList.add('border-coral', 'bg-coral/5');
                    });
                });
                ['dragleave', 'drop'].forEach((eventName) => {
                    zone.addEventListener(eventName, (event) => {
                        event.preventDefault();
                        zone.classList.remove('border-coral', 'bg-coral/5');
                    });
                });
                zone.addEventListener('drop', (event) => {
                    if (!input || !event.dataTransfer?.files?.length) return;
                    input.files = event.dataTransfer.files;
                    renderFiles();
                });
            });
            document.querySelectorAll('.portfolio-gallery-sortable').forEach((grid) => {
                let dragging = null;
                const wrapper = grid.closest('section');
                const input = wrapper?.querySelector('.portfolio-gallery-order');
                const sync = () => {
                    const items = Array.from(grid.querySelectorAll('[data-gallery-id]'));
                    if (input) input.value = items.map((item) => item.dataset.galleryId).join(',');
                    Array.from(grid.children).forEach((item, index) => {
                        const cover = item.querySelector('.cover-badge');
                        const order = item.querySelector('.order-badge');
                        if (cover) {
                            cover.textContent = index === 0 ? 'ปก' : 'Gallery';
                            cover.className = `cover-badge rounded-xl px-3 py-1 text-xs font-extrabold shadow-sm ${index === 0 ? 'bg-coral text-white' : 'bg-white/90 text-slate-600'}`;
                        }
                        if (order) order.textContent = `#${index + 1}`;
                    });
                };
                grid.querySelectorAll('[draggable="true"]').forEach((item) => {
                    item.addEventListener('dragstart', (event) => {
                        dragging = item;
                        item.classList.add('opacity-50');
                        event.dataTransfer.effectAllowed = 'move';
                    });
                    item.addEventListener('dragend', () => {
                        item.classList.remove('opacity-50');
                        dragging = null;
                        sync();
                    });
                    item.addEventListener('dragover', (event) => {
                        event.preventDefault();
                        if (!dragging || dragging === item) return;
                        const rect = item.getBoundingClientRect();
                        const after = event.clientX > rect.left + rect.width / 2;
                        grid.insertBefore(dragging, after ? item.nextSibling : item);
                    });
                });
                sync();
            });
            const updateGalleryCount = (scope, count) => {
                const badge = scope?.querySelector('[data-gallery-count]');
                if (badge && Number.isFinite(count)) badge.textContent = `${count} รูป`;
            };
            const renumberGalleryCards = (scope) => {
                const cards = Array.from(scope?.querySelectorAll('[data-gallery-card]') || []);
                cards.forEach((card, index) => {
                    const order = card.querySelector('.order-badge');
                    if (order) order.textContent = `#${index + 1}`;
                    const cover = card.querySelector('.cover-badge');
                    if (cover) {
                        cover.textContent = index === 0 ? 'ปก' : 'Gallery';
                        cover.className = `cover-badge rounded-xl px-3 py-1 text-xs font-extrabold shadow-sm ${index === 0 ? 'bg-coral text-white' : 'bg-white/90 text-slate-600'}`;
                    }
                    const simpleNumber = card.querySelector('.text-xs.font-semibold.text-slate-500');
                    if (simpleNumber && simpleNumber.textContent.trim().startsWith('#')) {
                        simpleNumber.textContent = `#${index + 1}`;
                    }
                });
                const orderInput = scope?.querySelector('.portfolio-gallery-order');
                if (orderInput) {
                    orderInput.value = cards.map((card) => card.dataset.galleryId).filter(Boolean).join(',');
                }
            };
            const showGalleryDeleteNotice = (scope, message, isError = false) => {
                if (!scope) return;
                scope.querySelector('[data-gallery-delete-notice]')?.remove();
                const notice = document.createElement('div');
                notice.dataset.galleryDeleteNotice = '1';
                notice.className = `mt-4 rounded-2xl px-4 py-3 text-sm font-extrabold ${isError ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'}`;
                notice.textContent = message;
                const uploadZone = scope.querySelector('.admin-upload-zone');
                scope.insertBefore(notice, uploadZone || null);
                window.setTimeout(() => notice.remove(), 2600);
            };
            document.querySelectorAll('[data-gallery-delete-form]').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const id = form.dataset.galleryId || form.querySelector('input[name="id"]')?.value || '';
                    const card = document.querySelector(`[data-gallery-card][data-gallery-id="${CSS.escape(id)}"]`);
                    const scope = card?.closest('section') || document;
                    const submitButton = form.id ? document.querySelector(`[form="${CSS.escape(form.id)}"]`) : null;
                    submitButton?.setAttribute('disabled', 'disabled');
                    submitButton?.classList.add('opacity-60', 'cursor-wait');
                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form),
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok || !data.ok) {
                            throw new Error(data.message || 'ลบรูปไม่สำเร็จ');
                        }
                        card?.classList.add('scale-95', 'opacity-0');
                        window.setTimeout(() => {
                            card?.remove();
                            form.remove();
                            renumberGalleryCards(scope);
                            updateGalleryCount(scope, scope.querySelectorAll('[data-gallery-card]').length);
                            showGalleryDeleteNotice(scope, data.message || 'ลบรูปเรียบร้อยแล้ว');
                        }, 180);
                    } catch (error) {
                        showGalleryDeleteNotice(scope, error.message || 'ลบรูปไม่สำเร็จ', true);
                        submitButton?.removeAttribute('disabled');
                        submitButton?.classList.remove('opacity-60', 'cursor-wait');
                    }
                });
            });
            document.querySelectorAll('[data-home-hero]').forEach((hero) => {
                const slides = Array.from(hero.querySelectorAll('[data-home-hero-slide]'));
                const copies = Array.from(hero.querySelectorAll('[data-home-hero-copy]'));
                const dots = Array.from(hero.querySelectorAll('[data-home-hero-dot]'));
                const strip = hero.querySelector('[data-home-work-strip]');
                let current = 0;
                const show = (index) => {
                    if (!slides.length) return;
                    current = (index + slides.length) % slides.length;
                    slides.forEach((slide, i) => {
                        slide.classList.toggle('opacity-100', i === current);
                        slide.classList.toggle('opacity-0', i !== current);
                    });
                    copies.forEach((copy, i) => copy.classList.toggle('hidden', i !== current));
                    dots.forEach((dot, i) => {
                        dot.classList.toggle('w-10', i === current);
                        dot.classList.toggle('w-2.5', i !== current);
                        dot.classList.toggle('bg-white', i === current);
                        dot.classList.toggle('bg-white/40', i !== current);
                    });
                };
                hero.querySelector('[data-home-hero-prev]')?.addEventListener('click', () => show(current - 1));
                hero.querySelector('[data-home-hero-next]')?.addEventListener('click', () => show(current + 1));
                dots.forEach((dot) => dot.addEventListener('click', () => show(Number(dot.dataset.homeHeroDot || 0))));
                hero.querySelector('[data-home-strip-prev]')?.addEventListener('click', () => strip?.scrollBy({ left: -320, behavior: 'smooth' }));
                hero.querySelector('[data-home-strip-next]')?.addEventListener('click', () => strip?.scrollBy({ left: 320, behavior: 'smooth' }));
                if (slides.length > 1) {
                    window.setInterval(() => show(current + 1), 6500);
                }
            });
            document.querySelectorAll('[data-seo-panel]').forEach((panel) => {
                const titleInput = panel.querySelector('[name="seo_title"]');
                const fallbackTitle = document.querySelector('[name="title"]');
                const descriptionInput = panel.querySelector('[name="meta_description"]');
                const fallbackDescription = document.querySelector('[name="excerpt"], [name="description"]');
                const focusInput = panel.querySelector('[name="seo_focus_keyphrase"]');
                const previewTitle = panel.querySelector('[data-seo-preview-title]');
                const previewDescription = panel.querySelector('[data-seo-preview-description]');
                const titleCount = panel.querySelector('[data-seo-title-count]');
                const descriptionCount = panel.querySelector('[data-seo-description-count]');
                const focusState = panel.querySelector('[data-seo-focus-state]');
                const setStatusClass = (el, ok) => {
                    if (!el) return;
                    el.classList.toggle('text-emerald-600', ok);
                    el.classList.toggle('text-amber-600', !ok);
                };
                const updateSeoPreview = () => {
                    const title = (titleInput?.value || fallbackTitle?.value || 'SEO Title').trim();
                    const description = (descriptionInput?.value || fallbackDescription?.value || 'Meta description จะแสดงตัวอย่างคำอธิบายผลค้นหาตรงนี้').trim();
                    const focus = (focusInput?.value || '').trim();
                    if (previewTitle) previewTitle.textContent = title;
                    if (previewDescription) previewDescription.textContent = description;
                    if (titleCount) {
                        titleCount.textContent = `${Array.from(title).length} ตัวอักษร`;
                        setStatusClass(titleCount, Array.from(title).length >= 35 && Array.from(title).length <= 70);
                    }
                    if (descriptionCount) {
                        descriptionCount.textContent = `${Array.from(description).length} ตัวอักษร`;
                        setStatusClass(descriptionCount, Array.from(description).length >= 90 && Array.from(description).length <= 170);
                    }
                    if (focusState) {
                        focusState.textContent = focus ? 'มีแล้ว' : 'ยังไม่ได้ใส่';
                        setStatusClass(focusState, Boolean(focus));
                    }
                };
                [titleInput, fallbackTitle, descriptionInput, fallbackDescription, focusInput].forEach((input) => {
                    input?.addEventListener('input', updateSeoPreview);
                });
                updateSeoPreview();
            });
        </script>
    </body>
    </html>
    <?php
}

function home_page(): void
{
    $banner = all('banners', 'is_active = 1', 'sort_order ASC, id DESC')[0] ?? null;
    $portfolios = all('portfolios', 'is_featured = 1', 'sort_order ASC, id DESC');
    $clients = all('clients', 'is_active = 1', 'sort_order ASC, id DESC');
    $articles = all('articles', 'is_published = 1', 'published_at DESC, id DESC');
    $lang = current_lang();
    layout($lang === 'en' ? 'Home' : 'หน้าแรก', function () use ($banner, $portfolios, $clients, $articles) {
        $heroSlides = array_slice($portfolios, 0, 5);
        if (!$heroSlides && $banner) {
            $heroSlides = [[
                'title' => $banner['title'] ?? '',
                'title_en' => $banner['title_en'] ?? '',
                'category' => 'Full-service Event Organizer',
                'category_en' => 'Full-service Event Organizer',
                'description' => $banner['subtitle'] ?? '',
                'description_en' => $banner['subtitle_en'] ?? '',
                'image_path' => $banner['image_path'] ?? '',
                'slug' => '',
                'slug_en' => '',
            ]];
        }
        ?>
        <section class="relative min-h-[calc(100vh-4rem)] overflow-hidden bg-slate-950 text-white" data-home-hero>
            <div class="absolute inset-0">
                <?php foreach ($heroSlides as $index => $slide): ?>
                    <div class="absolute inset-0 transition-opacity duration-700 <?= $index === 0 ? 'opacity-100' : 'opacity-0' ?>" data-home-hero-slide>
                        <img src="<?= e(image_src($slide['image_path'] ?? null)) ?>" alt="<?= e(localized($slide, 'title')) ?>" class="h-full w-full object-cover">
                    </div>
                <?php endforeach; ?>
                <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(2,6,23,.94)_0%,rgba(2,6,23,.74)_38%,rgba(2,6,23,.18)_70%),linear-gradient(0deg,rgba(2,6,23,.82),rgba(2,6,23,.06)_46%,rgba(2,6,23,.42))]"></div>
                <div class="absolute inset-x-0 bottom-0 h-64 bg-gradient-to-t from-slate-950 via-slate-950/55 to-transparent"></div>
            </div>
            <button type="button" class="absolute left-4 top-1/2 z-20 hidden h-12 w-12 -translate-y-1/2 place-items-center rounded-full border border-white/20 bg-white/10 backdrop-blur hover:bg-white/20 md:grid" data-home-hero-prev aria-label="Previous slide">
                <i data-lucide="chevron-left" class="h-7 w-7"></i>
            </button>
            <button type="button" class="absolute right-4 top-1/2 z-20 hidden h-12 w-12 -translate-y-1/2 place-items-center rounded-full border border-white/20 bg-white/10 backdrop-blur hover:bg-white/20 md:grid" data-home-hero-next aria-label="Next slide">
                <i data-lucide="chevron-right" class="h-7 w-7"></i>
            </button>
            <div class="relative z-10 mx-auto flex min-h-[calc(100vh-4rem)] max-w-7xl flex-col justify-end px-4 pb-10 pt-24 sm:px-6 lg:px-8 lg:pb-12">
                <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_430px] lg:items-end">
                    <div class="max-w-4xl">
                        <?php foreach ($heroSlides as $index => $slide): ?>
                            <?php $slideUrl = !empty($slide['id']) ? portfolio_url($slide) : url_for($banner['cta_url'] ?? '/portfolio'); ?>
                            <div class="<?= $index === 0 ? '' : 'hidden' ?>" data-home-hero-copy>
                                <div class="mb-5 flex flex-wrap gap-2">
                                    <span class="inline-flex items-center rounded-lg bg-white/12 px-3 py-1.5 text-sm font-bold backdrop-blur"><?= e(localized($slide, 'category') ?: 'Full-service Event Organizer') ?></span>
                                    <span class="inline-flex items-center rounded-lg bg-white/12 px-3 py-1.5 text-sm font-bold backdrop-blur"><?= current_lang() === 'en' ? 'Featured Work' : 'ผลงานเด่น' ?></span>
                                </div>
                                <h1 class="max-w-4xl text-4xl font-extrabold leading-tight tracking-normal sm:text-6xl lg:text-7xl"><?= e(localized($slide, 'title') ?: localized($banner ?? [], 'title')) ?></h1>
                                <p class="mt-5 max-w-2xl text-base leading-8 text-slate-100 sm:text-lg"><?= e(localized($slide, 'description') ?: localized($banner ?? [], 'subtitle')) ?></p>
                                <div class="mt-8 flex flex-wrap gap-3">
                                    <a href="<?= e($slideUrl) ?>" class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-extrabold text-slate-950 shadow-soft hover:bg-gold">
                                        <i data-lucide="play" class="h-4 w-4 fill-current"></i>
                                        <?= current_lang() === 'en' ? 'View This Work' : 'ดูผลงานนี้' ?>
                                    </a>
                                    <a href="<?= e(url_for('/portfolio')) ?>" class="inline-flex items-center gap-2 rounded-xl border border-white/25 bg-white/10 px-5 py-3 text-sm font-extrabold backdrop-blur hover:bg-white/15">
                                        <i data-lucide="bookmark" class="h-4 w-4"></i>
                                        <?= e(t('view_all')) ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="mt-8 flex items-center gap-2" data-home-hero-dots>
                            <?php foreach ($heroSlides as $index => $_): ?>
                                <button type="button" class="h-2.5 rounded-full transition-all <?= $index === 0 ? 'w-10 bg-white' : 'w-2.5 bg-white/40' ?>" data-home-hero-dot="<?= $index ?>" aria-label="Go to slide <?= $index + 1 ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="hidden rounded-[1.75rem] border border-white/15 bg-white/10 p-5 shadow-soft backdrop-blur-xl lg:block">
                        <p class="text-xs font-extrabold uppercase tracking-[0.24em] text-gold"><?= current_lang() === 'en' ? 'Event production stack' : 'ระบบงานครบสำหรับอีเวนต์' ?></p>
                        <div class="mt-5 space-y-3">
                            <?php foreach (current_lang() === 'en' ? [['Creative', 'Concept, content direction and storytelling'], ['Production', 'Stage, lighting, sound and technical crew'], ['Operation', 'Registration, supplier and front-of-house flow']] : [['Creative', 'คอนเซ็ปต์ คอนเทนต์ และเรื่องเล่าของงาน'], ['Production', 'เวที แสง สี เสียง และทีมเทคนิค'], ['Operation', 'ลงทะเบียน ซัพพลายเออร์ และ flow หน้างาน']] as [$name, $desc]): ?>
                                <div class="rounded-2xl border border-white/10 bg-slate-950/35 p-4">
                                    <div class="font-extrabold"><?= e($name) ?></div>
                                    <p class="mt-1 text-sm leading-6 text-slate-300"><?= e($desc) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php if ($portfolios): ?>
                    <div class="mt-12">
                        <div class="mb-4 flex items-center justify-between gap-4">
                            <h2 class="text-2xl font-extrabold"><?= current_lang() === 'en' ? 'Trending works' : 'ผลงานที่กำลังมาแรง' ?></h2>
                            <div class="hidden gap-2 sm:flex">
                                <button type="button" class="grid h-10 w-10 place-items-center rounded-full bg-white/10 backdrop-blur hover:bg-white/20" data-home-strip-prev aria-label="Scroll works left"><i data-lucide="chevron-left" class="h-5 w-5"></i></button>
                                <button type="button" class="grid h-10 w-10 place-items-center rounded-full bg-white/10 backdrop-blur hover:bg-white/20" data-home-strip-next aria-label="Scroll works right"><i data-lucide="chevron-right" class="h-5 w-5"></i></button>
                            </div>
                        </div>
                        <div class="-mx-4 flex snap-x gap-4 overflow-x-auto px-4 pb-3 [scrollbar-width:none] sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8" data-home-work-strip>
                            <?php foreach ($portfolios as $item): ?>
                                <a href="<?= portfolio_url($item) ?>" class="group relative h-52 w-52 shrink-0 snap-start overflow-hidden rounded-2xl bg-slate-800 shadow-soft ring-1 ring-white/10 sm:h-60 sm:w-72">
                                    <img src="<?= e(image_src($item['image_path'])) ?>" alt="<?= e(localized($item, 'title')) ?>" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/88 via-slate-950/20 to-transparent"></div>
                                    <div class="absolute inset-x-0 bottom-0 p-4">
                                        <p class="line-clamp-1 text-xs font-extrabold uppercase tracking-wider text-gold"><?= e(localized($item, 'category')) ?></p>
                                        <h3 class="mt-1 line-clamp-2 text-base font-extrabold leading-snug"><?= e(localized($item, 'title')) ?></h3>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="hidden">
            <img src="<?= e(image_src($banner['image_path'] ?? null)) ?>" alt="Event hero" class="absolute inset-0 h-full w-full object-cover opacity-70">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_78%_22%,rgba(200,155,60,.42),transparent_28%),radial-gradient(circle_at_20%_75%,rgba(20,184,166,.24),transparent_26%),linear-gradient(105deg,rgba(2,6,23,.96),rgba(15,23,42,.80)_48%,rgba(127,29,29,.54))]"></div>
            <div class="absolute inset-x-0 bottom-0 h-40 bg-gradient-to-t from-slate-950/85 to-transparent"></div>
            <div class="relative mx-auto flex min-h-[calc(100vh-4rem)] max-w-7xl items-center px-4 py-20 sm:px-6 lg:px-8">
                <div class="grid w-full gap-10 lg:grid-cols-[minmax(0,1fr)_420px] lg:items-end">
                    <div class="max-w-4xl text-white">
                        <div class="mb-6 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm font-semibold backdrop-blur">
                            <span class="h-2 w-2 rounded-full bg-gold"></span>
                            Full-service Event Organizer
                        </div>
                        <h1 class="max-w-4xl text-5xl font-extrabold leading-tight tracking-normal sm:text-7xl"><?= e($banner ? localized($banner, 'title') : 'ออกแบบอีเวนต์ให้แบรนด์ของคุณถูกจดจำ') ?></h1>
                        <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-100"><?= e($banner ? localized($banner, 'subtitle') : '') ?></p>
                        <div class="mt-9 flex flex-wrap gap-3">
                            <a href="<?= e(url_for($banner['cta_url'] ?? '/portfolio')) ?>" class="inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 text-sm font-extrabold text-slate-950 shadow-soft hover:bg-gold">
                                <?= e($banner ? localized($banner, 'cta_label') : t('view_work')) ?>
                                <i data-lucide="arrow-right" class="h-4 w-4"></i>
                            </a>
                            <a href="<?= e(url_for('/contact')) ?>" class="inline-flex items-center gap-2 rounded-full border border-white/30 bg-white/10 px-6 py-3 text-sm font-extrabold text-white backdrop-blur hover:bg-white/15">
                                <?= e(t('talk_project')) ?>
                            </a>
                        </div>
                        <div class="mt-10 grid max-w-2xl grid-cols-3 gap-3">
                            <?php foreach (current_lang() === 'en' ? [['7+', 'real projects'], ['360°', 'event service'], ['2', 'language SEO']] : [['7+', 'ผลงานจริง'], ['360°', 'บริการครบวงจร'], ['2', 'ภาษา SEO']] as [$num, $label]): ?>
                                <div class="border-l border-white/20 pl-4">
                                    <div class="text-3xl font-extrabold text-white"><?= e($num) ?></div>
                                    <div class="mt-1 text-xs font-bold uppercase tracking-wide text-slate-300"><?= e($label) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="hidden rounded-[1.75rem] border border-white/15 bg-white/10 p-5 text-white shadow-soft backdrop-blur-xl lg:block">
                        <p class="text-xs font-extrabold uppercase tracking-[0.24em] text-gold"><?= current_lang() === 'en' ? 'Built for event impact' : 'ออกแบบเพื่อให้งานมีแรงส่ง' ?></p>
                        <div class="mt-5 space-y-3">
                            <?php foreach (current_lang() === 'en' ? [['Creative', 'Concept, theme and event storytelling'], ['Production', 'Stage, light, sound and technical crew'], ['Operation', 'Registration, supplier and on-site flow']] : [['Creative', 'คอนเซ็ปต์ ธีม และเรื่องเล่าของงาน'], ['Production', 'เวที แสง สี เสียง และทีมเทคนิค'], ['Operation', 'ลงทะเบียน ซัพพลายเออร์ และ flow หน้างาน']] as [$name, $desc]): ?>
                                <div class="rounded-2xl border border-white/10 bg-slate-950/30 p-4">
                                    <div class="font-extrabold"><?= e($name) ?></div>
                                    <p class="mt-1 text-sm leading-6 text-slate-300"><?= e($desc) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white px-4 py-20 sm:px-6 lg:px-8">
            <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-[.78fr_1.22fr] lg:items-start">
                <div class="lg:sticky lg:top-28">
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">What we do</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl"><?= current_lang() === 'en' ? 'We take care of every detail from first idea to final wrap-up.' : 'ดูแลงานตั้งแต่ไอเดียแรกจนแขกคนสุดท้ายกลับบ้าน' ?></h2>
                    <p class="mt-5 max-w-md text-base leading-8 text-slate-600"><?= current_lang() === 'en' ? 'One team for creative direction, production, media and on-site operations, so every touchpoint feels consistent.' : 'ทีมเดียวดูแลทั้งครีเอทีฟ โปรดักชัน สื่อ และ operation หน้างาน เพื่อให้งานออกมาชัดและต่อเนื่องทุกจุดสัมผัส' ?></p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <?php foreach (current_lang() === 'en' ? [['calendar-check', 'Event Organizer', 'Corporate events, exhibitions, seminars, concerts and booth management.'], ['clapperboard', 'Media Production', 'Advertising, PR materials, music production and video content.'], ['badge-check', 'Print & Fabrication', 'Vinyl signs, backdrops, standees, public relations signage and displays.'], ['shirt', 'Graphic & Apparel', 'Event graphics, apparel production and screen printing.']] : [['calendar-check', 'Event Organizer', 'รับจัดงาน Event, ออแกไนเซอร์, เอ็กซิบิชั่น, สัมมนา, คอนเสิร์ต และจัดบูธร้านค้า'], ['clapperboard', 'Media Production', 'รับผลิตสื่อ โฆษณา ประชาสัมพันธ์ ผลิตเพลง และมิวสิกวิดีโอ'], ['badge-check', 'Print & Fabrication', 'รับผลิตป้าย ไวนิล แบลกดรอป สแตนดี้ ป้ายประชาสัมพันธ์ และป้าย ส.ส.'], ['shirt', 'Graphic & Apparel', 'รับผลิตเสื้อ สกรีนเสื้อ และออกแบบกราฟิกสำหรับงานอีเว้นท์']] as [$icon, $name, $desc]): ?>
                        <div class="group rounded-[1.35rem] border border-slate-100 bg-slate-50 p-6 transition hover:-translate-y-1 hover:border-coral/20 hover:bg-white hover:shadow-soft">
                            <div class="mb-5 grid h-11 w-11 place-items-center rounded-2xl bg-white text-coral shadow-sm ring-1 ring-slate-100 transition group-hover:bg-coral group-hover:text-white">
                                <i data-lucide="<?= e($icon) ?>" class="h-5 w-5"></i>
                            </div>
                            <h3 class="text-lg font-extrabold"><?= e($name) ?></h3>
                            <p class="mt-2 text-sm leading-7 text-slate-600"><?= e($desc) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="bg-[#f3f0ea] px-4 py-20 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
                    <div>
                        <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">Selected works</p>
                        <h2 class="mt-3 text-3xl font-extrabold"><?= current_lang() === 'en' ? 'Selected work' : 'ผลงานที่เลือกมาให้ดู' ?></h2>
                    </div>
                    <a href="<?= e(url_for('/portfolio')) ?>" class="inline-flex items-center gap-2 rounded-full bg-white px-5 py-3 text-sm font-extrabold text-slate-700 shadow-sm ring-1 ring-slate-100 hover:text-coral"><?= e(t('view_all')) ?> <i data-lucide="arrow-right" class="h-4 w-4"></i></a>
                </div>
                <div class="grid gap-5 lg:grid-cols-3">
                    <?php foreach (array_slice($portfolios, 0, 3) as $index => $item): ?>
                        <a href="<?= portfolio_url($item) ?>" class="group overflow-hidden rounded-[1.5rem] bg-white shadow-sm ring-1 ring-black/5 transition hover:-translate-y-1 hover:shadow-soft <?= $index === 0 ? 'lg:col-span-1' : '' ?>">
                            <div class="<?= $index === 0 ? 'aspect-[4/3]' : 'aspect-[4/3]' ?> overflow-hidden">
                                <img src="<?= e(image_src($item['image_path'])) ?>" alt="<?= e(localized($item, 'title')) ?>" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                            </div>
                            <div class="p-6">
                                <div class="mb-4 flex items-center justify-between gap-3">
                                    <p class="text-xs font-extrabold uppercase tracking-wider text-coral"><?= e(localized($item, 'category')) ?></p>
                                    <span class="grid h-9 w-9 place-items-center rounded-full bg-slate-950 text-white transition group-hover:bg-coral">
                                        <i data-lucide="arrow-up-right" class="h-4 w-4"></i>
                                    </span>
                                </div>
                                <h3 class="mt-2 text-xl font-extrabold"><?= e(localized($item, 'title')) ?></h3>
                                <p class="mt-3 line-clamp-2 text-sm leading-7 text-slate-600"><?= e(localized($item, 'description')) ?></p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="bg-white px-4 py-20 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="mx-auto mb-8 max-w-2xl text-center">
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-slate-400">Trusted by brands</p>
                    <h2 class="mt-3 text-3xl font-extrabold"><?= current_lang() === 'en' ? 'Organizations that trust our event team' : 'องค์กรและแบรนด์ที่ไว้วางใจทีมของเรา' ?></h2>
                </div>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    <?php foreach ($clients as $client): ?>
                        <?= client_card($client) ?>
                    <?php endforeach; ?>
                </div>
                <div class="mt-8 text-center">
                    <a href="<?= e(url_for('/clients')) ?>" class="inline-flex items-center gap-2 rounded-full bg-slate-950 px-5 py-3 text-sm font-extrabold text-white shadow-soft hover:bg-coral">
                        <?= current_lang() === 'en' ? 'View all clients' : 'ดูโลโก้ทั้งหมด' ?>
                        <i data-lucide="arrow-right" class="h-4 w-4"></i>
                    </a>
                </div>
            </div>
        </section>

        <section class="bg-[#f6f4ef] px-4 py-20 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="mb-8 flex items-end justify-between gap-4">
                    <div>
                        <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">Insights</p>
                        <h2 class="mt-3 text-3xl font-extrabold"><?= current_lang() === 'en' ? 'Latest insights' : 'บทความล่าสุด' ?></h2>
                    </div>
                    <a href="<?= e(url_for('/articles')) ?>" class="hidden items-center gap-2 rounded-full bg-white px-5 py-3 text-sm font-extrabold text-slate-700 shadow-sm ring-1 ring-slate-100 hover:text-coral sm:inline-flex"><?= e(t('read_all')) ?> <i data-lucide="arrow-right" class="h-4 w-4"></i></a>
                </div>
                <div class="grid gap-5 md:grid-cols-3">
                    <?php foreach (array_slice($articles, 0, 3) as $article): ?>
                        <?= article_card($article) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
    }, $lang === 'en' ? 'Bigevent Organizer is a full-service event company for corporate events, product launches, exhibitions and organizational events from concept to show day.' : 'Bigevent Organizer บริษัทรับจัดงานอีเวนต์ครบวงจร ดูแล Corporate Event, Product Launch, Exhibition และงานองค์กรตั้งแต่คอนเซ็ปต์ถึงวันจริง', $banner['image_path'] ?? '');
}

function article_card(array $article): string
{
    ob_start();
    $title = localized($article, 'title');
    $excerpt = localized($article, 'excerpt');
    ?>
    <article class="overflow-hidden rounded-[1.5rem] bg-white shadow-sm ring-1 ring-slate-100">
        <img src="<?= e(image_src($article['image_path'])) ?>" alt="<?= e($title) ?>" class="h-48 w-full object-cover">
        <div class="p-6">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400"><?= e($article['published_at'] ?: $article['created_at']) ?></p>
            <h3 class="mt-2 text-lg font-extrabold leading-snug"><?= e($title) ?></h3>
            <p class="mt-3 line-clamp-2 text-sm leading-7 text-slate-600"><?= e($excerpt) ?></p>
            <a href="<?= e(article_url($article)) ?>" class="mt-5 inline-flex items-center gap-2 text-sm font-extrabold text-coral"><?= e(t('read_more')) ?> <i data-lucide="arrow-right" class="h-4 w-4"></i></a>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

function client_card(array $client): string
{
    ob_start();
    $name = localized($client, 'name');
    $website = trim((string) ($client['website'] ?? ''));
    $tag = current_lang() === 'en' ? 'Client / Partner' : 'ลูกค้า / พาร์ทเนอร์';
    $card = function () use ($client, $name, $tag) {
        ?>
        <div class="group flex min-h-28 items-center gap-4 rounded-[1.25rem] border border-slate-100 bg-slate-50 p-4 transition hover:-translate-y-1 hover:border-gold/30 hover:bg-white hover:shadow-soft">
            <div class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl bg-white p-2 shadow-sm ring-1 ring-slate-100">
                <?php if (!empty($client['logo_path'])): ?>
                    <img src="<?= e($client['logo_path']) ?>" alt="<?= e($name) ?>" class="max-h-12 max-w-full object-contain">
                <?php else: ?>
                    <span class="text-sm font-extrabold text-coral"><?= e(mb_substr($name, 0, 2)) ?></span>
                <?php endif; ?>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-extrabold leading-6 text-slate-700"><?= e($name) ?></p>
                <p class="mt-1 text-xs font-semibold text-slate-400"><?= e($tag) ?></p>
            </div>
        </div>
        <?php
    };

    if ($website !== '') {
        ?>
        <a href="<?= e($website) ?>" target="_blank" rel="noopener" class="block">
            <?php $card(); ?>
        </a>
        <?php
    } else {
        $card();
    }

    return ob_get_clean();
}

function about_page(): void
{
    $lang = current_lang();
    layout($lang === 'en' ? 'About Us' : 'เกี่ยวกับเรา', function () {
        ?>
        <section class="px-4 py-20 sm:px-6 lg:px-8">
            <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-[.9fr_1.1fr] lg:items-center">
                <div>
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">About Bigevent</p>
                    <h1 class="mt-4 text-4xl font-extrabold tracking-tight sm:text-5xl"><?= current_lang() === 'en' ? 'We combine brand strategy with real event experience.' : 'เราคือทีมออแกไนเซอร์ที่ผสมกลยุทธ์แบรนด์เข้ากับประสบการณ์จริง' ?></h1>
                    <p class="mt-6 text-lg leading-8 text-slate-600"><?= current_lang() === 'en' ? 'Bigevent is a new-generation event organizer focused on meaningful event outcomes, practical production and technology that helps every project feel current and well managed.' : 'เราคือผู้พัฒนาอีเว้นท์ออแกไนเซอร์รุ่นใหม่ ที่คำนึงถึงประโยชน์ของผู้ที่ได้รับในการจัดงาน และนำนวัตกรรมเทคโนโลยีเข้าช่วยในการจัดการอีเว้นท์ให้เสมือนอยู่ในยุคใหม่เสมอ' ?></p>
                    <blockquote class="mt-6 rounded-[1.5rem] border-l-4 border-coral bg-white p-6 text-lg font-semibold leading-8 text-slate-800 shadow-sm"><?= current_lang() === 'en' ? '“We turn every possibility into an event people remember.”' : '“สร้างสรรค์ทุกความเป็นไปได้ ให้กลายเป็นงานอีเว้นท์ที่คนจดจำ”' ?></blockquote>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <?php foreach ([['4.5/5', 'Performance'], ['90', 'Design'], ['88', 'Process'], ['96', 'Successful Results'], ['91', 'Teamwork'], ['95', 'Satisfied Customer']] as [$num, $label]): ?>
                        <div class="rounded-[1.5rem] bg-white p-8 shadow-sm">
                            <div class="text-4xl font-extrabold text-coral"><?= e($num) ?></div>
                            <div class="mt-2 text-sm font-semibold text-slate-500"><?= e($label) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="bg-white px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="mb-10 max-w-3xl">
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">Leadership</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl"><?= current_lang() === 'en' ? 'Leadership' : 'คณะผู้บริหาร' ?></h2>
                    <p class="mt-4 text-base leading-8 text-slate-600"><?= current_lang() === 'en' ? 'The leadership team behind Big Event connects creativity, production and technology with real on-site experience.' : 'ทีมผู้บริหารที่ขับเคลื่อน Big Event ให้เป็นออแกไนเซอร์ยุคใหม่ เชื่อมความคิดสร้างสรรค์ งานโปรดักชัน และเทคโนโลยีเข้ากับประสบการณ์จริงในวันงาน' ?></p>
                </div>

                <div class="grid max-w-4xl gap-6">
                    <article class="overflow-hidden rounded-[2rem] border border-slate-100 bg-slate-50 shadow-sm">
                        <div class="grid gap-0 sm:grid-cols-[220px_1fr]">
                            <img src="https://www.bigevent.co.th/wp-content/uploads/2025/06/ว่าที่ร้อยตรีปกป้อง-มกรนันท์.jpg" alt="ว่าที่ร้อยตรีปกป้อง มกรนันท์" class="h-72 w-full object-cover sm:h-full">
                            <div class="p-7">
                                <p class="text-xs font-extrabold uppercase tracking-[0.22em] text-coral">Chief Executive Officer</p>
                                <h3 class="mt-3 text-2xl font-extrabold">ว่าที่ร้อยตรีปกป้อง มกรนันท์</h3>
                                <p class="mt-2 text-sm font-bold text-slate-500">CEO บริษัท บิ๊กอีเว้นท์ จำกัด</p>
                                <p class="mt-5 text-sm leading-7 text-slate-600">มุ่งพัฒนาอีเว้นท์ออแกไนเซอร์รุ่นใหม่ที่นำเทคโนโลยี ระบบหลังบ้าน และการวัดผลมาช่วยยกระดับการจัดงานให้มีประสิทธิภาพและน่าจดจำ</p>
                            </div>
                        </div>
                    </article>
                </div>
            </div>
        </section>
        <?php
    }, $lang === 'en' ? 'About Bigevent Organizer, the team behind creative event strategy, production and operations for brands and organizations.' : 'รู้จัก Bigevent ทีมออแกไนเซอร์ที่วางกลยุทธ์ ครีเอทีฟ โปรดักชัน และ operation สำหรับงานแบรนด์และองค์กร');
}

function portfolio_page(): void
{
    $items = all('portfolios');
    $lang = current_lang();
    layout('ผลงานบริษัท', function () use ($items) {
        ?>
        <section class="px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-3xl">
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">Portfolio</p>
                    <h1 class="mt-4 text-4xl font-extrabold"><?= current_lang() === 'en' ? 'Portfolio' : 'ผลงานบริษัท' ?></h1>
                    <p class="mt-4 text-lg leading-8 text-slate-600"><?= current_lang() === 'en' ? 'Selected event projects designed and produced by the Bigevent team.' : 'รวมตัวอย่างงานอีเวนต์ที่ออกแบบและผลิตโดยทีม Bigevent' ?></p>
                </div>
                <div class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($items as $item): ?>
                        <a href="<?= portfolio_url($item) ?>" class="group overflow-hidden rounded-[1.5rem] bg-white shadow-sm ring-1 ring-slate-100 transition hover:-translate-y-1 hover:shadow-soft">
                            <img src="<?= e(image_src($item['image_path'])) ?>" alt="<?= e(localized($item, 'title')) ?>" class="h-64 w-full object-cover">
                            <div class="p-6">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-bold uppercase tracking-wider text-coral"><?= e(localized($item, 'category')) ?></p>
                                    <p class="text-xs font-semibold text-slate-400"><?= e($item['event_date']) ?></p>
                                </div>
                                <h2 class="mt-2 text-xl font-extrabold"><?= e(localized($item, 'title')) ?></h2>
                                <p class="mt-1 text-sm font-semibold text-slate-500"><?= e(localized($item, 'client')) ?> <?= localized($item, 'location') ? ' · ' . e(localized($item, 'location')) : '' ?></p>
                                <p class="mt-4 text-sm leading-7 text-slate-600"><?= e(localized($item, 'description')) ?></p>
                                <span class="mt-5 inline-flex items-center gap-2 text-sm font-extrabold text-coral"><?= e(t('details')) ?> <i data-lucide="arrow-right" class="h-4 w-4"></i></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
    }, $lang === 'en' ? 'Explore Bigevent event portfolio, including product launches, corporate events, exhibitions and organizational events.' : 'รวมผลงานจัดงานอีเวนต์ของ Bigevent ทั้ง Product Launch, Corporate Event, Exhibition และงานองค์กร', $items[0]['image_path'] ?? '');
}

function portfolio_detail_page(string $slug): void
{
    $stmt = db()->prepare("SELECT * FROM portfolios WHERE slug = ? OR slug_en = ? OR id = ? LIMIT 1");
    $stmt->execute([$slug, $slug, ctype_digit($slug) ? (int) $slug : 0]);
    $item = $stmt->fetch();
    if (!$item) {
        not_found();
    }

    $relatedStmt = db()->prepare("SELECT * FROM portfolios WHERE id != ? ORDER BY is_featured DESC, sort_order ASC, id DESC LIMIT 3");
    $relatedStmt->execute([(int) $item['id']]);
    $related = $relatedStmt->fetchAll();
    $gallery = gallery_items('portfolio', (int) $item['id']);
    $title = localized($item, 'title');
    if (!empty($item['image_path'])) {
        $cover = null;
        $restGallery = [];
        foreach ($gallery as $image) {
            if (($image['image_path'] ?? '') === $item['image_path'] && $cover === null) {
                $cover = $image;
            } else {
                $restGallery[] = $image;
            }
        }
        $cover = $cover ?: [
            'id' => 0,
            'image_path' => $item['image_path'],
            'caption' => $title,
            'sort_order' => 0,
        ];
        $cover['caption'] = $cover['caption'] ?: $title;
        $gallery = array_merge([$cover], $restGallery);
    }

    $description = localized($item, 'description');
    $seoTitle = localized($item, 'seo_title') ?: $title;
    $seoDescription = localized($item, 'meta_description') ?: ($description ?: (current_lang() === 'en' ? 'Bigevent Organizer project details.' : 'รายละเอียดผลงานจัดงานอีเว้นท์ของบริษัท บิ๊กอีเว้นท์ จำกัด'));
    set_alternate_paths('/portfolio/' . ($item['slug'] ?: $item['id']), '/en/portfolio/' . (($item['slug_en'] ?? '') ?: ($item['slug'] ?: $item['id'])));
    set_schema_extra([
        [
            '@type' => 'CreativeWork',
            '@id' => absolute_url(portfolio_url($item)) . '#creativework',
            'name' => $title,
            'description' => $seoDescription,
            'image' => absolute_url($item['image_path'] ?: setting('default_og_image', '/assets/img/og-default.png')),
            'url' => absolute_url(portfolio_url($item)),
            'creator' => ['@id' => absolute_url('/#organization')],
            'about' => localized($item, 'category') ?: 'Event Organizer',
            'datePublished' => $item['event_date'] ?: substr((string) $item['created_at'], 0, 10),
        ],
    ]);
    layout($seoTitle, function () use ($item, $related, $gallery, $title, $description) {
        ?>
        <article>
            <section class="relative overflow-hidden bg-slate-950">
                <?= admin_front_edit_menu('/admin/portfolio/edit?id=' . (int) $item['id']) ?>
                <img src="<?= e(image_src($item['image_path'])) ?>" alt="<?= e($title) ?>" class="absolute inset-0 h-full w-full object-cover opacity-45">
                <div class="absolute inset-0 bg-[linear-gradient(110deg,rgba(15,23,42,.94),rgba(15,23,42,.68),rgba(225,91,79,.24))]"></div>
                <div class="relative mx-auto max-w-7xl px-4 py-20 text-white sm:px-6 lg:px-8 lg:py-28">
                    <a href="<?= e(url_for('/portfolio')) ?>" class="mb-8 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm font-bold backdrop-blur hover:bg-white/15">
                        <i data-lucide="arrow-left" class="h-4 w-4"></i>
                        <?= e(t('back_portfolio')) ?>
                    </a>
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-gold"><?= e(localized($item, 'category') ?: 'Portfolio') ?></p>
                    <h1 class="mt-4 max-w-4xl text-4xl font-extrabold leading-tight sm:text-6xl"><?= e($title) ?></h1>
                    <p class="mt-6 max-w-3xl text-lg leading-8 text-slate-200"><?= e($description) ?></p>
                </div>
            </section>

            <section class="px-4 py-14 sm:px-6 lg:px-8">
                <div class="mx-auto grid max-w-7xl gap-8 lg:grid-cols-[.78fr_1.22fr]">
                    <aside class="h-fit space-y-5">
                        <div class="rounded-[1.5rem] bg-white p-6 shadow-sm ring-1 ring-slate-100">
                            <h2 class="text-lg font-extrabold"><?= current_lang() === 'en' ? 'Project details' : 'รายละเอียดงาน' ?></h2>
                            <dl class="mt-5 space-y-4 text-sm">
                                <div>
                                    <dt class="font-bold text-slate-400"><?= current_lang() === 'en' ? 'Type' : 'ประเภทงาน' ?></dt>
                                    <dd class="mt-1 font-semibold text-slate-800"><?= e(localized($item, 'category') ?: '-') ?></dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate-400"><?= current_lang() === 'en' ? 'Client / Project' : 'ลูกค้า / โปรเจกต์' ?></dt>
                                    <dd class="mt-1 font-semibold text-slate-800"><?= e(localized($item, 'client') ?: '-') ?></dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate-400"><?= current_lang() === 'en' ? 'Location' : 'สถานที่' ?></dt>
                                    <dd class="mt-1 font-semibold text-slate-800"><?= e(localized($item, 'location') ?: '-') ?></dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate-400"><?= current_lang() === 'en' ? 'Event date' : 'วันที่จัดงาน' ?></dt>
                                    <dd class="mt-1 font-semibold text-slate-800"><?= e($item['event_date'] ?: '-') ?></dd>
                                </div>
                            </dl>
                            <a href="<?= e(url_for('/contact')) ?>" class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-slate-950 px-5 py-3 text-sm font-extrabold text-white hover:bg-coral">
                                <?= current_lang() === 'en' ? 'Discuss a similar project' : 'ปรึกษางานแบบนี้' ?>
                                <i data-lucide="arrow-right" class="h-4 w-4"></i>
                            </a>
                        </div>
                        <?= share_panel($title, absolute_url(portfolio_url($item)), absolute_url(short_url('p', (int) $item['id']))) ?>
                    </aside>

                    <div>
                        <div class="overflow-hidden rounded-[2rem] bg-white shadow-soft">
                            <img src="<?= e(image_src($item['image_path'])) ?>" alt="<?= e($title) ?>" class="max-h-[620px] w-full object-cover">
                        </div>
                        <div class="mt-8 rounded-[1.5rem] bg-white p-7 shadow-sm ring-1 ring-slate-100">
                            <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">Project overview</p>
                            <h2 class="mt-3 text-2xl font-extrabold"><?= current_lang() === 'en' ? 'Project overview' : 'ภาพรวมผลงาน' ?></h2>
                            <p class="mt-4 text-base leading-8 text-slate-700"><?= nl2br(e($description)) ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <?= video_section(current_lang() === 'en' ? (($item['video_url_en'] ?? '') ?: ($item['video_url'] ?? '')) : ($item['video_url'] ?? ''), current_lang() === 'en' ? 'Watch the event recap' : 'ชมวิดีโอสรุปงาน') ?>

            <?= gallery_section($gallery, current_lang() === 'en' ? 'Event gallery' : 'ภาพบรรยากาศงาน') ?>

            <?php if ($related): ?>
                <section class="bg-white px-4 py-14 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-7xl">
                        <div class="mb-8 flex items-end justify-between gap-4">
                            <div>
                                <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">More works</p>
                                <h2 class="mt-3 text-3xl font-extrabold"><?= current_lang() === 'en' ? 'More works' : 'ผลงานอื่น ๆ' ?></h2>
                            </div>
                            <a href="<?= e(url_for('/portfolio')) ?>" class="text-sm font-extrabold text-slate-700 hover:text-coral"><?= e(t('view_all')) ?></a>
                        </div>
                        <div class="grid gap-5 md:grid-cols-3">
                            <?php foreach ($related as $other): ?>
                                <a href="<?= portfolio_url($other) ?>" class="group overflow-hidden rounded-[1.5rem] bg-slate-50 ring-1 ring-slate-100 transition hover:-translate-y-1 hover:shadow-soft">
                                    <img src="<?= e(image_src($other['image_path'])) ?>" alt="<?= e(localized($other, 'title')) ?>" class="h-48 w-full object-cover transition duration-500 group-hover:scale-105">
                                    <div class="p-5">
                                        <p class="text-xs font-bold uppercase tracking-wider text-coral"><?= e(localized($other, 'category')) ?></p>
                                        <h3 class="mt-2 text-lg font-extrabold"><?= e(localized($other, 'title')) ?></h3>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </article>
        <?php
    }, $seoDescription, $item['image_path'] ?? '');
}

function services_page(): void
{
    $lang = current_lang();
    layout($lang === 'en' ? 'Event Organizer Services' : 'รับจัดงานอีเว้นท์', function () {
        $eventTypes = current_lang() === 'en' ? [
            ['Corporate Events', 'Product launches, conferences, seminars, company parties and press events.'],
            ['Private Events', 'Weddings, birthdays, reunions and ceremonial events.'],
            ['Marketing and Promotion Events', 'Fairs, exhibitions, mall activations, roadshows and brand PR events.'],
        ] : [
            ['งานอีเว้นท์องค์กร', 'งานเปิดตัวสินค้าใหม่, งานประชุมสัมมนา, งานเลี้ยงบริษัท และงานแถลงข่าว'],
            ['งานอีเว้นท์ส่วนบุคคล', 'งานแต่งงาน, งานเลี้ยงวันเกิด, งานเลี้ยงรุ่น และงานพิธีมงคลต่าง ๆ'],
            ['งานส่งเสริมการขายและการตลาด', 'งานแฟร์ งานแสดงสินค้า งานโปรโมชันตามศูนย์การค้า งานโรดโชว์ และงานประชาสัมพันธ์แบรนด์'],
        ];
        $steps = current_lang() === 'en' ? [
            ['Planning and Consultation', 'Understand requirements, budget, audience and event goals.'],
            ['Creative Concept Design', 'Develop the theme, decoration, program flow and activities.'],
            ['Preparation and Coordination', 'Coordinate venue, equipment, crew, speakers, artists and technical systems.'],
            ['Show-day Operation', 'Manage the event on-site professionally and respond quickly to changing situations.'],
            ['Reporting and Evaluation', 'Review goals, audience satisfaction and post-event results.'],
        ] : [
            ['การวางแผนและปรึกษา', 'พูดคุยกับลูกค้าเพื่อทำความเข้าใจความต้องการ งบประมาณ และเป้าหมายของงาน'],
            ['การออกแบบแนวคิด', 'นำเสนอ Concept ธีม งานตกแต่ง สคริปต์งาน และกิจกรรมภายในงาน'],
            ['การเตรียมการและประสานงาน', 'จัดหาสถานที่ อุปกรณ์ ทีมงาน วิทยากร ศิลปิน แขกพิเศษ และระบบเทคนิค'],
            ['การดำเนินงานในวันงาน', 'ดูแลและบริหารจัดการทุกอย่างให้เป็นไปตามแผน พร้อมรับมือกับสถานการณ์หน้างานอย่างมืออาชีพ'],
            ['การสรุปผลและประเมินผล', 'ตรวจสอบความสำเร็จจากเป้าหมาย ความพึงพอใจ และผลตอบรับจากผู้ร่วมงาน'],
        ];
        $serviceHighlights = current_lang() === 'en' ? [
            ['event-organizer', 'calendar-check', 'Event Organizer', 'Corporate events, launches, seminars, company parties and press conferences with end-to-end coordination.'],
            ['exhibition-seminar', 'presentation', 'Exhibition & Seminar', 'Exhibition booths, seminar flow, registration, speaker support and on-site team management.'],
            ['concert-booth', 'music-2', 'Concert & Booth', 'Stage, lighting, sound, booth setup, technical crew and show-day operations.'],
            ['media-production', 'clapperboard', 'Media Production', 'Photo, video, highlight content, PR materials and media assets for event communication.'],
        ] : [
            ['event-organizer', 'calendar-check', 'Event Organizer', 'รับจัดงานองค์กร งานเปิดตัวสินค้า งานสัมมนา งานเลี้ยงบริษัท และงานแถลงข่าวครบวงจร'],
            ['exhibition-seminar', 'presentation', 'Exhibition & Seminar', 'ออกแบบบูธ งานแสดงสินค้า ระบบลงทะเบียน ดูแล flow สัมมนา วิทยากร และทีมหน้างาน'],
            ['concert-booth', 'music-2', 'Concert & Booth', 'ดูแลเวที แสง สี เสียง บูธจัดแสดง ทีมเทคนิค และการดำเนินงานในวันจริง'],
            ['media-production', 'clapperboard', 'Media Production', 'ผลิตภาพนิ่ง วิดีโอ highlight สื่อประชาสัมพันธ์ และ asset สำหรับสื่อสารงานอีเวนต์'],
        ];
        ?>
        <section class="px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-[.9fr_1.1fr] lg:items-center">
                <div>
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">Services</p>
                    <h1 class="mt-4 text-4xl font-extrabold leading-tight sm:text-5xl"><?= current_lang() === 'en' ? 'Full-service event organizer for every requirement.' : 'รับจัดงานอีเว้นท์ บริการครบวงจรสำหรับทุกความต้องการ' ?></h1>
                    <p class="mt-6 text-lg leading-8 text-slate-600"><?= current_lang() === 'en' ? 'Events are essential for business and community communication. Our professional team plans, designs and manages every detail so your event runs smoothly and achieves its goals.' : 'งานอีเว้นท์เป็นส่วนสำคัญของธุรกิจและสังคม ไม่ว่าจะเป็นงานเปิดตัวสินค้า งานประชุมสัมมนา งานแต่งงาน หรือกิจกรรมส่งเสริมการขาย ทีมมืออาชีพช่วยให้ทุกอย่างดำเนินไปอย่างราบรื่นและประสบความสำเร็จตามเป้าหมาย' ?></p>
                </div>
                <img src="https://www.bigevent.co.th/wp-content/uploads/2024/08/Big-Event-บิ๊กอีเว้นท์-รับจัดงานอีเว้นท์-3.jpg" alt="รับจัดงานอีเว้นท์ By บิ๊กอีเว้นท์" class="h-full max-h-[460px] w-full rounded-[2rem] object-cover shadow-soft">
            </div>
        </section>

        <section id="service-focus" class="bg-slate-950 px-4 py-16 text-white sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-3xl">
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-gold">Service focus</p>
                    <h2 class="mt-3 text-3xl font-extrabold"><?= current_lang() === 'en' ? 'Core services' : 'บริการหลักของเรา' ?></h2>
                </div>
                <div class="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                    <?php foreach ($serviceHighlights as [$id, $icon, $title, $desc]): ?>
                        <article id="<?= e($id) ?>" class="scroll-mt-24 rounded-[1.5rem] border border-white/10 bg-white/5 p-6">
                            <div class="grid h-11 w-11 place-items-center rounded-2xl bg-white text-slate-950">
                                <i data-lucide="<?= e($icon) ?>" class="h-5 w-5"></i>
                            </div>
                            <h3 class="mt-5 text-xl font-extrabold"><?= e($title) ?></h3>
                            <p class="mt-3 text-sm leading-7 text-slate-300"><?= e($desc) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="bg-white px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="max-w-3xl">
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">Event types</p>
                    <h2 class="mt-3 text-3xl font-extrabold"><?= current_lang() === 'en' ? 'Event types we support' : 'ประเภทของงานอีเว้นท์ที่ให้บริการ' ?></h2>
                </div>
                <div class="mt-8 grid gap-5 md:grid-cols-3">
                    <?php foreach ($eventTypes as [$title, $desc]): ?>
                        <div class="rounded-[1.5rem] border border-slate-100 bg-slate-50 p-6">
                            <h3 class="text-xl font-extrabold"><?= e($title) ?></h3>
                            <p class="mt-3 text-sm leading-7 text-slate-600"><?= e($desc) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="grid gap-10 lg:grid-cols-[.8fr_1.2fr]">
                    <div>
                        <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">Process</p>
                        <h2 class="mt-3 text-3xl font-extrabold"><?= current_lang() === 'en' ? 'Our process' : 'ขั้นตอนการให้บริการ' ?></h2>
                        <p class="mt-4 text-slate-600"><?= current_lang() === 'en' ? 'From concept development and venue planning to decoration, equipment, crew and full event management.' : 'ครอบคลุมตั้งแต่การกำหนดแนวคิด การเลือกสถานที่ การออกแบบตกแต่ง การจัดหาอุปกรณ์ ทีมงาน และการบริหารจัดการทั้งหมด' ?></p>
                    </div>
                    <div class="space-y-4">
                        <?php foreach ($steps as $i => [$title, $desc]): ?>
                            <div class="flex gap-4 rounded-[1.5rem] bg-white p-5 shadow-sm">
                                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-slate-950 text-sm font-extrabold text-white"><?= $i + 1 ?></div>
                                <div>
                                    <h3 class="font-extrabold"><?= e($title) ?></h3>
                                    <p class="mt-1 text-sm leading-7 text-slate-600"><?= e($desc) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }, $lang === 'en' ? 'Full-service event organizer for planning, creative design, equipment, crew, lighting, sound, stage and on-site event management by BIG EVENT CO., LTD.' : 'รับจัดงานอีเว้นท์ครบวงจร วางแผน ออกแบบ จัดหาอุปกรณ์ ทีมงาน แสง สี เสียง เวที และบริหารจัดการหน้างานโดยบริษัท บิ๊กอีเว้นท์ จำกัด', 'https://www.bigevent.co.th/wp-content/uploads/2024/08/Big-Event-บิ๊กอีเว้นท์-รับจัดงานอีเว้นท์.jpg');
}

function articles_page(): void
{
    $articles = all('articles', 'is_published = 1', 'published_at DESC, id DESC');
    $lang = current_lang();
    layout('บทความ', function () use ($articles) {
        ?>
        <section class="px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">Articles</p>
                <h1 class="mt-4 text-4xl font-extrabold"><?= current_lang() === 'en' ? 'Articles and Event Ideas' : 'บทความและไอเดียจัดงาน' ?></h1>
                <div class="mt-10 grid gap-5 md:grid-cols-3">
                    <?php foreach ($articles as $article): ?>
                        <?= article_card($article) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
    }, $lang === 'en' ? 'Event planning articles and ideas for brands and organizations from Bigevent Organizer.' : 'บทความและไอเดียจัดงานอีเวนต์สำหรับแบรนด์และองค์กร จากทีม Bigevent Organizer', $articles[0]['image_path'] ?? '');
}

function clients_page(): void
{
    $clients = all('clients', 'is_active = 1', 'sort_order ASC, id DESC');
    $lang = current_lang();
    layout($lang === 'en' ? 'Clients and Partners' : 'โลโก้ลูกค้าและพาร์ทเนอร์', function () use ($clients) {
        ?>
        <section class="relative overflow-hidden bg-slate-950 px-4 py-20 text-white sm:px-6 lg:px-8">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_78%_20%,rgba(200,155,60,.28),transparent_30%),linear-gradient(110deg,rgba(2,6,23,.98),rgba(15,23,42,.84),rgba(127,29,29,.42))]"></div>
            <div class="relative mx-auto max-w-7xl">
                <div class="max-w-3xl">
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-gold">Trusted by brands</p>
                    <h1 class="mt-4 text-4xl font-extrabold tracking-normal sm:text-6xl"><?= current_lang() === 'en' ? 'Clients and partners who trust Bigevent' : 'รวมโลโก้ลูกค้าและพาร์ทเนอร์ที่ไว้วางใจเรา' ?></h1>
                    <p class="mt-6 text-lg leading-8 text-slate-200"><?= current_lang() === 'en' ? 'A growing collection of organizations, brands and public-sector partners we have supported through events, media and production work.' : 'รวมองค์กร แบรนด์ และพาร์ทเนอร์ที่ทีม Bigevent ได้ร่วมดูแลงานอีเวนต์ สื่อ และโปรดักชัน พร้อมรองรับการเพิ่มโลโก้ใหม่จากหลังบ้านได้ต่อเนื่อง' ?></p>
                </div>
            </div>
        </section>

        <section class="px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral"><?= count($clients) ?> <?= current_lang() === 'en' ? 'logos' : 'โลโก้' ?></p>
                        <h2 class="mt-3 text-3xl font-extrabold"><?= current_lang() === 'en' ? 'Brand wall' : 'Brand Wall รวมโลโก้' ?></h2>
                    </div>
                    <a href="<?= e(url_for('/contact')) ?>" class="inline-flex items-center gap-2 rounded-full bg-white px-5 py-3 text-sm font-extrabold text-slate-700 shadow-sm ring-1 ring-slate-100 hover:text-coral">
                        <?= current_lang() === 'en' ? 'Work with us' : 'เริ่มโปรเจกต์กับเรา' ?>
                        <i data-lucide="arrow-right" class="h-4 w-4"></i>
                    </a>
                </div>

                <?php if ($clients): ?>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <?php foreach ($clients as $client): ?>
                            <?= client_card($client) ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="rounded-[1.5rem] bg-white p-10 text-center shadow-sm ring-1 ring-slate-100">
                        <p class="text-lg font-extrabold"><?= current_lang() === 'en' ? 'No client logos yet.' : 'ยังไม่มีโลโก้ลูกค้า' ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }, $lang === 'en' ? 'Client and partner logo collection for Bigevent Organizer.' : 'รวมโลโก้ลูกค้าและพาร์ทเนอร์ของ Bigevent Organizer');
}

function article_detail_page(string $slug): void
{
    $stmt = db()->prepare("SELECT * FROM articles WHERE (slug = ? OR slug_en = ?) AND is_published = 1");
    $stmt->execute([$slug, $slug]);
    $article = $stmt->fetch();
    if (!$article) {
        not_found();
    }

    $relatedStmt = db()->prepare("SELECT * FROM articles WHERE is_published = 1 AND id != ? ORDER BY published_at DESC, id DESC LIMIT 3");
    $relatedStmt->execute([(int) $article['id']]);
    $related = $relatedStmt->fetchAll();

    $title = localized($article, 'title');
    $excerpt = localized($article, 'excerpt');
    $content = localized($article, 'content');
    $seoTitle = localized($article, 'seo_title') ?: $title;
    $seoDescription = localized($article, 'meta_description') ?: ($excerpt ?: (current_lang() === 'en' ? 'Articles from Bigevent Organizer about event planning and production.' : 'บทความจาก Bigevent Organizer เกี่ยวกับการวางแผนและจัดงานอีเวนต์'));
    $paragraphs = preg_split("/\R{2,}/", trim($content)) ?: [];
    $published = $article['published_at'] ?: substr((string) $article['created_at'], 0, 10);
    $readMinutes = max(1, (int) ceil(mb_strlen(strip_tags($content)) / 850));
    $gallery = gallery_items('article', (int) $article['id']);
    set_alternate_paths('/articles/' . $article['slug'], '/en/articles/' . (($article['slug_en'] ?? '') ?: $article['slug']));
    set_schema_extra([
        [
            '@type' => 'Article',
            '@id' => absolute_url(article_url($article)) . '#article',
            'headline' => $title,
            'description' => $seoDescription,
            'image' => absolute_url($article['image_path'] ?: setting('default_og_image', '/assets/img/og-default.png')),
            'url' => absolute_url(article_url($article)),
            'datePublished' => $published,
            'dateModified' => substr((string) ($article['created_at'] ?? $published), 0, 10),
            'author' => ['@id' => absolute_url('/#organization')],
            'publisher' => ['@id' => absolute_url('/#organization')],
            'mainEntityOfPage' => absolute_url(article_url($article)),
        ],
    ]);

    layout($seoTitle, function () use ($article, $related, $paragraphs, $published, $readMinutes, $gallery, $title, $excerpt) {
        ?>
        <article>
            <section class="relative overflow-hidden bg-slate-950">
                <?= admin_front_edit_menu('/admin/articles/edit?id=' . (int) $article['id']) ?>
                <img src="<?= e(image_src($article['image_path'])) ?>" alt="<?= e($title) ?>" class="absolute inset-0 h-full w-full object-cover opacity-36">
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_80%_20%,rgba(225,91,79,.35),transparent_34%),linear-gradient(110deg,rgba(15,23,42,.96),rgba(15,23,42,.82),rgba(15,23,42,.62))]"></div>
                <div class="relative mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-[1fr_420px] lg:px-8 lg:py-24">
                    <div class="flex flex-col justify-center text-white">
                        <a href="<?= e(url_for('/articles')) ?>" class="mb-8 inline-flex w-fit items-center gap-2 rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm font-bold backdrop-blur hover:bg-white/15">
                            <i data-lucide="arrow-left" class="h-4 w-4"></i>
                            <?= e(t('back_articles')) ?>
                        </a>
                        <div class="flex flex-wrap gap-3">
                            <span class="rounded-full bg-coral px-4 py-2 text-xs font-extrabold uppercase tracking-wider text-white">Article</span>
                            <span class="rounded-full border border-white/20 bg-white/10 px-4 py-2 text-xs font-bold text-slate-100"><?= e($published) ?></span>
                            <span class="rounded-full border border-white/20 bg-white/10 px-4 py-2 text-xs font-bold text-slate-100"><?= current_lang() === 'en' ? 'About ' . $readMinutes . ' min read' : 'อ่านประมาณ ' . $readMinutes . ' นาที' ?></span>
                        </div>
                        <h1 class="mt-6 max-w-4xl text-4xl font-extrabold leading-tight sm:text-6xl"><?= e($title) ?></h1>
                        <p class="mt-6 max-w-3xl text-lg leading-8 text-slate-200"><?= e($excerpt) ?></p>
                    </div>
                    <div class="hidden overflow-hidden rounded-[2rem] bg-white/10 p-3 shadow-soft ring-1 ring-white/15 backdrop-blur lg:block">
                        <img src="<?= e(image_src($article['image_path'])) ?>" alt="<?= e($title) ?>" class="h-[460px] w-full rounded-[1.4rem] object-cover">
                    </div>
                </div>
            </section>

            <section class="px-4 py-14 sm:px-6 lg:px-8">
                <div class="mx-auto grid max-w-7xl gap-8 lg:grid-cols-[minmax(0,760px)_320px] lg:items-start lg:justify-center">
                    <div class="overflow-hidden rounded-[2rem] bg-white shadow-soft ring-1 ring-slate-100">
                        <img src="<?= e(image_src($article['image_path'])) ?>" alt="<?= e($title) ?>" class="h-72 w-full object-cover lg:hidden">
                        <div class="p-6 sm:p-10">
                            <div class="mb-8 rounded-[1.5rem] border-l-4 border-coral bg-slate-50 p-5">
                                <p class="text-base font-semibold leading-8 text-slate-700"><?= e($excerpt) ?></p>
                            </div>
                            <div class="article-content space-y-7 text-[1.05rem] leading-9 text-slate-700">
                                <?php foreach ($paragraphs as $i => $paragraph): ?>
                                    <?php if (trim($paragraph) !== ''): ?>
                                        <p class="<?= $i === 0 ? 'first-paragraph' : '' ?>"><?= nl2br(e(trim($paragraph))) ?></p>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <aside class="space-y-5 lg:sticky lg:top-24">
                        <div class="rounded-[1.5rem] bg-white p-6 shadow-sm ring-1 ring-slate-100">
                            <p class="text-xs font-extrabold uppercase tracking-[0.22em] text-coral">Quick info</p>
                            <dl class="mt-5 space-y-4 text-sm">
                                <div>
                                    <dt class="font-bold text-slate-400"><?= current_lang() === 'en' ? 'Published' : 'เผยแพร่' ?></dt>
                                    <dd class="mt-1 font-semibold text-slate-800"><?= e($published) ?></dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate-400"><?= current_lang() === 'en' ? 'Category' : 'หมวดหมู่' ?></dt>
                                    <dd class="mt-1 font-semibold text-slate-800">Event Organizer</dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate-400"><?= current_lang() === 'en' ? 'Reading time' : 'เวลาอ่าน' ?></dt>
                                    <dd class="mt-1 font-semibold text-slate-800"><?= current_lang() === 'en' ? 'About ' . $readMinutes . ' min' : 'ประมาณ ' . $readMinutes . ' นาที' ?></dd>
                                </div>
                            </dl>
                        </div>

                        <?= share_panel($title, absolute_url(article_url($article)), absolute_url(short_url('a', (int) $article['id']))) ?>

                        <div class="rounded-[1.5rem] bg-slate-950 p-6 text-white shadow-soft">
                            <p class="text-xs font-extrabold uppercase tracking-[0.22em] text-gold">Plan your event</p>
                            <h2 class="mt-3 text-2xl font-extrabold"><?= current_lang() === 'en' ? 'Planning a professional event?' : 'อยากจัดงานแบบมืออาชีพ?' ?></h2>
                            <p class="mt-3 text-sm leading-7 text-slate-300"><?= current_lang() === 'en' ? 'Talk to the Big Event team about format, budget and the right event approach for your goals.' : 'คุยกับทีม Big Event เพื่อประเมินรูปแบบงาน งบประมาณ และแนวทางการจัดงานที่เหมาะกับเป้าหมายของคุณ' ?></p>
                            <a href="<?= e(url_for('/contact')) ?>" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-white px-5 py-3 text-sm font-extrabold text-slate-950 hover:bg-gold">
                                <?= current_lang() === 'en' ? 'Request Consultation' : 'ขอคำปรึกษา' ?>
                                <i data-lucide="arrow-right" class="h-4 w-4"></i>
                            </a>
                        </div>
                    </aside>
                </div>
            </section>

            <?= gallery_section($gallery, current_lang() === 'en' ? 'Article gallery' : 'ภาพประกอบบทความ') ?>

            <?php if ($related): ?>
                <section class="bg-white px-4 py-14 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-7xl">
                        <div class="mb-8 flex items-end justify-between gap-4">
                            <div>
                                <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">More articles</p>
                                <h2 class="mt-3 text-3xl font-extrabold"><?= current_lang() === 'en' ? 'Related articles' : 'บทความที่เกี่ยวข้อง' ?></h2>
                            </div>
                            <a href="<?= e(url_for('/articles')) ?>" class="text-sm font-extrabold text-slate-700 hover:text-coral"><?= e(t('read_all')) ?></a>
                        </div>
                        <div class="grid gap-5 md:grid-cols-3">
                            <?php foreach ($related as $item): ?>
                                <?= article_card($item) ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </article>
        <?php
    }, $seoDescription, $article['image_path'] ?? '');
}

function legal_policy_layout(string $eyebrow, string $title, string $intro, array $sections, string $description): void
{
    layout($title, function () use ($eyebrow, $title, $intro, $sections) {
        ?>
        <section class="relative overflow-hidden bg-slate-950 px-4 py-20 text-white sm:px-6 lg:px-8">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_82%_18%,rgba(200,155,60,.25),transparent_32%),linear-gradient(110deg,rgba(2,6,23,.98),rgba(15,23,42,.86),rgba(127,29,29,.40))]"></div>
            <div class="relative mx-auto max-w-5xl">
                <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-gold"><?= e($eyebrow) ?></p>
                <h1 class="mt-4 text-4xl font-extrabold tracking-tight sm:text-5xl"><?= e($title) ?></h1>
                <p class="mt-5 max-w-3xl text-lg leading-8 text-slate-200"><?= e($intro) ?></p>
                <p class="mt-4 text-sm font-semibold text-slate-400"><?= current_lang() === 'en' ? 'Last updated: May 17, 2026' : 'อัปเดตล่าสุด: 17 พฤษภาคม 2569' ?></p>
            </div>
        </section>
        <section class="px-4 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-5xl rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-slate-100 sm:p-10">
                <div class="space-y-8">
                    <?php foreach ($sections as [$heading, $body]): ?>
                        <section>
                            <h2 class="text-2xl font-extrabold text-slate-950"><?= e($heading) ?></h2>
                            <div class="mt-4 space-y-3 text-base leading-8 text-slate-600">
                                <?php foreach ((array) $body as $paragraph): ?>
                                    <p><?= e($paragraph) ?></p>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
    }, $description);
}

function privacy_policy_page(): void
{
    if (current_lang() === 'en') {
        legal_policy_layout(
            'Privacy',
            'Privacy Policy',
            'This policy explains how BIG EVENT CO., LTD. collects, uses and protects personal data submitted through this website.',
            [
                ['Personal data we collect', ['We may collect contact name, phone number, event type, event date, venue and information that you voluntarily provide through forms, phone calls, email, LINE OA or other contact channels.', 'Admin users may also have account data such as name, email, role and login session information for managing the website.']],
                ['Purpose of use', ['We use personal data to contact you back, prepare event consultation, manage CRM records, improve services, maintain website security and comply with legal obligations.']],
                ['Disclosure', ['We do not sell personal data. Data may be shared only with authorized team members, service providers needed for operations, or government agencies when required by law.']],
                ['Retention and security', ['Data is stored only as long as necessary for business contact, CRM management, service delivery and legal requirements. We use reasonable technical and organizational measures to protect the data.']],
                ['Your rights', ['You may request access, correction, deletion, restriction, objection or withdrawal of consent where applicable under Thai personal data protection law. Contact us at Contact@bigevent.co.th.']],
                ['Contact', ['BIG EVENT CO., LTD., 131 Moo 5, Phon Kho Subdistrict, Mueang Sisaket District, Sisaket 33000. Email: Contact@bigevent.co.th.']],
            ],
            'Privacy policy for BIG EVENT CO., LTD. covering contact forms, CRM data, admin accounts and personal data protection.'
        );
        return;
    }

    legal_policy_layout(
        'Privacy',
        'นโยบายความเป็นส่วนตัว',
        'นโยบายนี้อธิบายการเก็บ ใช้ และคุ้มครองข้อมูลส่วนบุคคลที่ส่งผ่านเว็บไซต์ของบริษัท บิ๊กอีเว้นท์ จำกัด',
        [
            ['ข้อมูลส่วนบุคคลที่เราเก็บ', ['เราอาจเก็บชื่อผู้ติดต่อ เบอร์โทรศัพท์ ประเภทงาน วันที่จัดงาน สถานที่ และข้อมูลที่คุณให้ไว้โดยสมัครใจผ่านฟอร์ม เว็บไซต์ โทรศัพท์ อีเมล LINE OA หรือช่องทางติดต่ออื่น', 'สำหรับผู้ดูแลระบบ อาจมีข้อมูลบัญชี เช่น ชื่อ อีเมล สิทธิ์การใช้งาน และข้อมูล session เพื่อใช้จัดการเว็บไซต์']],
            ['วัตถุประสงค์ในการใช้ข้อมูล', ['เราใช้ข้อมูลเพื่อการติดต่อกลับ ให้คำปรึกษาและประเมินงานอีเวนต์ จัดเก็บใน CRM ปรับปรุงบริการ ดูแลความปลอดภัยของระบบ และปฏิบัติตามกฎหมายที่เกี่ยวข้อง']],
            ['การเปิดเผยข้อมูล', ['เราไม่ขายข้อมูลส่วนบุคคล ข้อมูลอาจถูกเข้าถึงโดยทีมงานที่ได้รับอนุญาต ผู้ให้บริการที่จำเป็นต่อการดำเนินงาน หรือหน่วยงานรัฐเมื่อกฎหมายกำหนด']],
            ['ระยะเวลาเก็บรักษาและความปลอดภัย', ['เราจะเก็บข้อมูลเท่าที่จำเป็นต่อการติดต่อทางธุรกิจ การจัดการ CRM การให้บริการ และข้อกำหนดทางกฎหมาย พร้อมใช้มาตรการที่เหมาะสมเพื่อป้องกันข้อมูล']],
            ['สิทธิของเจ้าของข้อมูล', ['คุณสามารถขอเข้าถึง แก้ไข ลบ ระงับการใช้ คัดค้าน หรือถอนความยินยอมได้ตามที่กฎหมายคุ้มครองข้อมูลส่วนบุคคลกำหนด โดยติดต่อ Contact@bigevent.co.th']],
            ['ช่องทางติดต่อ', ['บริษัท บิ๊กอีเว้นท์ จำกัด 131 หมู่ 5 ตำบลโพนค้อ อำเภอเมืองศรีสะเกษ จังหวัดศรีสะเกษ 33000 อีเมล Contact@bigevent.co.th']],
        ],
        'นโยบายความเป็นส่วนตัวของบริษัท บิ๊กอีเว้นท์ จำกัด ครอบคลุมฟอร์มติดต่อ ข้อมูล CRM บัญชีผู้ดูแลระบบ และการคุ้มครองข้อมูลส่วนบุคคล'
    );
}

function cookie_policy_page(): void
{
    if (current_lang() === 'en') {
        legal_policy_layout(
            'Cookies',
            'Cookie Policy',
            'This policy explains how this website uses cookies and similar technologies.',
            [
                ['What are cookies?', ['Cookies are small files stored on your browser to help the website remember settings, maintain sessions and improve user experience.']],
                ['Types of cookies we use', ['Necessary cookies: required for website operation, admin login sessions, CSRF protection and language routing.', 'Analytics or marketing cookies: may be used in the future for Google Analytics, Facebook Pixel, TikTok Pixel or similar tools only after consent where required.']],
                ['Cookie consent', ['When you visit the website, you can choose to accept all cookies or allow only necessary cookies. Your choice is stored in your browser so the banner does not appear repeatedly.']],
                ['Managing cookies', ['You can clear or block cookies through your browser settings. Blocking necessary cookies may affect login, form submission or some website functions.']],
                ['Contact', ['For cookie-related questions, contact Contact@bigevent.co.th.']],
            ],
            'Cookie policy for BIG EVENT CO., LTD. explaining necessary cookies, consent and future analytics or marketing cookies.'
        );
        return;
    }

    legal_policy_layout(
        'Cookies',
        'นโยบายคุกกี้',
        'นโยบายนี้อธิบายการใช้คุกกี้และเทคโนโลยีที่คล้ายกันบนเว็บไซต์นี้',
        [
            ['คุกกี้คืออะไร', ['คุกกี้คือไฟล์ขนาดเล็กที่ถูกเก็บไว้ในเบราว์เซอร์ เพื่อช่วยให้เว็บไซต์จดจำการตั้งค่า รักษา session และทำให้การใช้งานสะดวกขึ้น']],
            ['ประเภทคุกกี้ที่เราใช้', ['คุกกี้ที่จำเป็น: ใช้เพื่อให้เว็บไซต์ทำงานได้ เช่น session การเข้าสู่ระบบแอดมิน การป้องกัน CSRF และระบบภาษา', 'คุกกี้วิเคราะห์หรือการตลาด: อาจใช้ในอนาคต เช่น Google Analytics, Facebook Pixel, TikTok Pixel หรือเครื่องมือที่คล้ายกัน โดยจะใช้เมื่อได้รับความยินยอมตามที่กฎหมายกำหนด']],
            ['การยินยอมคุกกี้', ['เมื่อเข้าเว็บไซต์ คุณสามารถเลือกยอมรับทั้งหมด หรืออนุญาตเฉพาะคุกกี้ที่จำเป็น ระบบจะจดจำตัวเลือกไว้ในเบราว์เซอร์เพื่อไม่ให้แถบแจ้งเตือนแสดงซ้ำบ่อย ๆ']],
            ['การจัดการคุกกี้', ['คุณสามารถลบหรือบล็อกคุกกี้ได้จากการตั้งค่าเบราว์เซอร์ แต่หากบล็อกคุกกี้ที่จำเป็น อาจทำให้การเข้าสู่ระบบ การส่งฟอร์ม หรือบางฟังก์ชันทำงานไม่สมบูรณ์']],
            ['ช่องทางติดต่อ', ['หากมีคำถามเกี่ยวกับคุกกี้ ติดต่อได้ที่ Contact@bigevent.co.th']],
        ],
        'นโยบายคุกกี้ของบริษัท บิ๊กอีเว้นท์ จำกัด อธิบายคุกกี้ที่จำเป็น การยินยอม และคุกกี้วิเคราะห์หรือการตลาดในอนาคต'
    );
}

function contact_page(): void
{
    $lang = current_lang();
    $flash = flash();
    layout($lang === 'en' ? 'Contact Us' : 'ติดต่อเรา', function () use ($flash) {
        ?>
        <section class="px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto grid max-w-7xl gap-8 lg:grid-cols-[.9fr_1.1fr]">
                <div>
                    <p class="text-sm font-extrabold uppercase tracking-[0.25em] text-coral">Contact</p>
                    <h1 class="mt-4 text-4xl font-extrabold"><?= current_lang() === 'en' ? 'Contact BIG EVENT CO., LTD.' : 'ติดต่อบริษัท บิ๊กอีเว้นท์ จำกัด' ?></h1>
                    <p class="mt-5 text-lg leading-8 text-slate-600"><?= current_lang() === 'en' ? 'Send your name, phone number, event type, date and venue. Our team will contact you to estimate the right format and budget.' : 'ส่งชื่อ เบอร์โทร ประเภทงาน วันที่จัดงาน และสถานที่ ทีมงานจะติดต่อกลับเพื่อช่วยประเมินรูปแบบและงบประมาณ' ?></p>
                    <div class="mt-8">
                        <?= contact_links_html('light') ?>
                    </div>
                    <a href="<?= e(setting('google_maps_url', 'https://maps.app.goo.gl/S1gbvFiQm2sfMyjt8')) ?>" target="_blank" rel="noopener" class="mt-6 inline-flex items-center gap-2 rounded-2xl bg-white px-5 py-3 text-sm font-extrabold text-slate-900 shadow-sm ring-1 ring-slate-200 hover:bg-slate-950 hover:text-white">
                        <i data-lucide="map" class="h-4 w-4"></i>
                        <?= current_lang() === 'en' ? 'Open Google Maps' : 'เปิดแผนที่ Google Maps' ?>
                    </a>
                </div>
                <form method="post" action="<?= e(url_for('/contact')) ?>" class="rounded-[2rem] bg-white p-6 shadow-soft">
                    <?= csrf_field() ?>
                    <input type="hidden" name="source_path" value="<?= e(path()) ?>">
                    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="admin-label"><?= current_lang() === 'en' ? 'Contact name' : 'ชื่อผู้ติดต่อ' ?></label><input class="admin-field" name="name" required placeholder="<?= current_lang() === 'en' ? 'Contact name' : 'ชื่อผู้ติดต่อ' ?>"></div>
                        <div><label class="admin-label"><?= current_lang() === 'en' ? 'Phone number' : 'เบอร์โทรศัพท์' ?></label><input class="admin-field" name="phone" required inputmode="tel" placeholder="08x-xxx-xxxx"></div>
                        <div><label class="admin-label"><?= current_lang() === 'en' ? 'Event type' : 'ประเภทงาน' ?></label><input class="admin-field" name="event_type" placeholder="<?= current_lang() === 'en' ? 'Seminar, booth, launch...' : 'สัมมนา, บูธ, เปิดตัวสินค้า...' ?>"></div>
                        <div><label class="admin-label"><?= current_lang() === 'en' ? 'Event date' : 'วันที่จัดงาน' ?></label><input class="admin-field" name="event_date" type="date"></div>
                        <div class="sm:col-span-2"><label class="admin-label"><?= current_lang() === 'en' ? 'Venue' : 'สถานที่' ?></label><input class="admin-field" name="venue" placeholder="<?= current_lang() === 'en' ? 'Venue / province' : 'สถานที่ / จังหวัด' ?>"></div>
                    </div>
                    <?php if ($flash): ?>
                        <div class="mt-5 flex items-start gap-3 rounded-2xl border px-4 py-3 text-sm font-extrabold shadow-sm <?= $flash['type'] === 'error' ? 'border-red-100 bg-red-50 text-red-700' : 'border-emerald-100 bg-emerald-50 text-emerald-700' ?>">
                            <i data-lucide="<?= $flash['type'] === 'error' ? 'alert-circle' : 'check-circle-2' ?>" class="mt-0.5 h-5 w-5 shrink-0"></i>
                            <span><?= e($flash['message']) ?></span>
                        </div>
                    <?php endif; ?>
                    <button class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-slate-950 px-5 py-4 text-sm font-extrabold text-white hover:bg-coral"><?= current_lang() === 'en' ? 'Send Request' : 'ส่งข้อมูล' ?> <i data-lucide="send" class="h-4 w-4"></i></button>
                </form>
            </div>
        </section>
        <?php
    }, $lang === 'en' ? 'Contact Bigevent for event quotations and consultation for product launches, corporate events, exhibitions and organizational events.' : 'ติดต่อ Bigevent เพื่อขอใบเสนอราคาและปรึกษาการจัดงานอีเวนต์ Product Launch, Corporate Event, Exhibition และงานองค์กร');
}

function handle_contact_submission(): void
{
    verify_csrf();
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        redirect(url_for('/contact'));
    }
    $lastSubmit = (int) ($_SESSION['contact_last_submit'] ?? 0);
    if ($lastSubmit && time() - $lastSubmit < 45) {
        flash(current_lang() === 'en' ? 'Please wait a moment before sending another request.' : 'กรุณารอสักครู่ก่อนส่งข้อมูลอีกครั้ง', 'error');
        redirect(url_for('/contact'));
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    if ($name === '' || $phone === '') {
        flash(current_lang() === 'en' ? 'Please enter your name and phone number.' : 'กรุณากรอกชื่อและเบอร์โทรศัพท์', 'error');
        redirect(url_for('/contact'));
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash(current_lang() === 'en' ? 'Please enter a valid email address.' : 'กรุณากรอกอีเมลให้ถูกต้อง', 'error');
        redirect(url_for('/contact'));
    }

    $stmt = db()->prepare("
        INSERT INTO inquiries (name, phone, email, event_type, event_date, venue, guest_count, budget, message, source_path)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $name,
        $phone,
        $email,
        trim((string) ($_POST['event_type'] ?? '')),
        trim((string) ($_POST['event_date'] ?? '')),
        trim((string) ($_POST['venue'] ?? '')),
        trim((string) ($_POST['guest_count'] ?? '')),
        trim((string) ($_POST['budget'] ?? '')),
        trim((string) ($_POST['message'] ?? '')),
        trim((string) ($_POST['source_path'] ?? path())),
    ]);
    $_SESSION['contact_last_submit'] = time();
    notify_crm_inquiry((int) db()->lastInsertId());

    flash(current_lang() === 'en' ? 'Thank you. Our team will contact you shortly.' : 'ขอบคุณครับ ทีมงานได้รับข้อมูลแล้วและจะติดต่อกลับโดยเร็ว');
    redirect(url_for('/contact'));
}

function notify_crm_inquiry(int $id): void
{
    $stmt = db()->prepare("SELECT * FROM inquiries WHERE id = ?");
    $stmt->execute([$id]);
    $lead = $stmt->fetch();
    if (!$lead) {
        return;
    }

    $subject = 'New CRM Lead: ' . $lead['name'];
    $body = implode("\n", [
        'มีลูกค้ากรอกฟอร์มใหม่',
        'ชื่อ: ' . ($lead['name'] ?? '-'),
        'โทร: ' . ($lead['phone'] ?? '-'),
        'อีเมล: ' . ($lead['email'] ?? '-'),
        'ประเภทงาน: ' . ($lead['event_type'] ?? '-'),
        'วันที่จัดงาน: ' . ($lead['event_date'] ?? '-'),
        'สถานที่: ' . ($lead['venue'] ?? '-'),
        'จำนวนแขก: ' . ($lead['guest_count'] ?? '-'),
        'งบประมาณ: ' . ($lead['budget'] ?? '-'),
        'รายละเอียด: ' . ($lead['message'] ?? '-'),
        'ดูในหลังบ้าน: ' . absolute_url('/admin/crm/view?id=' . (int) $lead['id']),
    ]);

    $notificationEmail = setting('crm_notification_email', CRM_NOTIFICATION_EMAIL);
    if ($notificationEmail !== '') {
        @mail($notificationEmail, $subject, $body, "Content-Type: text/plain; charset=UTF-8\r\n");
    }

    $webhook = setting('crm_line_webhook_url', getenv(CRM_LINE_WEBHOOK_ENV) ?: '');
    if ($webhook !== '') {
        $payload = json_encode(['text' => $body], JSON_UNESCAPED_UNICODE);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 3,
            ],
        ]);
        @file_get_contents($webhook, false, $context);
    }
}

function robots_txt()
{
    header('Content-Type: text/plain; charset=UTF-8');
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "Disallow: /admin\n";
    echo "Sitemap: " . absolute_url('/sitemap.xml') . "\n";
    exit;
}

function sitemap_xml()
{
    $urls = [];
    foreach (['/', '/about', '/services', '/portfolio', '/clients', '/articles', '/contact', '/privacy-policy', '/cookie-policy'] as $staticPath) {
        $priority = $staticPath === '/' ? '1.0' : (in_array($staticPath, ['/services', '/portfolio'], true) ? '0.9' : '0.8');
        $changefreq = in_array($staticPath, ['/', '/portfolio', '/articles'], true) ? 'weekly' : 'monthly';
        $alternates = [
            'th-TH' => absolute_url(localized_url('th', $staticPath)),
            'en' => absolute_url(localized_url('en', $staticPath)),
            'x-default' => absolute_url(localized_url('th', $staticPath)),
        ];
        $urls[] = ['loc' => $alternates['th-TH'], 'priority' => $priority, 'changefreq' => $changefreq, 'alternates' => $alternates];
        $urls[] = ['loc' => $alternates['en'], 'priority' => $priority, 'changefreq' => $changefreq, 'alternates' => $alternates];
    }

    $stmt = db()->query("SELECT slug, slug_en, COALESCE(published_at, created_at) AS updated_at FROM articles WHERE is_published = 1 ORDER BY published_at DESC, id DESC");
    foreach ($stmt->fetchAll() as $article) {
        $alternates = [
            'th-TH' => absolute_url('/articles/' . $article['slug']),
            'en' => absolute_url('/en/articles/' . ($article['slug_en'] ?: $article['slug'])),
            'x-default' => absolute_url('/articles/' . $article['slug']),
        ];
        $urls[] = [
            'loc' => $alternates['th-TH'],
            'priority' => '0.7',
            'changefreq' => 'monthly',
            'lastmod' => date('Y-m-d', strtotime($article['updated_at'] ?: 'now')),
            'alternates' => $alternates,
        ];
        $urls[] = [
            'loc' => $alternates['en'],
            'priority' => '0.7',
            'changefreq' => 'monthly',
            'lastmod' => date('Y-m-d', strtotime($article['updated_at'] ?: 'now')),
            'alternates' => $alternates,
        ];
    }

    $portfolioStmt = db()->query("SELECT slug, slug_en, created_at FROM portfolios ORDER BY sort_order ASC, id DESC");
    foreach ($portfolioStmt->fetchAll() as $portfolio) {
        if (!empty($portfolio['slug'])) {
            $alternates = [
                'th-TH' => absolute_url('/portfolio/' . $portfolio['slug']),
                'en' => absolute_url('/en/portfolio/' . ($portfolio['slug_en'] ?: $portfolio['slug'])),
                'x-default' => absolute_url('/portfolio/' . $portfolio['slug']),
            ];
            $urls[] = [
                'loc' => $alternates['th-TH'],
                'priority' => '0.75',
                'changefreq' => 'monthly',
                'lastmod' => date('Y-m-d', strtotime($portfolio['created_at'] ?: 'now')),
                'alternates' => $alternates,
            ];
            $urls[] = [
                'loc' => $alternates['en'],
                'priority' => '0.75',
                'changefreq' => 'monthly',
                'lastmod' => date('Y-m-d', strtotime($portfolio['created_at'] ?: 'now')),
                'alternates' => $alternates,
            ];
        }
    }

    header('Content-Type: application/xml; charset=UTF-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n";
    foreach ($urls as $url) {
        echo "  <url>\n";
        echo "    <loc>" . e($url['loc']) . "</loc>\n";
        foreach (($url['alternates'] ?? []) as $hreflang => $href) {
            echo "    <xhtml:link rel=\"alternate\" hreflang=\"" . e($hreflang) . "\" href=\"" . e($href) . "\" />\n";
        }
        if (!empty($url['lastmod'])) {
            echo "    <lastmod>" . e($url['lastmod']) . "</lastmod>\n";
        }
        echo "    <changefreq>" . e($url['changefreq']) . "</changefreq>\n";
        echo "    <priority>" . e($url['priority']) . "</priority>\n";
        echo "  </url>\n";
    }
    echo "</urlset>\n";
    exit;
}

function login_page(): void
{
    if (is_admin()) {
        redirect('/admin');
    }
    $flash = flash();
    ?>
    <!doctype html>
    <html lang="th">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin Login | <?= APP_NAME ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="/assets/css/app.css">
    </head>
    <body class="grid min-h-screen place-items-center bg-slate-950 p-4 text-white">
        <form method="post" action="/admin/login" class="w-full max-w-md rounded-[2rem] border border-white/10 bg-white p-8 text-slate-950 shadow-2xl">
            <?= csrf_field() ?>
            <a href="/" class="mb-8 inline-flex text-sm font-bold text-slate-500 hover:text-coral">กลับหน้าบ้าน</a>
            <h1 class="text-3xl font-extrabold">เข้าสู่ระบบแอดมิน</h1>
            <p class="mt-2 text-sm text-slate-500">จัดการคอนเทนต์เว็บไซต์ออแกไนเซอร์</p>
            <?php if ($flash): ?>
                <div class="mt-5 rounded-2xl bg-red-50 px-4 py-3 text-sm font-semibold text-red-700"><?= e($flash['message']) ?></div>
            <?php endif; ?>
            <div class="mt-6 space-y-4">
                <div>
                    <label class="admin-label">Email</label>
                    <input class="admin-field" name="email" type="email" placeholder="<?= ADMIN_EMAIL ?>" required>
                </div>
                <div>
                    <label class="admin-label">Password</label>
                    <input class="admin-field" name="password" type="password" placeholder="••••••••" required>
                </div>
            </div>
            <button class="mt-6 w-full rounded-2xl bg-slate-950 px-5 py-4 text-sm font-extrabold text-white hover:bg-coral">เข้าสู่ระบบ</button>
        </form>
    </body>
    </html>
    <?php
}

function handle_login(): void
{
    verify_csrf();
    $stmt = db()->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$_POST['email'] ?? '']);
    $user = $stmt->fetch();
    if (!$user || !password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        flash('อีเมลหรือรหัสผ่านไม่ถูกต้อง', 'error');
        if (($_POST['_login_modal'] ?? '') === '1') {
            $back = (string) ($_POST['redirect_to'] ?? '/');
            if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//') || str_starts_with($back, '/admin')) {
                $back = '/';
            }
            redirect($back . (str_contains($back, '?') ? '&' : '?') . 'admin_login=1');
        }
        redirect('/admin/login');
    }
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_name'] = $user['name'];
    $_SESSION['admin_role'] = $user['role'] ?? 'manager';
    flash('ยินดีต้อนรับเข้าสู่หลังบ้าน');
    redirect('/admin');
}

function admin_dashboard(): void
{
    admin_layout('ภาพรวม', function () {
        $stats = [
            ['แบนเนอร์', (int) db()->query("SELECT COUNT(*) FROM banners")->fetchColumn(), 'image'],
            ['ผลงาน', (int) db()->query("SELECT COUNT(*) FROM portfolios")->fetchColumn(), 'briefcase-business'],
            ['โลโก้ลูกค้า', (int) db()->query("SELECT COUNT(*) FROM clients")->fetchColumn(), 'handshake'],
            ['บทความ', (int) db()->query("SELECT COUNT(*) FROM articles")->fetchColumn(), 'newspaper'],
            ['CRM ลูกค้า', (int) db()->query("SELECT COUNT(*) FROM inquiries")->fetchColumn(), 'message-square-text'],
            ['เครื่องมือ', 0, 'wrench'],
            ['ผู้ใช้งาน', (int) db()->query("SELECT COUNT(*) FROM users")->fetchColumn(), 'users'],
        ];
        ?>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            <?php foreach ($stats as [$label, $count, $icon]): ?>
                <div class="min-w-0 rounded-[1.5rem] bg-white p-6 shadow-sm">
                    <div class="mb-5 grid h-11 w-11 place-items-center rounded-2xl bg-slate-100"><i data-lucide="<?= $icon ?>" class="h-5 w-5"></i></div>
                    <div class="text-3xl font-extrabold"><?= $count ?></div>
                    <div class="mt-1 truncate text-sm font-semibold text-slate-500"><?= e($label) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-8 rounded-[1.5rem] bg-white p-6 shadow-sm">
            <h2 class="text-xl font-extrabold">เริ่มจัดการเว็บ</h2>
            <p class="mt-2 text-sm leading-7 text-slate-600">ใช้เมนูด้านซ้ายเพื่อเพิ่ม/แก้ไขแบนเนอร์ ผลงาน โลโก้ลูกค้า และบทความ คอนเทนต์ที่เปิดเผยแพร่จะไปแสดงบนหน้าบ้านทันที</p>
        </div>
        <?php
    });
}

function admin_tools(): void
{
    admin_layout('เครื่องมือ', function () {
        ?>
        <div class="rounded-[1.5rem] bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Admin Tools</p>
                    <h2 class="mt-2 text-2xl font-extrabold">ศูนย์รวมเครื่องมือสำหรับแอดมิน</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-7 text-slate-600">หน้านี้เตรียมไว้สำหรับเพิ่มโมดูลเครื่องมือในอนาคต ตอนนี้ยังไม่มีเครื่องมือที่เปิดใช้งาน</p>
                </div>
                <span class="inline-flex w-fit items-center gap-2 rounded-full bg-slate-100 px-4 py-2 text-xs font-extrabold text-slate-600">
                    <i data-lucide="blocks" class="h-4 w-4"></i>
                    0 โมดูล
                </span>
            </div>
        </div>
        <div class="mt-5 rounded-[1.5rem] border border-dashed border-slate-200 bg-white/70 p-10 text-center">
            <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-500">
                <i data-lucide="wrench" class="h-6 w-6"></i>
            </div>
            <h3 class="mt-4 text-lg font-extrabold">ยังไม่มีเครื่องมือในหน้านี้</h3>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-7 text-slate-500">เมื่อพร้อมเพิ่มเครื่องมือใหม่ สามารถนำมาวางเป็นโมดูลในหน้านี้ได้ เช่น SEO, จัดการรูปภาพ, แจ้งเตือน หรือระบบ automation ต่างๆ</p>
        </div>
        <?php
    });
}

function admin_settings(): void
{
    require_super_admin();
    $groups = settings_schema();
    admin_layout('ตั้งค่า', function () use ($groups) {
        ?>
        <form method="post" action="/admin/settings/save" class="space-y-5">
            <?= csrf_field() ?>
            <div class="rounded-[1.5rem] bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">System Settings</p>
                        <h2 class="mt-2 text-2xl font-extrabold">ตั้งค่าระบบโปรเจกต์</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-7 text-slate-600">รวมค่าระบบสำคัญไว้ที่เดียว เช่น API, การแจ้งเตือน, ข้อมูลโปรเจกต์ และ checklist ก่อนขึ้น production เฉพาะ Super Admin เท่านั้นที่เห็นเมนูนี้</p>
                    </div>
                    <button class="inline-flex shrink-0 items-center justify-center gap-2 rounded-2xl bg-slate-950 px-5 py-3 text-sm font-extrabold text-white hover:bg-coral">
                        <i data-lucide="save" class="h-4 w-4"></i> บันทึกการตั้งค่า
                    </button>
                </div>
            </div>

            <div class="grid gap-5 xl:grid-cols-2">
                <?php foreach ($groups as $group): ?>
                    <section class="min-w-0 rounded-[1.5rem] bg-white p-5 shadow-sm">
                        <div class="mb-5 flex items-start gap-3">
                            <div class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-slate-100 text-slate-800">
                                <i data-lucide="<?= e($group['icon']) ?>" class="h-5 w-5"></i>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-lg font-extrabold"><?= e($group['title']) ?></h3>
                                <p class="mt-1 text-xs font-semibold leading-5 text-slate-500"><?= e($group['description']) ?></p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <?php foreach ($group['fields'] as $key => [$label, $type, $hint]): ?>
                                <div>
                                    <label class="admin-label" for="setting-<?= e($key) ?>"><?= e($label) ?></label>
                                    <?php if ($type === 'textarea'): ?>
                                        <textarea id="setting-<?= e($key) ?>" class="admin-field min-h-28" name="settings[<?= e($key) ?>]" placeholder="<?= e($hint) ?>"><?= e(setting((string) $key)) ?></textarea>
                                    <?php else: ?>
                                        <input id="setting-<?= e($key) ?>" class="admin-field" type="<?= e($type) ?>" name="settings[<?= e($key) ?>]" value="<?= e(setting((string) $key)) ?>" placeholder="<?= e($hint) ?>">
                                    <?php endif; ?>
                                    <p class="mt-1 text-xs font-semibold leading-5 text-slate-400"><?= e($hint) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </form>
        <?php
    });
}

function save_settings(): void
{
    require_super_admin();
    verify_csrf();
    $allowed = [];
    foreach (settings_schema() as $group) {
        foreach (array_keys($group['fields']) as $key) {
            $allowed[] = (string) $key;
        }
    }

    $posted = $_POST['settings'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }
    foreach ($allowed as $key) {
        save_setting($key, trim((string) ($posted[$key] ?? '')));
    }
    flash('บันทึกการตั้งค่าเรียบร้อยแล้ว');
    redirect('/admin/settings');
}

function inquiry_status_label(string $status): string
{
    return [
        'new' => 'ใหม่',
        'contacted' => 'ติดต่อแล้ว',
        'quoted' => 'ส่งใบเสนอราคา',
        'won' => 'ปิดงานแล้ว',
        'lost' => 'ไม่สำเร็จ',
    ][$status] ?? 'ใหม่';
}

function inquiry_status_class(string $status): string
{
    return [
        'new' => 'bg-coral/10 text-coral ring-coral/15',
        'contacted' => 'bg-sky-50 text-sky-700 ring-sky-100',
        'quoted' => 'bg-amber-50 text-amber-700 ring-amber-100',
        'won' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        'lost' => 'bg-slate-100 text-slate-600 ring-slate-200',
    ][$status] ?? 'bg-slate-100 text-slate-600 ring-slate-200';
}

function admin_crm(): void
{
    $statusFilter = (string) ($_GET['status'] ?? '');
    $keyword = trim((string) ($_GET['q'] ?? ''));
    $page = current_page();
    $perPage = per_page();
    $where = [];
    $params = [];
    if (in_array($statusFilter, ['new', 'contacted', 'quoted', 'won', 'lost'], true)) {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }
    if ($keyword !== '') {
        $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ? OR event_type LIKE ? OR message LIKE ?)';
        $like = '%' . $keyword . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $countStmt = db()->prepare("SELECT COUNT(*) FROM inquiries" . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $sql = "SELECT * FROM inquiries" . $whereSql . " ORDER BY created_at DESC, id DESC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    admin_layout('CRM ลูกค้า', function () use ($rows, $statusFilter, $keyword, $total, $page, $perPage) {
        ?>
        <div class="mb-5 flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <p class="text-sm font-semibold text-slate-500">ลูกค้าที่กรอกฟอร์มติดต่อจากหน้าบ้าน</p>
                <p class="mt-1 text-xs font-semibold leading-6 text-slate-400">ทุกสิทธิ์สามารถเข้าดู CRM ได้ เพื่อให้ทีมติดตามงานต่อได้ทันที</p>
            </div>
            <a href="/admin/crm/new" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-2xl bg-slate-950 px-4 py-3 text-sm font-extrabold text-white hover:bg-coral">
                <i data-lucide="plus" class="h-4 w-4"></i> เพิ่มลูกค้า
            </a>
        </div>
        <form method="get" action="/admin/crm" class="mb-5 grid gap-3 rounded-[1.5rem] bg-white p-4 shadow-sm md:grid-cols-[1fr_220px_auto_auto_auto]">
            <input class="admin-field" name="q" value="<?= e($keyword) ?>" placeholder="ค้นหาชื่อ เบอร์ อีเมล ประเภทงาน หรือรายละเอียด">
            <select class="admin-field bg-white" name="status">
                <option value="">ทุกสถานะ</option>
                <?php foreach (['new', 'contacted', 'quoted', 'won', 'lost'] as $status): ?>
                    <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(inquiry_status_label($status)) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-extrabold text-white hover:bg-coral">ค้นหา</button>
            <a href="/admin/crm" class="rounded-2xl bg-slate-100 px-5 py-3 text-center text-sm font-extrabold text-slate-700 hover:bg-slate-200">ล้าง</a>
            <a href="/admin/crm/export?<?= e(http_build_query(['q' => $keyword, 'status' => $statusFilter])) ?>" class="rounded-2xl bg-emerald-50 px-5 py-3 text-center text-sm font-extrabold text-emerald-700 hover:bg-emerald-100">Export CSV</a>
        </form>
        <div class="overflow-hidden rounded-[1.5rem] bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[940px] table-fixed text-left text-sm xl:min-w-full">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="w-[25%] px-5 py-4">ลูกค้า</th>
                            <th class="w-[25%] px-5 py-4">ข้อมูลงาน</th>
                            <th class="w-[14%] px-5 py-4">สถานะ</th>
                            <th class="w-[16%] px-5 py-4">วันที่ส่ง</th>
                            <th class="w-[20%] px-5 py-4 text-right">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="px-5 py-4">
                                <div class="truncate font-extrabold"><?= e($row['name']) ?></div>
                                <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs font-semibold text-slate-500">
                                    <?php if ($row['phone']): ?><a class="hover:text-coral" href="tel:<?= e(preg_replace('/\D+/', '', (string) $row['phone'])) ?>"><?= e($row['phone']) ?></a><?php endif; ?>
                                    <?php if ($row['email']): ?><a class="hover:text-coral" href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a><?php endif; ?>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <div class="truncate font-bold"><?= e($row['event_type'] ?: 'ไม่ระบุประเภทงาน') ?></div>
                                <div class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500"><?= e($row['message'] ?: trim(($row['venue'] ?? '') . ' ' . ($row['guest_count'] ?? '') . ' ' . ($row['budget'] ?? ''))) ?></div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-3 py-1 text-xs font-bold ring-1 <?= e(inquiry_status_class((string) $row['status'])) ?>"><?= e(inquiry_status_label((string) $row['status'])) ?></span>
                            </td>
                            <td class="px-5 py-4 text-slate-500"><?= e(date('Y-m-d H:i', strtotime((string) $row['created_at']))) ?></td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="/admin/crm/view?id=<?= (int) $row['id'] ?>" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold hover:bg-slate-200">ดูรายละเอียด</a>
                                    <a href="/admin/crm/edit?id=<?= (int) $row['id'] ?>" class="rounded-xl bg-sky-50 px-3 py-2 text-xs font-bold text-sky-700 hover:bg-sky-100">แก้ไข</a>
                                    <form method="post" action="/admin/crm/delete" onsubmit="return confirm('ยืนยันการลบข้อมูลลูกค้านี้?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-600 hover:bg-red-100">ลบ</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5" class="px-5 py-10 text-center text-sm font-semibold text-slate-400">ยังไม่มีลูกค้ากรอกฟอร์มเข้ามา</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?= pagination_html($total, $page, $perPage) ?>
        <?php
    });
}

function export_crm_csv()
{
    require_admin();
    $statusFilter = (string) ($_GET['status'] ?? '');
    $keyword = trim((string) ($_GET['q'] ?? ''));
    $where = [];
    $params = [];
    if (in_array($statusFilter, ['new', 'contacted', 'quoted', 'won', 'lost'], true)) {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }
    if ($keyword !== '') {
        $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ? OR event_type LIKE ? OR message LIKE ?)';
        $like = '%' . $keyword . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $stmt = db()->prepare("SELECT * FROM inquiries" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY created_at DESC, id DESC");
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="crm-leads-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Name', 'Phone', 'Email', 'Event Type', 'Event Date', 'Venue', 'Guests', 'Budget', 'Message', 'Status', 'Admin Note', 'Source', 'Created At']);
    foreach ($stmt->fetchAll() as $row) {
        fputcsv($out, [
            $row['id'], $row['name'], $row['phone'], $row['email'], $row['event_type'], $row['event_date'],
            $row['venue'], $row['guest_count'], $row['budget'], $row['message'], inquiry_status_label((string) $row['status']),
            $row['admin_note'], $row['source_path'], $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

function admin_crm_view(int $id): void
{
    $stmt = db()->prepare("SELECT * FROM inquiries WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        not_found();
    }

    if (empty($row['viewed_at'])) {
        $markStmt = db()->prepare("UPDATE inquiries SET viewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $markStmt->execute([$id]);
        $row['viewed_at'] = date('Y-m-d H:i:s');
    }

    admin_layout('รายละเอียด CRM', function () use ($row) {
        $fields = [
            'ชื่อผู้ติดต่อ' => $row['name'],
            'เบอร์โทร' => $row['phone'],
            'อีเมล' => $row['email'],
            'ประเภทงาน' => $row['event_type'],
            'วันที่จัดงาน' => $row['event_date'],
            'สถานที่' => $row['venue'],
            'จำนวนแขก' => $row['guest_count'],
            'งบประมาณ' => $row['budget'],
            'หน้าที่ส่งข้อมูล' => $row['source_path'],
            'วันที่ส่ง' => date('Y-m-d H:i', strtotime((string) $row['created_at'])),
        ];
        ?>
        <div class="grid gap-6 xl:grid-cols-[1fr_420px]">
            <div class="rounded-[1.5rem] bg-white p-6 shadow-sm">
                <div class="mb-6 flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div>
                        <p class="text-xs font-extrabold uppercase tracking-[0.22em] text-coral">CRM Lead</p>
                        <h2 class="mt-2 text-2xl font-extrabold"><?= e($row['name']) ?></h2>
                    </div>
                    <span class="w-fit rounded-full px-3 py-1 text-xs font-bold ring-1 <?= e(inquiry_status_class((string) $row['status'])) ?>"><?= e(inquiry_status_label((string) $row['status'])) ?></span>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <?php foreach ($fields as $label => $value): ?>
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <div class="text-xs font-bold text-slate-400"><?= e($label) ?></div>
                            <div class="mt-1 break-words text-sm font-extrabold text-slate-800"><?= e((string) ($value ?: '-')) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-5 rounded-2xl bg-slate-50 p-4">
                    <div class="text-xs font-bold text-slate-400">รายละเอียดงาน</div>
                    <p class="mt-2 whitespace-pre-line break-words text-sm leading-7 text-slate-700"><?= e((string) ($row['message'] ?: '-')) ?></p>
                </div>
            </div>
            <div class="space-y-4">
                <div class="rounded-[1.5rem] bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-extrabold">ช่องทางติดต่อ</h3>
                    <div class="mt-4 grid gap-2">
                        <?php if ($row['phone']): ?><a href="tel:<?= e(preg_replace('/\D+/', '', (string) $row['phone'])) ?>" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-950 px-4 py-3 text-sm font-extrabold text-white hover:bg-coral"><i data-lucide="phone-call" class="h-4 w-4"></i> โทรหาลูกค้า</a><?php endif; ?>
                        <?php if ($row['email']): ?><a href="mailto:<?= e($row['email']) ?>" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-100 px-4 py-3 text-sm font-extrabold text-slate-800 hover:bg-slate-200"><i data-lucide="mail" class="h-4 w-4"></i> ส่งอีเมล</a><?php endif; ?>
                    </div>
                </div>
                <form method="post" action="/admin/crm/update" class="rounded-[1.5rem] bg-white p-6 shadow-sm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <label class="admin-label">สถานะ</label>
                    <select name="status" class="admin-field bg-white">
                        <?php foreach (['new', 'contacted', 'quoted', 'won', 'lost'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $row['status'] === $status ? 'selected' : '' ?>><?= e(inquiry_status_label($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="admin-label mt-4">โน้ตภายใน</label>
                    <textarea name="admin_note" class="admin-field min-h-36"><?= e((string) ($row['admin_note'] ?? '')) ?></textarea>
                    <button class="mt-4 w-full rounded-2xl bg-slate-950 px-5 py-3 text-sm font-extrabold text-white hover:bg-coral">บันทึก CRM</button>
                    <a href="/admin/crm/edit?id=<?= (int) $row['id'] ?>" class="mt-3 inline-flex w-full justify-center rounded-2xl bg-sky-50 px-5 py-3 text-sm font-extrabold text-sky-700 hover:bg-sky-100">แก้ไขข้อมูลลูกค้า</a>
                    <a href="/admin/crm" class="mt-3 inline-flex w-full justify-center rounded-2xl bg-slate-100 px-5 py-3 text-sm font-extrabold text-slate-700 hover:bg-slate-200">กลับรายการ CRM</a>
                </form>
                <form method="post" action="/admin/crm/delete" onsubmit="return confirm('ยืนยันการลบข้อมูลลูกค้านี้?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button class="w-full rounded-2xl bg-red-50 px-5 py-3 text-sm font-extrabold text-red-600 hover:bg-red-100">ลบข้อมูลลูกค้านี้</button>
                </form>
            </div>
        </div>
        <?php
    });
}

function admin_crm_form(?int $id = null): void
{
    $row = null;
    if ($id) {
        $stmt = db()->prepare("SELECT * FROM inquiries WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            not_found();
        }
    }

    admin_layout($id ? 'แก้ไขข้อมูลลูกค้า' : 'เพิ่มลูกค้า CRM', function () use ($row, $id) {
        $status = (string) ($row['status'] ?? 'new');
        ?>
        <form method="post" action="/admin/crm/save" class="w-full rounded-[1.5rem] bg-white p-5 shadow-sm sm:p-6">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label class="admin-label">ชื่อผู้ติดต่อ</label>
                    <input class="admin-field" name="name" value="<?= e((string) ($row['name'] ?? '')) ?>" required>
                </div>
                <div>
                    <label class="admin-label">เบอร์โทร</label>
                    <input class="admin-field" name="phone" value="<?= e((string) ($row['phone'] ?? '')) ?>" required inputmode="tel">
                </div>
                <div>
                    <label class="admin-label">อีเมล</label>
                    <input class="admin-field" name="email" type="email" value="<?= e((string) ($row['email'] ?? '')) ?>">
                </div>
                <div>
                    <label class="admin-label">สถานะ</label>
                    <select name="status" class="admin-field bg-white">
                        <?php foreach (['new', 'contacted', 'quoted', 'won', 'lost'] as $item): ?>
                            <option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e(inquiry_status_label($item)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="admin-label">ประเภทงาน</label>
                    <input class="admin-field" name="event_type" value="<?= e((string) ($row['event_type'] ?? '')) ?>">
                </div>
                <div>
                    <label class="admin-label">วันที่จัดงาน</label>
                    <input class="admin-field" name="event_date" type="date" value="<?= e((string) ($row['event_date'] ?? '')) ?>">
                </div>
                <div>
                    <label class="admin-label">สถานที่</label>
                    <input class="admin-field" name="venue" value="<?= e((string) ($row['venue'] ?? '')) ?>">
                </div>
                <div>
                    <label class="admin-label">จำนวนแขก</label>
                    <input class="admin-field" name="guest_count" value="<?= e((string) ($row['guest_count'] ?? '')) ?>">
                </div>
                <div class="md:col-span-2">
                    <label class="admin-label">งบประมาณ</label>
                    <input class="admin-field" name="budget" value="<?= e((string) ($row['budget'] ?? '')) ?>">
                </div>
                <div class="md:col-span-2">
                    <label class="admin-label">รายละเอียดงาน</label>
                    <textarea class="admin-field min-h-36" name="message"><?= e((string) ($row['message'] ?? '')) ?></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="admin-label">โน้ตภายใน</label>
                    <textarea class="admin-field min-h-32" name="admin_note"><?= e((string) ($row['admin_note'] ?? '')) ?></textarea>
                </div>
            </div>
            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <a href="<?= $id ? '/admin/crm/view?id=' . (int) $id : '/admin/crm' ?>" class="rounded-2xl bg-slate-100 px-5 py-3 text-center text-sm font-extrabold text-slate-700 hover:bg-slate-200">ยกเลิก</a>
                <button class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-extrabold text-white hover:bg-coral">บันทึกข้อมูลลูกค้า</button>
            </div>
        </form>
        <?php
    });
}

function save_crm(): void
{
    require_admin();
    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $status = (string) ($_POST['status'] ?? 'new');
    if ($name === '' || $phone === '') {
        flash('กรุณากรอกชื่อและเบอร์โทรศัพท์', 'error');
        redirect($id ? '/admin/crm/edit?id=' . $id : '/admin/crm/new');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('กรุณากรอกอีเมลให้ถูกต้อง', 'error');
        redirect($id ? '/admin/crm/edit?id=' . $id : '/admin/crm/new');
    }
    if (!in_array($status, ['new', 'contacted', 'quoted', 'won', 'lost'], true)) {
        $status = 'new';
    }

    $data = [
        $name,
        $phone,
        $email,
        trim((string) ($_POST['event_type'] ?? '')),
        trim((string) ($_POST['event_date'] ?? '')),
        trim((string) ($_POST['venue'] ?? '')),
        trim((string) ($_POST['guest_count'] ?? '')),
        trim((string) ($_POST['budget'] ?? '')),
        trim((string) ($_POST['message'] ?? '')),
        $status,
        trim((string) ($_POST['admin_note'] ?? '')),
    ];

    if ($id) {
        $stmt = db()->prepare("UPDATE inquiries SET name=?, phone=?, email=?, event_type=?, event_date=?, venue=?, guest_count=?, budget=?, message=?, status=?, admin_note=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmt->execute([...$data, $id]);
        flash('อัปเดตข้อมูลลูกค้าเรียบร้อยแล้ว');
        redirect('/admin/crm/view?id=' . $id);
    }

    $stmt = db()->prepare("INSERT INTO inquiries (name, phone, email, event_type, event_date, venue, guest_count, budget, message, status, admin_note, source_path, viewed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $stmt->execute([...$data, 'manual']);
    $newId = (int) db()->lastInsertId();
    flash('เพิ่มลูกค้า CRM เรียบร้อยแล้ว');
    redirect('/admin/crm/view?id=' . $newId);
}

function delete_crm(): void
{
    require_admin();
    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare("DELETE FROM inquiries WHERE id = ?");
    $stmt->execute([$id]);
    flash('ลบข้อมูลลูกค้าเรียบร้อยแล้ว');
    redirect('/admin/crm');
}

function admin_backup(): void
{
    $files = array_merge(
        glob(__DIR__ . '/storage/backups/*.sqlite') ?: [],
        glob(__DIR__ . '/storage/backups/*.sql') ?: []
    );
    rsort($files);
    admin_layout('Backup ฐานข้อมูล', function () use ($files) {
        ?>
        <div class="mb-5 flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <p class="text-sm font-semibold text-slate-500">สำรองฐานข้อมูลก่อนแก้ไขใหญ่หรือก่อนขึ้น Production</p>
                <p class="mt-1 text-xs font-semibold text-slate-400">ไฟล์จะถูกเก็บไว้ใน storage/backups</p>
            </div>
            <form method="post" action="/admin/backup/create">
                <?= csrf_field() ?>
                <button class="inline-flex items-center gap-2 rounded-2xl bg-slate-950 px-5 py-3 text-sm font-extrabold text-white hover:bg-coral">
                    <i data-lucide="database-backup" class="h-4 w-4"></i> สร้าง Backup
                </button>
            </form>
        </div>
        <div class="overflow-hidden rounded-[1.5rem] bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr><th class="px-5 py-4">ไฟล์</th><th class="px-5 py-4">ขนาด</th><th class="px-5 py-4 text-right">ดาวน์โหลด</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($files as $file): ?>
                    <?php $name = basename($file); ?>
                    <tr>
                        <td class="px-5 py-4 font-bold"><?= e($name) ?></td>
                        <td class="px-5 py-4 text-slate-500"><?= e(number_format(filesize($file) / 1024, 1)) ?> KB</td>
                        <td class="px-5 py-4 text-right"><a class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold hover:bg-slate-200" href="/admin/backup/download?file=<?= e(rawurlencode($name)) ?>">ดาวน์โหลด</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$files): ?><tr><td colspan="3" class="px-5 py-10 text-center text-slate-400">ยังไม่มีไฟล์ Backup</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    });
}

function create_backup(): void
{
    require_admin();
    verify_csrf();

    if (db_is_mysql(db())) {
        $target = __DIR__ . '/storage/backups/database-' . date('Ymd-His') . '.sql';
        $ok = (bool) file_put_contents($target, database_dump_sql());
    } else {
        $source = __DIR__ . '/storage/database.sqlite';
        $target = __DIR__ . '/storage/backups/database-' . date('Ymd-His') . '.sqlite';
        $ok = is_file($source) && copy($source, $target);
    }

    if ($ok) {
        flash('สร้าง Backup เรียบร้อยแล้ว');
    } else {
        flash('ไม่สามารถสร้าง Backup ได้', 'error');
    }
    redirect('/admin/backup');
}

function download_backup()
{
    require_admin();
    $file = basename(rawurldecode((string) ($_GET['file'] ?? '')));
    if (!preg_match('/^database-\d{8}-\d{6}\.(sqlite|sql)$/', $file)) {
        not_found();
    }
    $path = __DIR__ . '/storage/backups/' . $file;
    if (!is_file($path)) {
        not_found();
    }
    header('Content-Type: ' . (str_ends_with($file, '.sql') ? 'application/sql; charset=UTF-8' : 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function database_dump_sql(): string
{
    $pdo = db();
    $tables = ['users', 'banners', 'portfolios', 'clients', 'articles', 'gallery_images', 'inquiries', 'system_settings'];
    $sql = "-- Bigevent database backup\n-- Generated: " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $rows = $pdo->query('SELECT * FROM ' . db_identifier($table))->fetchAll();
        if (!$rows) {
            continue;
        }
        $sql .= "DELETE FROM " . db_identifier($table) . ";\n";
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $values = array_map(
                fn ($value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                array_values($row)
            );
            $sql .= 'INSERT INTO ' . db_identifier($table) . ' (' . implode(', ', array_map('db_identifier', $columns)) . ') VALUES (' . implode(', ', $values) . ");\n";
        }
        $sql .= "\n";
    }

    return $sql . "SET FOREIGN_KEY_CHECKS=1;\n";
}

function password_form(): void
{
    admin_layout('เปลี่ยนรหัสผ่าน', function () {
        ?>
        <form method="post" action="/admin/account/password" class="max-w-2xl rounded-[1.5rem] bg-white p-6 shadow-sm">
            <?= csrf_field() ?>
            <label class="admin-label">รหัสผ่านปัจจุบัน</label>
            <input class="admin-field" type="password" name="current_password" required>
            <label class="admin-label mt-4">รหัสผ่านใหม่</label>
            <input class="admin-field" type="password" name="password" minlength="8" required>
            <label class="admin-label mt-4">ยืนยันรหัสผ่านใหม่</label>
            <input class="admin-field" type="password" name="password_confirm" minlength="8" required>
            <button class="mt-5 rounded-2xl bg-slate-950 px-5 py-3 text-sm font-extrabold text-white hover:bg-coral">เปลี่ยนรหัสผ่าน</button>
        </form>
        <?php
    });
}

function update_password(): void
{
    require_admin();
    verify_csrf();
    $admin = current_admin();
    $stmt = db()->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([(int) $admin['id']]);
    $hash = (string) $stmt->fetchColumn();
    $current = (string) ($_POST['current_password'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (!password_verify($current, $hash)) {
        flash('รหัสผ่านปัจจุบันไม่ถูกต้อง', 'error');
        redirect('/admin/account/password');
    }
    if (mb_strlen($password) < 8 || $password !== (string) ($_POST['password_confirm'] ?? '')) {
        flash('รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษรและยืนยันให้ตรงกัน', 'error');
        redirect('/admin/account/password');
    }
    $update = db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $update->execute([password_hash($password, PASSWORD_DEFAULT), (int) $admin['id']]);
    flash('เปลี่ยนรหัสผ่านเรียบร้อยแล้ว');
    redirect('/admin');
}

function update_crm(): void
{
    require_admin();
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? 'new');
    if (!in_array($status, ['new', 'contacted', 'quoted', 'won', 'lost'], true)) {
        $status = 'new';
    }
    $stmt = db()->prepare("UPDATE inquiries SET status = ?, admin_note = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$status, trim((string) ($_POST['admin_note'] ?? '')), $id]);
    flash('บันทึก CRM เรียบร้อยแล้ว');
    redirect('/admin/crm/view?id=' . $id);
}

function admin_users(): void
{
    $rows = db()->query("SELECT id, name, email, role, created_at FROM users ORDER BY CASE role WHEN 'super_admin' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END, id ASC")->fetchAll();
    admin_layout('ผู้ใช้งาน', function () use ($rows) {
        ?>
        <div class="mb-5 flex flex-col justify-between gap-4 xl:flex-row xl:items-center">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-500">จัดการผู้ดูแลระบบและสิทธิ์การเข้าถึงหลังบ้าน</p>
                <p class="mt-1 max-w-5xl text-xs font-semibold leading-6 text-slate-400">Super Admin ทำได้ทุกอย่าง, Admin ลบ Super Admin ไม่ได้, Manager ลบได้เฉพาะ Manager</p>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button form="bulk-users-form" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-2xl bg-red-50 px-4 py-3 text-sm font-extrabold text-red-600 hover:bg-red-100">
                    <i data-lucide="trash-2" class="h-4 w-4"></i> ลบรายการที่เลือก
                </button>
                <a href="/admin/users/new" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-2xl bg-slate-950 px-4 py-3 text-sm font-extrabold text-white hover:bg-coral">
                    <i data-lucide="user-plus" class="h-4 w-4"></i> เพิ่มผู้ใช้งาน
                </a>
            </div>
        </div>
        <form id="bulk-users-form" method="post" action="/admin/users/bulk-delete" onsubmit="return confirmBulkDelete(this, 'ผู้ใช้งานที่เลือก')">
            <?= csrf_field() ?>
        </form>
        <div class="overflow-hidden rounded-[1.5rem] bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] table-fixed text-left text-sm xl:min-w-full">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="w-[5%] px-5 py-4">
                                <input type="checkbox" class="bulk-select-all h-4 w-4 rounded border-slate-300" data-target=".bulk-user-checkbox" aria-label="เลือกผู้ใช้งานทั้งหมด">
                            </th>
                            <th class="w-[43%] px-5 py-4">ผู้ใช้งาน</th>
                            <th class="w-[18%] px-5 py-4">สิทธิ์</th>
                            <th class="w-[16%] px-5 py-4">วันที่สร้าง</th>
                            <th class="w-[18%] px-5 py-4 text-right">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="px-5 py-4">
                                <?php if (can_delete_user($row)): ?>
                                    <input form="bulk-users-form" type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" class="bulk-user-checkbox h-4 w-4 rounded border-slate-300" aria-label="เลือก <?= e($row['name']) ?>">
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex min-w-0 items-center gap-4">
                                    <div class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-slate-950 text-sm font-extrabold text-white">
                                        <?= e(mb_substr((string) $row['name'], 0, 1)) ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="truncate font-extrabold"><?= e($row['name']) ?></div>
                                        <div class="mt-1 truncate text-xs text-slate-500"><?= e($row['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-3 py-1 text-xs font-bold ring-1 <?= e(role_badge_class((string) $row['role'])) ?>">
                                    <?= e(role_label((string) $row['role'])) ?>
                                </span>
                            </td>
                            <td class="px-5 py-4 text-slate-500"><?= e(date('Y-m-d', strtotime((string) $row['created_at']))) ?></td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="/admin/users/edit?id=<?= (int) $row['id'] ?>" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold hover:bg-slate-200">แก้ไข</a>
                                    <?php if (can_delete_user($row)): ?>
                                        <form method="post" action="/admin/users/delete" onsubmit="return confirm('ยืนยันการลบผู้ใช้งานนี้?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-600 hover:bg-red-100">ลบ</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="rounded-xl bg-slate-50 px-3 py-2 text-xs font-bold text-slate-400">ลบไม่ได้</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    });
}

function admin_user_form(?int $id = null): void
{
    $row = null;
    if ($id) {
        $stmt = db()->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            not_found();
        }
    }

    admin_layout($id ? 'แก้ไขผู้ใช้งาน' : 'เพิ่มผู้ใช้งาน', function () use ($row, $id) {
        ?>
        <form method="post" action="/admin/users/save" class="w-full rounded-[1.5rem] bg-white p-5 shadow-sm sm:p-6">
            <?= csrf_field() ?>
            <?php if ($id): ?>
                <input type="hidden" name="id" value="<?= (int) $id ?>">
            <?php endif; ?>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label class="admin-label">ชื่อผู้ใช้งาน</label>
                    <input class="admin-field" name="name" value="<?= e((string) ($row['name'] ?? '')) ?>" required>
                </div>
                <div>
                    <label class="admin-label">อีเมล</label>
                    <input class="admin-field" name="email" type="email" value="<?= e((string) ($row['email'] ?? '')) ?>" required>
                </div>
                <div>
                    <label class="admin-label">สิทธิ์</label>
                    <select class="admin-field bg-white" name="role" required>
                        <?php foreach (['super_admin', 'admin', 'manager'] as $role): ?>
                            <option value="<?= e($role) ?>" <?= (($row['role'] ?? 'manager') === $role) ? 'selected' : '' ?>><?= e(role_label($role)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="admin-label"><?= $id ? 'รหัสผ่านใหม่ (ไม่เปลี่ยนให้เว้นว่าง)' : 'รหัสผ่าน' ?></label>
                    <input class="admin-field" name="password" type="password" <?= $id ? '' : 'required' ?> minlength="8" autocomplete="new-password">
                </div>
            </div>
            <div class="mt-6 flex flex-wrap gap-3">
                <button class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-extrabold text-white hover:bg-coral">บันทึกผู้ใช้งาน</button>
                <a href="/admin/users" class="rounded-2xl bg-slate-100 px-5 py-3 text-sm font-extrabold text-slate-700 hover:bg-slate-200">ยกเลิก</a>
            </div>
        </form>
        <?php
    });
}

function save_user(): void
{
    require_admin();
    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $role = (string) ($_POST['role'] ?? 'manager');
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['super_admin', 'admin', 'manager'], true)) {
        flash('กรุณากรอกข้อมูลผู้ใช้งานให้ครบถ้วน', 'error');
        redirect($id ? '/admin/users/edit?id=' . $id : '/admin/users/new');
    }
    if ($id === 0 && mb_strlen($password) < 8) {
        flash('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร', 'error');
        redirect('/admin/users/new');
    }
    if ($id > 0 && $password !== '' && mb_strlen($password) < 8) {
        flash('รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร', 'error');
        redirect('/admin/users/edit?id=' . $id);
    }

    $duplicate = db()->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
    $duplicate->execute([$email, $id]);
    if ((int) $duplicate->fetchColumn() > 0) {
        flash('อีเมลนี้ถูกใช้งานแล้ว', 'error');
        redirect($id ? '/admin/users/edit?id=' . $id : '/admin/users/new');
    }
    if ($id > 0 && $role !== 'super_admin') {
        $existing = db()->prepare("SELECT role FROM users WHERE id = ?");
        $existing->execute([$id]);
        if (($existing->fetchColumn() ?: '') === 'super_admin') {
            $superCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
            if ($superCount <= 1) {
                flash('ต้องมี Super Admin อย่างน้อย 1 คนในระบบ', 'error');
                redirect('/admin/users/edit?id=' . $id);
            }
        }
    }

    if ($id > 0) {
        if ($password !== '') {
            $stmt = db()->prepare("UPDATE users SET name = ?, email = ?, role = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$name, $email, $role, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $stmt = db()->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
            $stmt->execute([$name, $email, $role, $id]);
        }
        if ($id === (int) ($_SESSION['admin_id'] ?? 0)) {
            $_SESSION['admin_name'] = $name;
            $_SESSION['admin_role'] = $role;
        }
        flash('อัปเดตผู้ใช้งานเรียบร้อยแล้ว');
    } else {
        $stmt = db()->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        flash('เพิ่มผู้ใช้งานเรียบร้อยแล้ว');
    }

    redirect('/admin/users');
}

function delete_user(): void
{
    require_admin();
    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) {
        not_found();
    }
    if (!can_delete_user($target)) {
        flash('สิทธิ์ของคุณไม่สามารถลบผู้ใช้งานนี้ได้', 'error');
        redirect('/admin/users');
    }

    $delete = db()->prepare("DELETE FROM users WHERE id = ?");
    $delete->execute([$id]);
    flash('ลบผู้ใช้งานเรียบร้อยแล้ว');
    redirect('/admin/users');
}

function bulk_delete_users(): void
{
    require_admin();
    verify_csrf();

    $ids = array_values(array_unique(array_map('intval', $_POST['ids'] ?? [])));
    if (!$ids) {
        flash('กรุณาเลือกผู้ใช้งานที่ต้องการลบ', 'error');
        redirect('/admin/users');
    }

    $deleted = 0;
    $select = db()->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
    $delete = db()->prepare("DELETE FROM users WHERE id = ?");
    foreach ($ids as $id) {
        $select->execute([$id]);
        $target = $select->fetch();
        if ($target && can_delete_user($target)) {
            $delete->execute([$id]);
            $deleted += $delete->rowCount();
        }
    }

    flash($deleted > 0 ? 'ลบผู้ใช้งานที่เลือกเรียบร้อยแล้ว' : 'ไม่มีผู้ใช้งานที่ลบได้ตามสิทธิ์ของคุณ', $deleted > 0 ? 'success' : 'error');
    redirect('/admin/users');
}

function resource_label(string $resource): string
{
    return [
        'banners' => 'แบนเนอร์',
        'portfolio' => 'ผลงาน',
        'clients' => 'โลโก้ลูกค้า',
        'articles' => 'บทความ',
    ][$resource] ?? $resource;
}

function table_for(string $resource): string
{
    return $resource === 'portfolio' ? 'portfolios' : $resource;
}

function admin_resource(string $resource): void
{
    $table = table_for($resource);
    $page = current_page();
    $perPage = per_page();
    $total = (int) db()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $stmt = db()->query("SELECT * FROM {$table} ORDER BY sort_order ASC, id DESC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset);
    $rows = $stmt->fetchAll();
    admin_layout(resource_label($resource), function () use ($resource, $rows, $total, $page, $perPage) {
        ?>
        <div class="mb-5 flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <p class="min-w-0 text-sm font-semibold leading-6 text-slate-500">จัดการ<?= e(resource_label($resource)) ?>ของเว็บไซต์</p>
            <div class="flex flex-col gap-2 sm:flex-row">
                <?php if ($resource === 'clients'): ?>
                    <button form="reorder-clients-form" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-2xl bg-emerald-50 px-4 py-3 text-sm font-extrabold text-emerald-700 hover:bg-emerald-100">
                        <i data-lucide="save" class="h-4 w-4"></i> บันทึกการเรียงโลโก้
                    </button>
                <?php endif; ?>
                <button form="bulk-<?= $resource ?>-form" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-2xl bg-red-50 px-4 py-3 text-sm font-extrabold text-red-600 hover:bg-red-100">
                    <i data-lucide="trash-2" class="h-4 w-4"></i> ลบรายการที่เลือก
                </button>
                <a href="/admin/<?= $resource ?>/new" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-2xl bg-slate-950 px-4 py-3 text-sm font-extrabold text-white hover:bg-coral">
                    <i data-lucide="plus" class="h-4 w-4"></i> เพิ่มใหม่
                </a>
            </div>
        </div>
        <form id="bulk-<?= $resource ?>-form" method="post" action="/admin/<?= $resource ?>/bulk-delete" onsubmit="return confirmBulkDelete(this, '<?= e(resource_label($resource)) ?>ที่เลือก')">
            <?= csrf_field() ?>
        </form>
        <?php if ($resource === 'clients'): ?>
            <form id="reorder-clients-form" method="post" action="/admin/clients/reorder">
                <?= csrf_field() ?>
                <input type="hidden" name="ids" id="clientOrderInput" value="<?= e(implode(',', array_map(static fn($row) => (int) $row['id'], $rows))) ?>">
            </form>
            <p class="mb-4 rounded-2xl bg-white px-4 py-3 text-xs font-bold leading-6 text-slate-500 shadow-sm">ลากไอคอนหรือกดลูกศรขึ้นลงเพื่อจัดเรียงโลโก้ แล้วกด “บันทึกการเรียงโลโก้”</p>
        <?php endif; ?>
        <div class="overflow-hidden rounded-[1.5rem] bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] table-fixed text-left text-sm xl:min-w-full">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="w-[5%] px-5 py-4">
                                <input type="checkbox" class="bulk-select-all h-4 w-4 rounded border-slate-300" data-target=".bulk-<?= $resource ?>-checkbox" aria-label="เลือกทั้งหมด">
                            </th>
                            <th class="<?= $resource === 'clients' ? 'w-[8%]' : 'w-[0%]' ?> px-5 py-4"><?= $resource === 'clients' ? 'ย้าย' : '' ?></th>
                            <th class="<?= $resource === 'clients' ? 'w-[49%]' : 'w-[60%]' ?> px-5 py-4">รายการ</th>
                            <th class="w-[14%] px-5 py-4">สถานะ</th>
                            <th class="w-[24%] px-5 py-4 text-right">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 <?= $resource === 'clients' ? 'sortable-logo-list' : '' ?>">
                    <?php foreach ($rows as $row): ?>
                        <tr <?= $resource === 'clients' ? 'draggable="true" data-sort-id="' . (int) $row['id'] . '"' : '' ?>>
                            <td class="px-5 py-4">
                                <input form="bulk-<?= $resource ?>-form" type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" class="bulk-<?= $resource ?>-checkbox h-4 w-4 rounded border-slate-300" aria-label="เลือก <?= e($row['title'] ?? $row['name']) ?>">
                            </td>
                            <td class="px-5 py-4">
                                <?php if ($resource === 'clients'): ?>
                                    <div class="flex items-center gap-1">
                                        <button type="button" class="drag-handle grid h-10 w-10 cursor-grab place-items-center rounded-xl bg-slate-100 text-slate-500 active:cursor-grabbing" aria-label="ลากเพื่อย้ายตำแหน่ง">
                                            <i data-lucide="grip-vertical" class="h-4 w-4"></i>
                                        </button>
                                        <div class="grid gap-1">
                                            <button type="button" class="move-logo-up grid h-5 w-7 place-items-center rounded-lg bg-slate-50 text-slate-500 hover:bg-slate-100" aria-label="ย้ายขึ้น">
                                                <i data-lucide="chevron-up" class="h-3.5 w-3.5"></i>
                                            </button>
                                            <button type="button" class="move-logo-down grid h-5 w-7 place-items-center rounded-lg bg-slate-50 text-slate-500 hover:bg-slate-100" aria-label="ย้ายลง">
                                                <i data-lucide="chevron-down" class="h-3.5 w-3.5"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex min-w-0 items-center gap-4">
                                    <img src="<?= e(image_src($row['image_path'] ?? $row['logo_path'] ?? null)) ?>" class="h-14 w-20 shrink-0 rounded-xl object-cover" alt="">
                                    <div class="min-w-0">
                                        <div class="truncate font-extrabold"><?= e($row['title'] ?? $row['name']) ?></div>
                                        <div class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500"><?= e($row['category'] ?? $row['excerpt'] ?? $row['subtitle'] ?? $row['website'] ?? '') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <?php $active = (int)($row['is_active'] ?? $row['is_published'] ?? $row['is_featured'] ?? 0); ?>
                                <span class="rounded-full <?= $active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' ?> px-3 py-1 text-xs font-bold">
                                    <?= $active ? 'แสดงผล' : 'ปิด/ไม่เด่น' ?>
                                </span>
                            </td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <?php if ($resource === 'portfolio'): ?>
                                        <a href="<?= portfolio_url($row) ?>" target="_blank" class="rounded-xl bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 hover:bg-emerald-100">ดูหน้า</a>
                                    <?php endif; ?>
                                    <a href="/admin/<?= $resource ?>/edit?id=<?= (int)$row['id'] ?>" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold hover:bg-slate-200">แก้ไข</a>
                                    <form method="post" action="/admin/<?= $resource ?>/delete" onsubmit="return confirm('ยืนยันการลบรายการนี้?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-600 hover:bg-red-100">ลบ</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?= pagination_html($total, $page, $perPage) ?>
        <?php
    });
}

function admin_form(string $resource, ?int $id = null): void
{
    $table = table_for($resource);
    $row = $id ? find_row($table, $id) : null;
    if ($id && !$row) {
        not_found();
    }
    $title = ($id ? 'แก้ไข' : 'เพิ่ม') . resource_label($resource);
    admin_layout($title, function () use ($resource, $row, $id) {
        $galleryOwnerType = $resource === 'portfolio' ? 'portfolio' : ($resource === 'articles' ? 'article' : '');
        $galleryImages = ($id && $galleryOwnerType) ? gallery_items($galleryOwnerType, $id) : [];
        ?>
        <form method="post" enctype="multipart/form-data" action="/admin/<?= $resource ?>/save" class="w-full rounded-[1.5rem] bg-white p-5 shadow-sm sm:p-6">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
            <div class="grid gap-5 md:grid-cols-2">
                <?php if ($resource === 'banners'): ?>
                    <?= input('title', 'หัวข้อหลัก', $row['title'] ?? '') ?>
                    <?= input('title_en', 'หัวข้อหลัก EN', $row['title_en'] ?? '') ?>
                    <input type="hidden" name="sort_order" value="<?= e((string) ($row['sort_order'] ?? 0)) ?>">
                    <?= textarea('subtitle', 'คำอธิบาย', $row['subtitle'] ?? '', 'md:col-span-2') ?>
                    <?= textarea('subtitle_en', 'คำอธิบาย EN', $row['subtitle_en'] ?? '', 'md:col-span-2') ?>
                    <?= input('cta_label', 'ข้อความปุ่ม', $row['cta_label'] ?? '') ?>
                    <?= input('cta_label_en', 'ข้อความปุ่ม EN', $row['cta_label_en'] ?? '') ?>
                    <?= input('cta_url', 'ลิงก์ปุ่ม', $row['cta_url'] ?? '/portfolio') ?>
                    <?= checkbox('is_active', 'เปิดใช้งานแบนเนอร์', (int)($row['is_active'] ?? 1)) ?>
                    <?= image_input('image', 'รูปแบนเนอร์', $row['image_path'] ?? null, 'md:col-span-2') ?>
                <?php elseif ($resource === 'portfolio'): ?>
                    <?= input('title', 'ชื่องาน', $row['title'] ?? '') ?>
                    <?= input('title_en', 'ชื่องาน EN', $row['title_en'] ?? '') ?>
                    <?= input('slug', 'Slug URL', $row['slug'] ?? '') ?>
                    <?= input('slug_en', 'Slug URL EN', $row['slug_en'] ?? '') ?>
                    <?= input('category', 'ประเภทงาน', $row['category'] ?? '') ?>
                    <?= input('category_en', 'ประเภทงาน EN', $row['category_en'] ?? '') ?>
                    <?= input('client', 'ลูกค้า', $row['client'] ?? '') ?>
                    <?= input('client_en', 'ลูกค้า EN', $row['client_en'] ?? '') ?>
                    <?= input('location', 'สถานที่', $row['location'] ?? '') ?>
                    <?= input('location_en', 'สถานที่ EN', $row['location_en'] ?? '') ?>
                    <?= input('event_date', 'วันที่จัดงาน', $row['event_date'] ?? '', 'date') ?>
                    <input type="hidden" name="sort_order" value="<?= e((string) ($row['sort_order'] ?? 0)) ?>">
                    <?= input('video_url', 'Video URL', $row['video_url'] ?? ($row['video_url_en'] ?? '')) ?>
                    <?= textarea('description', 'รายละเอียดผลงาน', $row['description'] ?? '', 'md:col-span-2') ?>
                    <?= textarea('description_en', 'รายละเอียดผลงาน EN', $row['description_en'] ?? '', 'md:col-span-2') ?>
                    <?= checkbox('is_featured', 'แสดงในหน้าแรก', (int)($row['is_featured'] ?? 0)) ?>
                    <?= portfolio_media_input($row['image_path'] ?? null, $galleryImages, 'md:col-span-2') ?>
                    <?= seo_editor_panel('portfolio', $row, 'md:col-span-2') ?>
                <?php elseif ($resource === 'clients'): ?>
                    <?= input('name', 'ชื่อลูกค้า', $row['name'] ?? '') ?>
                    <?= input('name_en', 'ชื่อลูกค้า EN', $row['name_en'] ?? '') ?>
                    <?= input('website', 'เว็บไซต์', $row['website'] ?? '') ?>
                    <input type="hidden" name="sort_order" value="<?= e((string) ($row['sort_order'] ?? 0)) ?>">
                    <?= checkbox('is_active', 'แสดงโลโก้บนหน้าบ้าน', (int)($row['is_active'] ?? 1)) ?>
                    <?= image_input('logo', 'ไฟล์โลโก้', $row['logo_path'] ?? null, 'md:col-span-2') ?>
                <?php elseif ($resource === 'articles'): ?>
                    <?= input('title', 'ชื่อบทความ', $row['title'] ?? '') ?>
                    <?= input('title_en', 'ชื่อบทความ EN', $row['title_en'] ?? '') ?>
                    <?= input('slug', 'Slug URL', $row['slug'] ?? '') ?>
                    <?= input('slug_en', 'Slug URL EN', $row['slug_en'] ?? '') ?>
                    <?= input('published_at', 'วันที่เผยแพร่', $row['published_at'] ?? date('Y-m-d'), 'date') ?>
                    <?= checkbox('is_published', 'เผยแพร่บทความ', (int)($row['is_published'] ?? 1)) ?>
                    <?= textarea('excerpt', 'เกริ่นนำ', $row['excerpt'] ?? '', 'md:col-span-2') ?>
                    <?= textarea('excerpt_en', 'เกริ่นนำ EN', $row['excerpt_en'] ?? '', 'md:col-span-2') ?>
                    <?= textarea('content', 'เนื้อหาบทความ', $row['content'] ?? '', 'md:col-span-2 min-h-64') ?>
                    <?= textarea('content_en', 'เนื้อหาบทความ EN', $row['content_en'] ?? '', 'md:col-span-2 min-h-64') ?>
                    <?= image_input('image', 'รูปบทความ', $row['image_path'] ?? null, 'md:col-span-2') ?>
                    <?= seo_editor_panel('articles', $row, 'md:col-span-2') ?>
                <?php endif; ?>
            </div>

            <?php if ($resource === 'articles'): ?>
                <?= gallery_upload_input($galleryImages) ?>
            <?php endif; ?>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <a href="/admin/<?= $resource ?>" class="rounded-2xl bg-slate-100 px-5 py-3 text-sm font-extrabold text-slate-700 hover:bg-slate-200">ยกเลิก</a>
                <button class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-extrabold text-white hover:bg-coral">บันทึก</button>
            </div>
        </form>
        <?php if (!empty($galleryImages)): ?>
            <?php foreach ($galleryImages as $image): ?>
                <form id="delete-gallery-<?= (int) $image['id'] ?>" class="gallery-delete-form" method="post" action="/admin/<?= $resource ?>/gallery/delete" data-gallery-delete-form data-gallery-id="<?= (int) $image['id'] ?>" onsubmit="return confirm('ยืนยันการลบรูปนี้?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $image['id'] ?>">
                    <input type="hidden" name="owner_id" value="<?= (int) $id ?>">
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
    });
}

function input(string $name, string $label, $value = '', string $type = 'text'): string
{
    return '<div><label class="admin-label">' . e($label) . '</label><input class="admin-field" type="' . e($type) . '" name="' . e($name) . '" value="' . e((string)$value) . '"></div>';
}

function textarea(string $name, string $label, $value = '', string $class = ''): string
{
    return '<div class="' . e($class) . '"><label class="admin-label">' . e($label) . '</label><textarea class="admin-field min-h-32" name="' . e($name) . '">' . e((string)$value) . '</textarea></div>';
}

function checkbox(string $name, string $label, int $checked = 0): string
{
    return '<label class="flex items-center gap-3 rounded-2xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-700"><input type="checkbox" name="' . e($name) . '" value="1" ' . ($checked ? 'checked' : '') . ' class="h-4 w-4 rounded border-slate-300"> ' . e($label) . '</label>';
}

function seo_editor_panel(string $resource, ?array $row, string $class = ''): string
{
    $row = $row ?: [];
    $isPortfolio = $resource === 'portfolio';
    $title = (string) ($row['title'] ?? '');
    $titleEn = (string) ($row['title_en'] ?? '');
    $slug = (string) ($row['slug'] ?? '');
    $slugEn = (string) ($row['slug_en'] ?? '');
    $summary = (string) ($isPortfolio ? ($row['description'] ?? '') : ($row['excerpt'] ?? ''));
    $summaryEn = (string) ($isPortfolio ? ($row['description_en'] ?? '') : ($row['excerpt_en'] ?? ''));
    $seoTitle = trim((string) ($row['seo_title'] ?? '')) ?: $title;
    $seoTitleEn = trim((string) ($row['seo_title_en'] ?? '')) ?: ($titleEn ?: $seoTitle);
    $meta = trim((string) ($row['meta_description'] ?? '')) ?: mb_substr(trim(strip_tags($summary)), 0, 160);
    $metaEn = trim((string) ($row['meta_description_en'] ?? '')) ?: mb_substr(trim(strip_tags($summaryEn ?: $summary)), 0, 160);
    $siteUrl = rtrim(setting('site_url', 'https://www.bigevent.co.th'), '/');
    $path = $isPortfolio ? '/portfolio/' : '/articles/';
    $score = seo_panel_score($seoTitle, $meta, (string) ($row['seo_focus_keyphrase'] ?? ''));
    $scoreClass = $score >= 3 ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : ($score >= 2 ? 'bg-amber-50 text-amber-700 ring-amber-100' : 'bg-red-50 text-red-700 ring-red-100');
    $scoreLabel = $score >= 3 ? 'SEO ดี' : ($score >= 2 ? 'ควรปรับเพิ่ม' : 'ข้อมูลยังน้อย');

    ob_start();
    ?>
    <section class="<?= e($class) ?> rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm" data-seo-panel>
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
            <div class="flex items-start gap-3">
                <div class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-slate-100 text-slate-500">
                    <i data-lucide="search-check" class="h-5 w-5"></i>
                </div>
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.22em] text-coral">SEO รายหน้า</p>
                    <h2 class="mt-1 text-xl font-extrabold">รายงาน SEO สำหรับ<?= e($isPortfolio ? 'ผลงานนี้' : 'บทความนี้') ?></h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">ตั้งค่า title, meta description และ focus keyword เฉพาะรายการนี้ คล้าย Yoast SEO ใน WordPress</p>
                </div>
            </div>
            <span class="w-fit rounded-full px-3 py-1 text-xs font-black ring-1 <?= e($scoreClass) ?>"><?= e($scoreLabel) ?></span>
        </div>

        <div class="mt-5 grid gap-5 lg:grid-cols-[1fr_.9fr]">
            <div class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <?= input('seo_focus_keyphrase', 'Focus Keyword TH', $row['seo_focus_keyphrase'] ?? '') ?>
                    <?= input('seo_focus_keyphrase_en', 'Focus Keyword EN', $row['seo_focus_keyphrase_en'] ?? '') ?>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <?= input('seo_title', 'SEO Title TH', $row['seo_title'] ?? '') ?>
                    <?= input('seo_title_en', 'SEO Title EN', $row['seo_title_en'] ?? '') ?>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <?= textarea('meta_description', 'Meta Description TH', $row['meta_description'] ?? '', 'min-h-28') ?>
                    <?= textarea('meta_description_en', 'Meta Description EN', $row['meta_description_en'] ?? '', 'min-h-28') ?>
                </div>
            </div>
            <div class="rounded-[1.25rem] bg-slate-50 p-4 ring-1 ring-slate-100">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <p class="text-sm font-extrabold text-slate-700">Google Preview</p>
                    <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-500 ring-1 ring-slate-200">Mobile / Desktop</span>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100">
                    <div class="flex items-center gap-3">
                        <img src="<?= e(image_src(setting('default_og_image', '/assets/img/og-default.png'))) ?>" alt="" class="h-8 w-8 rounded-full object-cover">
                        <div class="min-w-0">
                            <p class="truncate text-xs text-slate-500"><?= e(setting('project_company_name', 'บริษัท บิ๊กอีเว้นท์ จำกัด')) ?></p>
                            <p class="truncate text-xs text-slate-400"><?= e($siteUrl . $path . ($slug ?: $slugEn ?: 'slug')) ?></p>
                        </div>
                    </div>
                    <p class="mt-3 line-clamp-2 text-lg font-semibold leading-6 text-[#1a0dab]" data-seo-preview-title><?= e($seoTitle ?: 'SEO Title') ?></p>
                    <p class="mt-2 line-clamp-3 text-sm leading-6 text-slate-600" data-seo-preview-description><?= e($meta ?: 'Meta description จะแสดงตัวอย่างคำอธิบายผลค้นหาตรงนี้') ?></p>
                </div>
                <div class="mt-4 grid gap-2 text-xs font-bold text-slate-500">
                    <div class="flex items-center justify-between gap-3 rounded-xl bg-white px-3 py-2">
                        <span>Title length</span>
                        <span class="<?= mb_strlen($seoTitle) >= 35 && mb_strlen($seoTitle) <= 70 ? 'text-emerald-600' : 'text-amber-600' ?>" data-seo-title-count><?= mb_strlen($seoTitle) ?> ตัวอักษร</span>
                    </div>
                    <div class="flex items-center justify-between gap-3 rounded-xl bg-white px-3 py-2">
                        <span>Description length</span>
                        <span class="<?= mb_strlen($meta) >= 90 && mb_strlen($meta) <= 170 ? 'text-emerald-600' : 'text-amber-600' ?>" data-seo-description-count><?= mb_strlen($meta) ?> ตัวอักษร</span>
                    </div>
                    <div class="flex items-center justify-between gap-3 rounded-xl bg-white px-3 py-2">
                        <span>Focus keyword</span>
                        <span class="<?= trim((string) ($row['seo_focus_keyphrase'] ?? '')) !== '' ? 'text-emerald-600' : 'text-amber-600' ?>" data-seo-focus-state><?= trim((string) ($row['seo_focus_keyphrase'] ?? '')) !== '' ? 'มีแล้ว' : 'ยังไม่ได้ใส่' ?></span>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

function seo_panel_score(string $title, string $description, string $focus): int
{
    $score = 0;
    $titleLength = mb_strlen($title);
    $descriptionLength = mb_strlen($description);
    if ($titleLength >= 35 && $titleLength <= 70) {
        $score++;
    }
    if ($descriptionLength >= 90 && $descriptionLength <= 170) {
        $score++;
    }
    if (trim($focus) !== '') {
        $score++;
    }
    return $score;
}

function image_input(string $name, string $label, ?string $current = null, string $class = ''): string
{
    $id = 'upload-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name) . '-' . bin2hex(random_bytes(3));
    ob_start();
    ?>
    <div class="<?= e($class) ?>">
        <section class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
            <div class="flex items-start gap-3">
                <div class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-white text-slate-500 ring-1 ring-slate-200">
                    <i data-lucide="image-up" class="h-5 w-5"></i>
                </div>
                <div>
                    <h2 class="text-lg font-extrabold"><?= e($label) ?></h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">ลากรูปหรือเลือกไฟล์ ระบบจะย่อ บีบอัด และแปลงเป็น WebP อัตโนมัติ</p>
                </div>
            </div>
            <?php if ($current): ?>
                <input type="hidden" name="current_<?= e($name) ?>" value="<?= e($current) ?>">
                <div class="mt-5 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-100">
                    <img src="<?= e($current) ?>" alt="" class="h-44 w-full object-cover">
                    <div class="p-3 text-xs font-bold text-slate-500">รูปปัจจุบัน</div>
                </div>
            <?php endif; ?>
            <div class="admin-upload-zone mt-5 rounded-[1.5rem] border-2 border-dashed border-slate-300 bg-white p-8 text-center transition hover:border-coral hover:bg-coral/5">
                <input id="<?= e($id) ?>" class="sr-only admin-upload-input" type="file" name="<?= e($name) ?>" accept="image/*">
                <label for="<?= e($id) ?>" class="flex cursor-pointer flex-col items-center justify-center gap-3">
                    <span class="grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-500">
                        <i data-lucide="cloud-upload" class="h-7 w-7"></i>
                    </span>
                    <span class="text-base font-extrabold text-slate-700">วางรูปหรือ <span class="text-coral">เลือกไฟล์</span></span>
                    <span class="text-sm font-semibold text-slate-400">JPG, PNG, GIF, WebP</span>
                </label>
                <div class="admin-upload-preview mt-5 hidden grid-cols-2 gap-3 text-left sm:grid-cols-3 lg:grid-cols-4"></div>
            </div>
        </section>
    </div>
    <?php
    return ob_get_clean();
}

function gallery_upload_input(array $galleryImages, string $title = 'Gallery หลายภาพ', string $description = 'อัปโหลดรูปหลายภาพเพื่อแสดงในหน้า view แบบ Featured + Grid + Lightbox'): string
{
    $id = 'gallery-upload-' . bin2hex(random_bytes(3));
    ob_start();
    ?>
    <div class="mt-8 rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
            <div class="flex items-start gap-3">
                <div class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-white text-slate-500 ring-1 ring-slate-200">
                    <i data-lucide="images" class="h-5 w-5"></i>
                </div>
                <div>
                    <h2 class="text-lg font-extrabold"><?= e($title) ?></h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500"><?= e($description) ?></p>
                </div>
            </div>
            <span class="w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500" data-gallery-count><?= count($galleryImages) ?> รูป</span>
        </div>

        <?php if ($galleryImages): ?>
            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <?php foreach ($galleryImages as $index => $image): ?>
                    <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-100 transition" data-gallery-card data-gallery-id="<?= (int) $image['id'] ?>">
                        <img src="<?= e($image['image_path']) ?>" alt="" class="h-36 w-full object-cover">
                        <div class="flex items-center justify-between gap-2 p-3">
                            <span class="text-xs font-semibold text-slate-500">#<?= $index + 1 ?></span>
                            <button type="submit" form="delete-gallery-<?= (int) $image['id'] ?>" class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-600 hover:bg-red-100">ลบรูป</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="admin-upload-zone mt-5 rounded-[1.5rem] border-2 border-dashed border-slate-300 bg-white p-8 text-center transition hover:border-coral hover:bg-coral/5">
            <input id="<?= e($id) ?>" class="sr-only admin-upload-input" type="file" name="gallery_images[]" accept="image/*" multiple>
            <label for="<?= e($id) ?>" class="flex cursor-pointer flex-col items-center justify-center gap-3">
                <span class="grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-500">
                    <i data-lucide="cloud-upload" class="h-7 w-7"></i>
                </span>
                <span class="text-base font-extrabold text-slate-700">วางรูปหรือ <span class="text-coral">เลือกไฟล์</span></span>
                <span class="text-sm font-semibold text-slate-400">JPG, PNG, GIF, WebP - เลือกหลายภาพได้</span>
            </label>
            <div class="admin-upload-preview mt-5 hidden grid-cols-2 gap-3 text-left sm:grid-cols-3 lg:grid-cols-4"></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function portfolio_media_input(?string $currentCover, array $galleryImages, string $class = ''): string
{
    $images = [];
    $usedIds = [];
    foreach ($galleryImages as $image) {
        if ($currentCover && ($image['image_path'] ?? '') === $currentCover) {
            $images[] = ['image_path' => $currentCover, 'id' => (int) ($image['id'] ?? 0), 'is_cover' => true];
            $usedIds[] = (int) ($image['id'] ?? 0);
            break;
        }
    }
    if ($currentCover) {
        $hasCover = false;
        foreach ($images as $image) {
            $hasCover = $hasCover || (($image['image_path'] ?? '') === $currentCover);
        }
        if (!$hasCover) {
            $images[] = ['image_path' => $currentCover, 'id' => 0, 'is_cover' => true];
        }
    }
    foreach ($galleryImages as $image) {
        $path = (string) ($image['image_path'] ?? '');
        $id = (int) ($image['id'] ?? 0);
        if ($path === '' || in_array($id, $usedIds, true)) {
            continue;
        }
        $images[] = ['image_path' => $path, 'id' => $id, 'is_cover' => false];
        $usedIds[] = $id;
    }

    ob_start();
    ?>
    <div class="<?= e($class) ?>">
        <input type="hidden" name="current_image" value="<?= e((string) $currentCover) ?>">
        <section class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
            <input type="hidden" name="gallery_order" class="portfolio-gallery-order" value="<?= e(implode(',', array_filter(array_map(static fn($image) => (int) ($image['id'] ?? 0), $images)))) ?>">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                <div class="flex items-start gap-3">
                    <div class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-white text-slate-500 ring-1 ring-slate-200">
                        <i data-lucide="image" class="h-5 w-5"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-extrabold">รูปภาพผลงาน</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-500">อัปโหลดได้หลายภาพ โดยภาพแรกจะเป็นภาพปก ใช้เป็นพื้นหลังหน้า view, ภาพการ์ด และรูปแชร์ SEO</p>
                    </div>
                </div>
                <span class="w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500" data-gallery-count><?= count($images) ?> รูป</span>
            </div>

            <?php if ($images): ?>
                <p class="mt-5 text-sm font-semibold text-slate-400">ลากรูปเพื่อเรียงลำดับ รูปแรกจะเป็นภาพปก</p>
                <div class="portfolio-gallery-sortable mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ($images as $index => $image): ?>
                        <?php $isCover = $index === 0; ?>
                        <div class="group relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-100 transition <?= !empty($image['id']) ? 'cursor-grab active:cursor-grabbing' : '' ?>" data-gallery-card <?= !empty($image['id']) ? 'draggable="true" data-gallery-id="' . (int) $image['id'] . '"' : '' ?>>
                            <img src="<?= e($image['image_path']) ?>" alt="" class="h-44 w-full object-cover">
                            <div class="absolute inset-x-0 top-0 flex justify-between p-3">
                                <?php if ($isCover): ?>
                                    <span class="cover-badge rounded-xl bg-coral px-3 py-1 text-xs font-extrabold text-white shadow-sm">ปก</span>
                                <?php else: ?>
                                    <span class="cover-badge rounded-xl bg-white/90 px-3 py-1 text-xs font-extrabold text-slate-600 shadow-sm">Gallery</span>
                                <?php endif; ?>
                                <span class="order-badge rounded-xl bg-slate-950/70 px-3 py-1 text-xs font-bold text-white">#<?= $index + 1 ?></span>
                            </div>
                            <?php if (!$isCover && !empty($image['id'])): ?>
                                <div class="p-3 text-right">
                                    <button type="submit" form="delete-gallery-<?= (int) $image['id'] ?>" class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-600 hover:bg-red-100">ลบรูป</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="admin-upload-zone mt-5 rounded-[1.5rem] border-2 border-dashed border-slate-300 bg-white p-8 text-center transition hover:border-coral hover:bg-coral/5" data-cover-first="1">
                <input id="portfolioGalleryInput" class="sr-only admin-upload-input" type="file" name="gallery_images[]" accept="image/*" multiple>
                <label for="portfolioGalleryInput" class="flex cursor-pointer flex-col items-center justify-center gap-3">
                    <span class="grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-500">
                        <i data-lucide="cloud-upload" class="h-7 w-7"></i>
                    </span>
                    <span class="text-base font-extrabold text-slate-700">วางรูปหรือ <span class="text-coral">เลือกไฟล์</span></span>
                    <span class="text-sm font-semibold text-slate-400">JPG, PNG, GIF, WebP - ระบบจะย่อและบีบอัดให้อัตโนมัติ</span>
                </label>
                <div class="admin-upload-preview mt-5 hidden grid-cols-2 gap-3 text-left sm:grid-cols-3 lg:grid-cols-4"></div>
            </div>
        </section>
    </div>
    <?php
    return ob_get_clean();
}

function save_resource(string $resource): void
{
    verify_csrf();
    $pdo = db();
    $id = (int)($_POST['id'] ?? 0);

    if ($resource === 'banners') {
        $image = upload_image('image', $_POST['current_image'] ?? null, 'banner');
        $data = [$_POST['title'] ?? '', $_POST['title_en'] ?? '', $_POST['subtitle'] ?? '', $_POST['subtitle_en'] ?? '', $_POST['cta_label'] ?? '', $_POST['cta_label_en'] ?? '', $_POST['cta_url'] ?? '', $image, isset($_POST['is_active']) ? 1 : 0, (int)($_POST['sort_order'] ?? 0)];
        if ($id) {
            $stmt = $pdo->prepare("UPDATE banners SET title=?, title_en=?, subtitle=?, subtitle_en=?, cta_label=?, cta_label_en=?, cta_url=?, image_path=?, is_active=?, sort_order=? WHERE id=?");
            $stmt->execute([...$data, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO banners (title, title_en, subtitle, subtitle_en, cta_label, cta_label_en, cta_url, image_path, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute($data);
        }
    }

    if ($resource === 'portfolio') {
        $image = trim((string) ($_POST['current_image'] ?? '')) ?: null;
        $slug = trim($_POST['slug'] ?? '') ?: slugify($_POST['title'] ?? '');
        $slugEn = trim($_POST['slug_en'] ?? '') ?: slugify($_POST['title_en'] ?? $slug);
        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
        $seoData = [
            trim((string) ($_POST['seo_focus_keyphrase'] ?? '')),
            trim((string) ($_POST['seo_focus_keyphrase_en'] ?? '')),
            trim((string) ($_POST['seo_title'] ?? '')),
            trim((string) ($_POST['seo_title_en'] ?? '')),
            trim((string) ($_POST['meta_description'] ?? '')),
            trim((string) ($_POST['meta_description_en'] ?? '')),
        ];
        $baseData = [$_POST['title'] ?? '', $_POST['title_en'] ?? '', $slug, $slugEn, $_POST['category'] ?? '', $_POST['category_en'] ?? '', $_POST['client'] ?? '', $_POST['client_en'] ?? '', $_POST['location'] ?? '', $_POST['location_en'] ?? '', $_POST['event_date'] ?? '', $_POST['description'] ?? '', $_POST['description_en'] ?? '', $videoUrl, $videoUrl, ...$seoData];
        $featured = isset($_POST['is_featured']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        if ($id) {
            $uploadedGallery = save_gallery_uploads('portfolio', $id);
            if (!empty($uploadedGallery)) {
                $image = $uploadedGallery[0];
            } else {
                $currentGallery = gallery_items('portfolio', $id);
                $coverInGallery = $image && in_array($image, array_column($currentGallery, 'image_path'), true);
                $orderedCover = ($coverInGallery || !$image) ? apply_gallery_order('portfolio', $id, (string) ($_POST['gallery_order'] ?? '')) : null;
                if ($orderedCover) {
                    $image = $orderedCover;
                } elseif (!$image) {
                    $firstGallery = $currentGallery[0]['image_path'] ?? null;
                    $image = $firstGallery ?: null;
                }
            }
            $data = [...$baseData, $image, $featured, $sortOrder];
            $stmt = $pdo->prepare("UPDATE portfolios SET title=?, title_en=?, slug=?, slug_en=?, category=?, category_en=?, client=?, client_en=?, location=?, location_en=?, event_date=?, description=?, description_en=?, video_url=?, video_url_en=?, seo_focus_keyphrase=?, seo_focus_keyphrase_en=?, seo_title=?, seo_title_en=?, meta_description=?, meta_description_en=?, image_path=?, is_featured=?, sort_order=? WHERE id=?");
            $stmt->execute([...$data, $id]);
        } else {
            $data = [...$baseData, $image, $featured, $sortOrder];
            $stmt = $pdo->prepare("INSERT INTO portfolios (title, title_en, slug, slug_en, category, category_en, client, client_en, location, location_en, event_date, description, description_en, video_url, video_url_en, seo_focus_keyphrase, seo_focus_keyphrase_en, seo_title, seo_title_en, meta_description, meta_description_en, image_path, is_featured, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute($data);
            $id = (int) $pdo->lastInsertId();
            $uploadedGallery = save_gallery_uploads('portfolio', $id);
            if (!empty($uploadedGallery)) {
                $image = $uploadedGallery[0];
                $updateCover = $pdo->prepare("UPDATE portfolios SET image_path = ? WHERE id = ?");
                $updateCover->execute([$image, $id]);
            }
        }
    }

    if ($resource === 'clients') {
        $logo = upload_image('logo', $_POST['current_logo'] ?? null, 'logo');
        $clientSort = (int)($_POST['sort_order'] ?? 0);
        if (!$id && $clientSort <= 0) {
            $clientSort = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM clients")->fetchColumn();
        }
        $data = [$_POST['name'] ?? '', $_POST['name_en'] ?? '', $logo, $_POST['website'] ?? '', isset($_POST['is_active']) ? 1 : 0, $clientSort];
        if ($id) {
            $stmt = $pdo->prepare("UPDATE clients SET name=?, name_en=?, logo_path=?, website=?, is_active=?, sort_order=? WHERE id=?");
            $stmt->execute([...$data, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO clients (name, name_en, logo_path, website, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute($data);
        }
    }

    if ($resource === 'articles') {
        $image = upload_image('image', $_POST['current_image'] ?? null, 'article');
        $slug = trim($_POST['slug'] ?? '') ?: slugify($_POST['title'] ?? '');
        $slugEn = trim($_POST['slug_en'] ?? '') ?: slugify($_POST['title_en'] ?? $slug);
        $data = [
            $_POST['title'] ?? '',
            $_POST['title_en'] ?? '',
            $slug,
            $slugEn,
            $_POST['excerpt'] ?? '',
            $_POST['excerpt_en'] ?? '',
            $_POST['content'] ?? '',
            $_POST['content_en'] ?? '',
            trim((string) ($_POST['seo_focus_keyphrase'] ?? '')),
            trim((string) ($_POST['seo_focus_keyphrase_en'] ?? '')),
            trim((string) ($_POST['seo_title'] ?? '')),
            trim((string) ($_POST['seo_title_en'] ?? '')),
            trim((string) ($_POST['meta_description'] ?? '')),
            trim((string) ($_POST['meta_description_en'] ?? '')),
            $image,
            isset($_POST['is_published']) ? 1 : 0,
            $_POST['published_at'] ?? date('Y-m-d'),
        ];
        if ($id) {
            $stmt = $pdo->prepare("UPDATE articles SET title=?, title_en=?, slug=?, slug_en=?, excerpt=?, excerpt_en=?, content=?, content_en=?, seo_focus_keyphrase=?, seo_focus_keyphrase_en=?, seo_title=?, seo_title_en=?, meta_description=?, meta_description_en=?, image_path=?, is_published=?, published_at=? WHERE id=?");
            $stmt->execute([...$data, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO articles (title, title_en, slug, slug_en, excerpt, excerpt_en, content, content_en, seo_focus_keyphrase, seo_focus_keyphrase_en, seo_title, seo_title_en, meta_description, meta_description_en, image_path, is_published, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute($data);
            $id = (int) $pdo->lastInsertId();
        }
        save_gallery_uploads('article', $id);
    }

    flash('บันทึกข้อมูลเรียบร้อยแล้ว');
    redirect('/admin/' . $resource);
}

function delete_resource(string $resource): void
{
    verify_csrf();
    $table = table_for($resource);
    delete_resource_gallery($resource, (int)($_POST['id'] ?? 0));
    $stmt = db()->prepare("DELETE FROM {$table} WHERE id = ?");
    $stmt->execute([(int)($_POST['id'] ?? 0)]);
    flash('ลบข้อมูลเรียบร้อยแล้ว');
    redirect('/admin/' . $resource);
}

function delete_resource_gallery(string $resource, int $id): void
{
    if ($id <= 0 || !in_array($resource, ['portfolio', 'articles'], true)) {
        return;
    }

    $ownerType = $resource === 'portfolio' ? 'portfolio' : 'article';
    $galleryStmt = db()->prepare("DELETE FROM gallery_images WHERE owner_type = ? AND owner_id = ?");
    $galleryStmt->execute([$ownerType, $id]);
}

function bulk_delete_resource(string $resource): void
{
    verify_csrf();
    $ids = array_values(array_unique(array_map('intval', $_POST['ids'] ?? [])));
    if (!$ids) {
        flash('กรุณาเลือกรายการที่ต้องการลบ', 'error');
        redirect('/admin/' . $resource);
    }

    $table = table_for($resource);
    $delete = db()->prepare("DELETE FROM {$table} WHERE id = ?");
    $deleted = 0;
    foreach ($ids as $id) {
        delete_resource_gallery($resource, $id);
        $delete->execute([$id]);
        $deleted += $delete->rowCount();
    }

    flash($deleted > 0 ? 'ลบรายการที่เลือกเรียบร้อยแล้ว' : 'ไม่พบรายการที่ลบได้', $deleted > 0 ? 'success' : 'error');
    redirect('/admin/' . $resource);
}

function reorder_clients(): void
{
    verify_csrf();
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? '')))));
    if (!$ids) {
        flash('ยังไม่มีรายการโลโก้ให้บันทึก', 'error');
        redirect('/admin/clients');
    }

    $stmt = db()->prepare("UPDATE clients SET sort_order = ? WHERE id = ?");
    $order = 1;
    foreach ($ids as $id) {
        $stmt->execute([$order++, $id]);
    }

    flash('บันทึกการเรียงโลโก้เรียบร้อยแล้ว');
    redirect('/admin/clients');
}

function delete_gallery_image(string $resource): void
{
    verify_csrf();
    $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if (!in_array($resource, ['portfolio', 'articles'], true)) {
        if ($isAjax) {
            json_response(['ok' => false, 'message' => 'ไม่พบประเภทข้อมูล'], 404);
        }
        not_found();
    }

    $ownerType = $resource === 'portfolio' ? 'portfolio' : 'article';
    $id = (int) ($_POST['id'] ?? 0);
    $ownerId = (int) ($_POST['owner_id'] ?? 0);

    $stmt = db()->prepare("SELECT * FROM gallery_images WHERE id = ? AND owner_type = ? AND owner_id = ?");
    $stmt->execute([$id, $ownerType, $ownerId]);
    $image = $stmt->fetch();
    if ($image) {
        $delete = db()->prepare("DELETE FROM gallery_images WHERE id = ?");
        $delete->execute([$id]);
        if (str_starts_with((string) $image['image_path'], '/uploads/')) {
            $file = __DIR__ . $image['image_path'];
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if ($isAjax) {
            $countStmt = db()->prepare("SELECT COUNT(*) FROM gallery_images WHERE owner_type = ? AND owner_id = ?");
            $countStmt->execute([$ownerType, $ownerId]);
            json_response([
                'ok' => true,
                'id' => $id,
                'count' => (int) $countStmt->fetchColumn(),
                'message' => 'ลบรูป Gallery เรียบร้อยแล้ว',
            ]);
        }
        flash('ลบรูป Gallery เรียบร้อยแล้ว');
    } elseif ($isAjax) {
        json_response(['ok' => false, 'message' => 'ไม่พบรูปที่ต้องการลบ'], 404);
    }

    redirect('/admin/' . $resource . '/edit?id=' . $ownerId);
}

function redirect_short_link(string $lang, string $type, int $id)
{
    $lang = $lang === 'en' ? 'en' : 'th';
    if ($type === 'p') {
        $item = find_row('portfolios', $id);
        if (!$item) {
            not_found();
        }
        $slug = $lang === 'en' ? (($item['slug_en'] ?? '') ?: ($item['slug'] ?? $id)) : (($item['slug'] ?? '') ?: $id);
        redirect(localized_url($lang, '/portfolio/' . $slug));
    }

    if ($type === 'a') {
        $article = find_row('articles', $id);
        if (!$article || (int) ($article['is_published'] ?? 0) !== 1) {
            not_found();
        }
        $slug = $lang === 'en' ? (($article['slug_en'] ?? '') ?: ($article['slug'] ?? $id)) : (($article['slug'] ?? '') ?: $id);
        redirect(localized_url($lang, '/articles/' . $slug));
    }

    not_found();
}

function not_found()
{
    http_response_code(404);
    layout('ไม่พบหน้า', function () {
        echo '<section class="px-4 py-24 text-center"><h1 class="text-4xl font-extrabold">ไม่พบหน้าที่ต้องการ</h1><a class="mt-6 inline-flex rounded-full bg-slate-950 px-5 py-3 text-sm font-bold text-white" href="/">กลับหน้าแรก</a></section>';
    });
    exit;
}

db();
$path = route_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/') {
    home_page();
} elseif ($path === '/robots.txt') {
    robots_txt();
} elseif ($path === '/sitemap.xml') {
    sitemap_xml();
} elseif (preg_match('#^/s/(th|en)/(p|a)/(\d+)$#', $path, $m)) {
    redirect_short_link($m[1], $m[2], (int) $m[3]);
} elseif ($path === '/about') {
    about_page();
} elseif ($path === '/services') {
    services_page();
} elseif ($path === '/portfolio') {
    portfolio_page();
} elseif (preg_match('#^/portfolio/([^/]+)$#', $path, $m)) {
    portfolio_detail_page($m[1]);
} elseif ($path === '/clients') {
    clients_page();
} elseif ($path === '/articles') {
    articles_page();
} elseif (preg_match('#^/articles/([^/]+)$#', $path, $m)) {
    article_detail_page($m[1]);
} elseif ($path === '/contact' && $method === 'POST') {
    handle_contact_submission();
} elseif ($path === '/contact') {
    contact_page();
} elseif ($path === '/privacy-policy') {
    privacy_policy_page();
} elseif ($path === '/cookie-policy') {
    cookie_policy_page();
} elseif ($path === '/admin/login' && $method === 'GET') {
    login_page();
} elseif ($path === '/admin/login' && $method === 'POST') {
    handle_login();
} elseif ($path === '/admin/logout' && $method === 'POST') {
    verify_csrf();
    session_destroy();
    redirect('/');
} elseif ($path === '/admin') {
    admin_dashboard();
} elseif ($path === '/admin/crm') {
    admin_crm();
} elseif ($path === '/admin/crm/export') {
    export_crm_csv();
} elseif ($path === '/admin/crm/new') {
    admin_crm_form();
} elseif ($path === '/admin/crm/edit') {
    admin_crm_form((int) ($_GET['id'] ?? 0));
} elseif ($path === '/admin/crm/save' && $method === 'POST') {
    save_crm();
} elseif ($path === '/admin/crm/delete' && $method === 'POST') {
    delete_crm();
} elseif ($path === '/admin/crm/view') {
    admin_crm_view((int) ($_GET['id'] ?? 0));
} elseif ($path === '/admin/crm/update' && $method === 'POST') {
    update_crm();
} elseif ($path === '/admin/tools') {
    admin_tools();
} elseif ($path === '/admin/settings' && $method === 'POST') {
    save_settings();
} elseif ($path === '/admin/settings/save' && $method === 'POST') {
    save_settings();
} elseif ($path === '/admin/settings') {
    admin_settings();
} elseif ($path === '/admin/backup') {
    admin_backup();
} elseif ($path === '/admin/backup/create' && $method === 'POST') {
    create_backup();
} elseif ($path === '/admin/backup/download') {
    download_backup();
} elseif ($path === '/admin/account/password' && $method === 'POST') {
    update_password();
} elseif ($path === '/admin/account/password') {
    password_form();
} elseif ($path === '/admin/users') {
    admin_users();
} elseif ($path === '/admin/users/new') {
    admin_user_form();
} elseif ($path === '/admin/users/edit') {
    admin_user_form((int) ($_GET['id'] ?? 0));
} elseif ($path === '/admin/users/save' && $method === 'POST') {
    save_user();
} elseif ($path === '/admin/users/delete' && $method === 'POST') {
    delete_user();
} elseif ($path === '/admin/users/bulk-delete' && $method === 'POST') {
    bulk_delete_users();
} elseif ($path === '/admin/clients/reorder' && $method === 'POST') {
    reorder_clients();
} elseif (preg_match('#^/admin/(banners|portfolio|clients|articles)$#', $path, $m)) {
    admin_resource($m[1]);
} elseif (preg_match('#^/admin/(banners|portfolio|clients|articles)/new$#', $path, $m)) {
    admin_form($m[1]);
} elseif (preg_match('#^/admin/(banners|portfolio|clients|articles)/edit$#', $path, $m)) {
    admin_form($m[1], (int)($_GET['id'] ?? 0));
} elseif (preg_match('#^/admin/(banners|portfolio|clients|articles)/save$#', $path, $m) && $method === 'POST') {
    save_resource($m[1]);
} elseif (preg_match('#^/admin/(banners|portfolio|clients|articles)/delete$#', $path, $m) && $method === 'POST') {
    delete_resource($m[1]);
} elseif (preg_match('#^/admin/(banners|portfolio|clients|articles)/bulk-delete$#', $path, $m) && $method === 'POST') {
    bulk_delete_resource($m[1]);
} elseif (preg_match('#^/admin/(portfolio|articles)/gallery/delete$#', $path, $m) && $method === 'POST') {
    delete_gallery_image($m[1]);
} else {
    not_found();
}
