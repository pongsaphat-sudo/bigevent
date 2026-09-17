<?php
declare(strict_types=1);

const ECATALOG_PDF_MAX_BYTES = 200 * 1024 * 1024;

function migrate_ecatalogs(PDO $pdo): void
{
    if (db_is_mysql($pdo)) {
        $existsStmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecatalogs'");
        $tableExisted = (int) $existsStmt->fetchColumn() > 0;
    } else {
        $existsStmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'ecatalogs'");
        $tableExisted = (int) $existsStmt->fetchColumn() > 0;
    }

    if (db_is_mysql($pdo)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ecatalogs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title TEXT NOT NULL,
            title_en TEXT NULL,
            slug VARCHAR(191) NOT NULL UNIQUE,
            slug_en VARCHAR(191) NULL UNIQUE,
            description MEDIUMTEXT NULL,
            description_en MEDIUMTEXT NULL,
            client_name VARCHAR(255) NULL,
            client_name_en VARCHAR(255) NULL,
            category VARCHAR(255) NULL,
            category_en VARCHAR(255) NULL,
            publication_year SMALLINT UNSIGNED NULL,
            cover_image_path TEXT NULL,
            book_url TEXT NULL,
            pdf_path TEXT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            published_at VARCHAR(40) NULL,
            seo_focus_keyphrase TEXT NULL,
            seo_focus_keyphrase_en TEXT NULL,
            seo_title TEXT NULL,
            seo_title_en TEXT NULL,
            meta_description TEXT NULL,
            meta_description_en TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ecatalogs_published_sort (is_published, sort_order),
            INDEX idx_ecatalogs_featured_sort (is_featured, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ecatalogs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            title_en TEXT,
            slug TEXT NOT NULL UNIQUE,
            slug_en TEXT UNIQUE,
            description TEXT,
            description_en TEXT,
            client_name TEXT,
            client_name_en TEXT,
            category TEXT,
            category_en TEXT,
            publication_year INTEGER,
            cover_image_path TEXT,
            book_url TEXT,
            pdf_path TEXT,
            is_published INTEGER NOT NULL DEFAULT 0,
            is_featured INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            published_at TEXT,
            seo_focus_keyphrase TEXT,
            seo_focus_keyphrase_en TEXT,
            seo_title TEXT,
            seo_title_en TEXT,
            meta_description TEXT,
            meta_description_en TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        db_create_index($pdo, 'idx_ecatalogs_published_sort', 'ecatalogs', ['is_published', 'sort_order']);
        db_create_index($pdo, 'idx_ecatalogs_featured_sort', 'ecatalogs', ['is_featured', 'sort_order']);
    }

    if (!$tableExisted && (int) $pdo->query("SELECT COUNT(*) FROM ecatalogs")->fetchColumn() === 0) {
        $stmt = $pdo->prepare("INSERT INTO ecatalogs (
            title, title_en, slug, slug_en, description, description_en,
            client_name, client_name_en, category, category_en, publication_year,
            book_url, is_published, is_featured, sort_order, published_at,
            seo_title, seo_title_en, meta_description, meta_description_en
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 1, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'อีแคตตาล็อก ศรีสะเกษ',
            'Sisaket E-Catalog',
            'sisaket-business-2026',
            'sisaket-business-2026',
            'เปิดโลกธุรกิจศรีสะเกษผ่านอีแคตตาล็อกออนไลน์แบบพลิกหน้า',
            'Explore Sisaket businesses through an interactive online page-flip catalog.',
            'จังหวัดศรีสะเกษ',
            'Sisaket Province',
            'ธุรกิจและผลิตภัณฑ์ท้องถิ่น',
            'Local businesses and products',
            2026,
            'https://online.fliphtml5.com/eppkb/egfq/',
            '2026-08-25',
            'อีแคตตาล็อก ศรีสะเกษ',
            'Sisaket E-Catalog',
            'เปิดชมอีแคตตาล็อกศรีสะเกษออนไลน์ในรูปแบบหนังสือพลิกหน้า',
            'Browse the Sisaket e-catalog online in an interactive page-flip format.',
        ]);
    }
}

function ecatalog_localized(array $catalog, string $field, ?string $lang = null): string
{
    $lang = $lang ?? current_lang();
    $base = trim((string) ($catalog[$field] ?? ''));
    $english = trim((string) ($catalog[$field . '_en'] ?? ''));
    return $lang === 'en' && $english !== '' ? $english : $base;
}

function ecatalog_url(array $catalog, ?string $lang = null): string
{
    $lang = $lang ?? current_lang();
    $slug = $lang === 'en'
        ? (trim((string) ($catalog['slug_en'] ?? '')) ?: (string) $catalog['slug'])
        : (string) $catalog['slug'];
    return localized_url($lang, '/ecatalog/' . rawurlencode($slug));
}

function ecatalog_pdf_url(array $catalog, bool $download = false): string
{
    $path = '/ecatalog/' . rawurlencode((string) $catalog['slug']) . ($download ? '/download' : '/pdf');
    $stored = __DIR__ . '/storage/ecatalog-pdfs/' . basename((string) ($catalog['pdf_path'] ?? ''));
    $version = is_file($stored)
        ? substr(hash('sha256', (string) $catalog['pdf_path'] . ':' . filesize($stored) . ':' . filemtime($stored)), 0, 12)
        : 'missing';
    return url_for($path) . '?v=' . $version;
}

function find_ecatalog_by_slug(string $slug, bool $publishedOnly = true): ?array
{
    $sql = "SELECT * FROM ecatalogs WHERE (slug = ? OR slug_en = ?)";
    if ($publishedOnly) {
        $sql .= " AND is_published = 1";
    }
    $sql .= " LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute([$slug, $slug]);
    return $stmt->fetch() ?: null;
}

function ecatalog_cover(array $catalog): string
{
    return trim((string) ($catalog['cover_image_path'] ?? '')) ?: '/assets/img/og-default.png';
}

function ecatalog_book_mockup(array $catalog, string $variant = 'card'): string
{
    $variant = in_array($variant, ['card', 'home', 'hero'], true) ? $variant : 'card';
    $title = ecatalog_localized($catalog, 'title');
    $client = ecatalog_localized($catalog, 'client_name');
    $category = ecatalog_localized($catalog, 'category');
    $year = trim((string) ($catalog['publication_year'] ?? ''));
    $coverPath = trim((string) ($catalog['cover_image_path'] ?? ''));
    $hasCustomCover = $coverPath !== '' && basename(parse_url($coverPath, PHP_URL_PATH) ?: $coverPath) !== 'og-default.png';
    ob_start();
    ?>
    <span class="ecatalog-book-stage ecatalog-book-stage--<?= e($variant) ?>" aria-hidden="true">
        <span class="ecatalog-book ecatalog-book--<?= e($variant) ?>">
            <span class="ecatalog-book__pages"></span>
            <span class="ecatalog-book__cover">
                <?php if ($hasCustomCover): ?>
                    <img src="<?= e($coverPath) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <span class="ecatalog-book__generated-cover">
                        <span class="ecatalog-book__brand"><span>BE</span> BIGEVENT</span>
                        <span class="ecatalog-book__cover-rule"></span>
                        <?php if ($category !== ''): ?><span class="ecatalog-book__kicker"><?= e($category) ?></span><?php endif; ?>
                        <span class="ecatalog-book__generated-title"><?= e($title) ?></span>
                        <?php if ($client !== ''): ?><span class="ecatalog-book__generated-client"><?= e($client) ?></span><?php endif; ?>
                        <span class="ecatalog-book__generated-footer"><span>E-CATALOG</span><span><?= e($year) ?></span></span>
                    </span>
                <?php endif; ?>
                <span class="ecatalog-book__spine"></span>
                <span class="ecatalog-book__shine"></span>
            </span>
            <span class="ecatalog-book__edge"></span>
        </span>
        <span class="sr-only"><?= e($title) ?></span>
    </span>
    <?php
    return ob_get_clean();
}

function ecatalog_card(array $catalog): string
{
    $title = ecatalog_localized($catalog, 'title');
    $description = ecatalog_localized($catalog, 'description');
    $category = ecatalog_localized($catalog, 'category');
    $client = ecatalog_localized($catalog, 'client_name');
    $year = trim((string) ($catalog['publication_year'] ?? ''));
    ob_start();
    ?>
    <article class="group flex h-full flex-col overflow-hidden rounded-[1.75rem] bg-white shadow-sm ring-1 ring-slate-200/70 transition duration-300 hover:-translate-y-1 hover:shadow-soft">
        <a href="<?= e(ecatalog_url($catalog)) ?>" class="relative block overflow-hidden bg-[radial-gradient(circle_at_top,#334155,#0f172a_72%)]" aria-label="<?= e((current_lang() === 'en' ? 'Open ' : 'เปิด ') . $title) ?>">
            <?= ecatalog_book_mockup($catalog, 'card') ?>
            <span class="absolute left-4 top-4 inline-flex items-center gap-2 rounded-full bg-white/90 px-3 py-1.5 text-xs font-black uppercase tracking-[.14em] text-slate-900 backdrop-blur"><i data-lucide="book-open" class="h-3.5 w-3.5"></i>E-Catalog</span>
            <?php if ($year !== ''): ?><span class="absolute bottom-4 right-4 rounded-full bg-slate-950/75 px-3 py-1 text-xs font-bold text-white backdrop-blur"><?= e($year) ?></span><?php endif; ?>
        </a>
        <div class="flex flex-1 flex-col p-6">
            <?php if ($category !== ''): ?><p class="text-xs font-extrabold uppercase tracking-[.18em] text-coral"><?= e($category) ?></p><?php endif; ?>
            <h2 class="mt-2 text-xl font-extrabold leading-snug text-slate-950"><a href="<?= e(ecatalog_url($catalog)) ?>" class="hover:text-coral"><?= e($title) ?></a></h2>
            <?php if ($client !== ''): ?><p class="mt-2 text-sm font-semibold text-slate-500"><?= e($client) ?></p><?php endif; ?>
            <?php if ($description !== ''): ?><p class="mt-4 line-clamp-3 text-sm leading-7 text-slate-600"><?= e($description) ?></p><?php endif; ?>
            <a href="<?= e(ecatalog_url($catalog)) ?>" class="mt-6 inline-flex items-center gap-2 text-sm font-extrabold text-slate-900 hover:text-coral"><?= current_lang() === 'en' ? 'View catalog' : 'เปิดดูแคตตาล็อก' ?><i data-lucide="arrow-right" class="h-4 w-4"></i></a>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

function ecatalog_share_panel(array $catalog): string
{
    $lang = current_lang();
    $title = ecatalog_localized($catalog, 'title', $lang);
    $localHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $isLocalPreview = (bool) preg_match('/^(?:127\.0\.0\.1|localhost)(?::\d+)?$/', $localHost);
    $shareBase = $isLocalPreview ? 'http://' . $localHost : rtrim(absolute_url('/'), '/');
    $shareUrl = $shareBase . ecatalog_url($catalog, $lang);
    $shortUrl = $shareBase . short_url('e', (int) $catalog['id'], $lang);
    $facebookUrl = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($shareUrl);
    $lineUrl = 'https://social-plugins.line.me/lineit/share?url=' . rawurlencode($shareUrl);
    $heading = $lang === 'en' ? 'Share this E-Catalog' : 'แชร์ E-Catalog เล่มนี้';
    $copyLabel = $lang === 'en' ? 'Copy link' : 'คัดลอกลิงก์';
    ob_start();
    ?>
    <div class="share-panel mt-7 max-w-2xl rounded-[1.5rem] border border-white/10 bg-white/[0.06] p-4 backdrop-blur sm:p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="flex items-center gap-2 text-sm font-extrabold text-white"><i data-lucide="share-2" class="h-4 w-4 text-gold"></i><?= e($heading) ?></p>
                <p class="mt-1 text-xs text-slate-400"><?= $lang === 'en' ? 'Send it to friends or copy the short link.' : 'ส่งต่อให้เพื่อน หรือคัดลอกลิงก์ย่อได้ทันที' ?></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="<?= e($facebookUrl) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-xl bg-[#1877f2] px-4 py-2.5 text-xs font-extrabold text-white transition hover:-translate-y-0.5 hover:opacity-90" aria-label="<?= $lang === 'en' ? 'Share on Facebook' : 'แชร์ผ่าน Facebook' ?>"><span class="text-sm leading-none">f</span>Facebook</a>
                <a href="<?= e($lineUrl) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-xl bg-[#06c755] px-4 py-2.5 text-xs font-extrabold text-white transition hover:-translate-y-0.5 hover:opacity-90" aria-label="<?= $lang === 'en' ? 'Share on LINE' : 'แชร์ผ่าน LINE' ?>"><i data-lucide="message-circle" class="h-4 w-4"></i>LINE</a>
                <button type="button" class="copy-link-button inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 py-2.5 text-xs font-extrabold text-white transition hover:-translate-y-0.5 hover:bg-white/20" data-copy-link="<?= e($shortUrl) ?>" aria-label="<?= e($copyLabel) ?>" title="<?= e($copyLabel) ?>"><i data-lucide="copy" class="h-4 w-4"></i><?= e($copyLabel) ?></button>
            </div>
        </div>
        <div class="mt-4 flex min-w-0 items-center gap-2 border-t border-white/10 pt-3 text-xs text-slate-400"><span class="shrink-0 font-bold text-slate-300"><?= $lang === 'en' ? 'Short link:' : 'ลิงก์ย่อ:' ?></span><span class="min-w-0 truncate font-mono"><?= e($shortUrl) ?></span></div>
        <?php if ($isLocalPreview): ?><p class="mt-1 text-xs text-amber-200/80"><?= $lang === 'en' ? 'Local preview link: accessible on this computer only.' : 'ลิงก์ตัวอย่าง local เปิดได้เฉพาะเครื่องนี้' ?></p><?php endif; ?>
        <p class="copy-link-status mt-2 hidden text-xs font-bold text-emerald-400"><?= $lang === 'en' ? 'Link copied' : 'คัดลอกลิงก์แล้ว' ?></p>
    </div>
    <?php
    return ob_get_clean();
}

function ecatalog_index_page(): void
{
    $lang = current_lang();
    $q = trim((string) ($_GET['q'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $year = (int) ($_GET['year'] ?? 0);
    $page = current_page();
    $perPage = 12;
    $where = ['is_published = 1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(title LIKE ? OR title_en LIKE ? OR description LIKE ? OR description_en LIKE ? OR client_name LIKE ? OR client_name_en LIKE ?)';
        $needle = '%' . $q . '%';
        array_push($params, $needle, $needle, $needle, $needle, $needle, $needle);
    }
    if ($category !== '') {
        $where[] = '(category = ? OR category_en = ?)';
        array_push($params, $category, $category);
    }
    if ($year > 0) {
        $where[] = 'publication_year = ?';
        $params[] = $year;
    }
    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM ecatalogs WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $stmt = db()->prepare("SELECT * FROM ecatalogs WHERE {$whereSql} ORDER BY sort_order ASC, published_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $catalogs = $stmt->fetchAll();
    $categories = db()->query("SELECT DISTINCT category, category_en FROM ecatalogs WHERE is_published = 1 AND COALESCE(category, '') != '' ORDER BY category")->fetchAll();
    $years = db()->query("SELECT DISTINCT publication_year FROM ecatalogs WHERE is_published = 1 AND publication_year IS NOT NULL ORDER BY publication_year DESC")->fetchAll(PDO::FETCH_COLUMN);
    $title = $lang === 'en' ? 'E-Catalog Library' : 'คลัง E-Catalog';
    $description = $lang === 'en' ? 'Browse digital catalogs, publications and project documents from Bigevent.' : 'รวมแคตตาล็อกดิจิทัล สิ่งพิมพ์ และเอกสารโครงการจากบิ๊กอีเว้นท์';
    set_alternate_paths('/ecatalog', '/en/ecatalog');
    set_schema_extra([[
        '@type' => 'CollectionPage',
        'name' => $title,
        'description' => $description,
        'url' => absolute_url(localized_url($lang, '/ecatalog')),
    ]]);
    layout($title, function () use ($lang, $q, $category, $year, $catalogs, $categories, $years, $total, $page, $perPage): void {
        ?>
        <main>
            <section class="relative overflow-hidden bg-slate-950 px-4 pb-20 pt-28 text-white sm:px-6 lg:px-8">
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(225,91,79,.3),transparent_35%),radial-gradient(circle_at_bottom_left,rgba(200,155,60,.22),transparent_35%)]"></div>
                <div class="relative mx-auto max-w-7xl">
                    <p class="text-sm font-black uppercase tracking-[.28em] text-gold">Digital publications</p>
                    <h1 class="mt-4 max-w-3xl text-4xl font-black leading-tight sm:text-6xl"><?= $lang === 'en' ? 'E-Catalog Library' : 'คลัง E-Catalog' ?></h1>
                    <p class="mt-5 max-w-2xl text-base leading-8 text-slate-300"><?= $lang === 'en' ? 'Explore interactive catalogs and download available PDF editions.' : 'เลือกชมแคตตาล็อกแบบอินเทอร์แอ็กทีฟ และดาวน์โหลดฉบับ PDF ที่เปิดให้บริการ' ?></p>
                </div>
            </section>
            <section class="bg-[#f3f0ea] px-4 py-14 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-7xl">
                    <form method="get" action="<?= e(url_for('/ecatalog')) ?>" class="grid gap-3 rounded-[1.5rem] bg-white p-4 shadow-sm md:grid-cols-[1fr_240px_180px_auto]">
                        <input class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-coral" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= $lang === 'en' ? 'Search catalogs or clients' : 'ค้นหาชื่อแคตตาล็อกหรือลูกค้า' ?>">
                        <select class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-coral" name="category"><option value=""><?= $lang === 'en' ? 'All categories' : 'ทุกหมวดหมู่' ?></option><?php foreach ($categories as $item): $label = ecatalog_localized($item, 'category', $lang); ?><option value="<?= e($label) ?>" <?= $category === $label ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                        <select class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-coral" name="year"><option value="0"><?= $lang === 'en' ? 'All years' : 'ทุกปี' ?></option><?php foreach ($years as $itemYear): ?><option value="<?= (int) $itemYear ?>" <?= $year === (int) $itemYear ? 'selected' : '' ?>><?= (int) $itemYear ?></option><?php endforeach; ?></select>
                        <button class="rounded-2xl bg-slate-950 px-6 py-3 text-sm font-extrabold text-white hover:bg-coral"><?= $lang === 'en' ? 'Search' : 'ค้นหา' ?></button>
                    </form>
                    <div class="mt-8 flex items-center justify-between"><p class="text-sm font-bold text-slate-500"><?= $lang === 'en' ? $total . ' catalogs' : 'พบ ' . $total . ' รายการ' ?></p><?php if ($q !== '' || $category !== '' || $year > 0): ?><a href="<?= e(url_for('/ecatalog')) ?>" class="text-sm font-extrabold text-coral"><?= $lang === 'en' ? 'Clear filters' : 'ล้างตัวกรอง' ?></a><?php endif; ?></div>
                    <?php if ($catalogs): ?><div class="mt-6 grid gap-6 md:grid-cols-2 xl:grid-cols-3"><?php foreach ($catalogs as $catalog) { echo ecatalog_card($catalog); } ?></div><?= pagination_html($total, $page, $perPage) ?><?php else: ?><div class="mt-6 rounded-[2rem] bg-white p-12 text-center shadow-sm"><i data-lucide="book-x" class="mx-auto h-12 w-12 text-slate-300"></i><h2 class="mt-4 text-xl font-extrabold"><?= $lang === 'en' ? 'No catalogs found' : 'ไม่พบแคตตาล็อก' ?></h2></div><?php endif; ?>
                </div>
            </section>
        </main>
        <?php
    }, $description);
}

function ecatalog_detail_page(string $slug): void
{
    $catalog = find_ecatalog_by_slug($slug);
    if (!$catalog) {
        not_found();
    }
    $lang = current_lang();
    $title = ecatalog_localized($catalog, 'seo_title', $lang) ?: ecatalog_localized($catalog, 'title', $lang);
    $description = ecatalog_localized($catalog, 'meta_description', $lang) ?: ecatalog_localized($catalog, 'description', $lang);
    $bookUrl = trim((string) ($catalog['book_url'] ?? ''));
    $hasNativePdf = !empty($catalog['pdf_path']);
    $thaiPath = '/ecatalog/' . rawurlencode((string) $catalog['slug']);
    $englishPath = '/en/ecatalog/' . rawurlencode(trim((string) ($catalog['slug_en'] ?? '')) ?: (string) $catalog['slug']);
    set_alternate_paths($thaiPath, $englishPath);
    set_schema_extra([[
        '@type' => ['CreativeWork', 'DigitalDocument'],
        'name' => ecatalog_localized($catalog, 'title', $lang),
        'description' => $description,
        'image' => absolute_url(ecatalog_cover($catalog)),
        'datePublished' => $catalog['published_at'] ?: (string) $catalog['publication_year'],
        'inLanguage' => $lang === 'en' ? 'en' : 'th',
        'url' => absolute_url(ecatalog_url($catalog, $lang)),
    ]]);
    $relatedStmt = db()->prepare("SELECT * FROM ecatalogs WHERE is_published = 1 AND id != ? AND (category = ? OR category_en = ?) ORDER BY sort_order ASC, id DESC LIMIT 3");
    $relatedStmt->execute([(int) $catalog['id'], $catalog['category'] ?? '', $catalog['category_en'] ?? '']);
    $related = $relatedStmt->fetchAll();
    layout($title, function () use ($catalog, $lang, $bookUrl, $hasNativePdf, $related): void {
        $displayTitle = ecatalog_localized($catalog, 'title', $lang);
        $displayDescription = ecatalog_localized($catalog, 'description', $lang);
        ?>
        <main class="bg-slate-950 text-white">
            <section class="px-4 pb-10 pt-28 sm:px-6 lg:px-8">
                <div class="mx-auto grid max-w-7xl gap-8 lg:grid-cols-[300px_1fr] lg:items-center">
                    <?= ecatalog_book_mockup($catalog, 'hero') ?>
                    <div>
                        <div class="flex flex-wrap gap-2"><span class="rounded-full bg-gold/15 px-3 py-1 text-xs font-black uppercase tracking-[.16em] text-gold">E-Catalog</span><?php if (!empty($catalog['publication_year'])): ?><span class="rounded-full bg-white/10 px-3 py-1 text-xs font-bold text-slate-200"><?= (int) $catalog['publication_year'] ?></span><?php endif; ?></div>
                        <h1 class="mt-5 text-4xl font-black leading-tight sm:text-5xl"><?= e($displayTitle) ?></h1>
                        <?php if ($displayDescription !== ''): ?><p class="mt-5 max-w-3xl whitespace-pre-line text-base leading-8 text-slate-300"><?= e($displayDescription) ?></p><?php endif; ?>
                        <div class="mt-6 flex flex-wrap gap-x-7 gap-y-3 text-sm text-slate-300"><?php if (ecatalog_localized($catalog, 'client_name', $lang) !== ''): ?><span><strong class="text-white"><?= $lang === 'en' ? 'Client:' : 'ลูกค้า:' ?></strong> <?= e(ecatalog_localized($catalog, 'client_name', $lang)) ?></span><?php endif; ?><?php if (ecatalog_localized($catalog, 'category', $lang) !== ''): ?><span><strong class="text-white"><?= $lang === 'en' ? 'Category:' : 'หมวดหมู่:' ?></strong> <?= e(ecatalog_localized($catalog, 'category', $lang)) ?></span><?php endif; ?></div>
                        <div class="mt-7 flex flex-wrap gap-3"><?php if ($hasNativePdf): ?><a href="#ecatalog-reader" class="inline-flex items-center gap-2 rounded-full bg-white px-5 py-3 text-sm font-extrabold text-slate-950 hover:bg-gold"><i data-lucide="book-open" class="h-4 w-4"></i><?= $lang === 'en' ? 'Read online' : 'เปิดอ่านบนเว็บ' ?></a><?php elseif ($bookUrl !== ''): ?><a href="<?= e($bookUrl) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-full bg-white px-5 py-3 text-sm font-extrabold text-slate-950 hover:bg-gold"><i data-lucide="maximize-2" class="h-4 w-4"></i><?= $lang === 'en' ? 'Open full screen' : 'เปิดเต็มหน้าจอ' ?></a><?php endif; ?><?php if ($hasNativePdf): ?><a href="<?= e(ecatalog_pdf_url($catalog, true)) ?>" class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-5 py-3 text-sm font-extrabold text-white hover:bg-white/20"><i data-lucide="download" class="h-4 w-4"></i><?= $lang === 'en' ? 'Download PDF' : 'ดาวน์โหลด PDF' ?></a><?php endif; ?><?php if (is_admin()): ?><a href="/admin/ecatalog/edit?id=<?= (int) $catalog['id'] ?>" class="inline-flex items-center gap-2 rounded-full border border-coral/40 bg-coral/10 px-5 py-3 text-sm font-extrabold text-coral"><i data-lucide="pencil" class="h-4 w-4"></i><?= $lang === 'en' ? 'Edit' : 'แก้ไข' ?></a><?php endif; ?></div>
                        <?= ecatalog_share_panel($catalog) ?>
                    </div>
                </div>
            </section>
            <?php if ($hasNativePdf): ?><?= ecatalog_native_reader($catalog, $lang) ?><?php elseif ($bookUrl !== ''): ?><section class="mx-auto max-w-[1600px] bg-[#202124]"><iframe src="<?= e($bookUrl) ?>" title="<?= e($displayTitle) ?>" class="block h-[calc(100dvh-5rem)] min-h-[680px] w-full border-0" loading="eager" allow="fullscreen"></iframe><div class="border-t border-white/10 px-4 py-4 text-center text-sm text-slate-400"><?= $lang === 'en' ? 'If the reader does not load, use “Open full screen” above.' : 'หากตัวอ่านไม่แสดงผล กรุณากด “เปิดเต็มหน้าจอ” ด้านบน' ?></div></section><?php else: ?><section class="px-4 py-16 text-center"><div class="mx-auto max-w-xl rounded-[2rem] bg-white/5 p-10 ring-1 ring-white/10"><i data-lucide="book-x" class="mx-auto h-12 w-12 text-gold"></i><h2 class="mt-4 text-2xl font-extrabold"><?= $lang === 'en' ? 'No reader is available yet' : 'ยังไม่มีไฟล์สำหรับเปิดอ่าน' ?></h2></div></section><?php endif; ?>
            <?php if ($related): ?><section class="bg-[#f3f0ea] px-4 py-16 text-slate-950 sm:px-6 lg:px-8"><div class="mx-auto max-w-7xl"><h2 class="text-3xl font-black"><?= $lang === 'en' ? 'Related catalogs' : 'E-Catalog ที่เกี่ยวข้อง' ?></h2><div class="mt-7 grid gap-6 md:grid-cols-2 xl:grid-cols-3"><?php foreach ($related as $item) { echo ecatalog_card($item); } ?></div></div></section><?php endif; ?>
        </main>
        <?php
    }, $description, ecatalog_cover($catalog));
}

function ecatalog_native_reader(array $catalog, string $lang): string
{
    $pdfUrl = ecatalog_pdf_url($catalog);
    $downloadUrl = ecatalog_pdf_url($catalog, true);
    $storedPdf = __DIR__ . '/storage/ecatalog-pdfs/' . basename((string) $catalog['pdf_path']);
    $pdfBytes = is_file($storedPdf) ? filesize($storedPdf) : 0;
    ob_start();
    ?>
    <section id="ecatalog-reader" class="native-reader" data-native-reader data-pdf-url="<?= e($pdfUrl) ?>" data-pdf-bytes="<?= (int) $pdfBytes ?>" data-lang="<?= e($lang) ?>" aria-label="<?= $lang === 'en' ? 'E-Catalog reader' : 'ตัวอ่าน E-Catalog' ?>">
        <div class="native-reader__header">
            <div><p class="native-reader__eyebrow">BIGEVENT DIGITAL PUBLICATION</p><h2><?= $lang === 'en' ? 'Read this catalog' : 'เปิดอ่านแคตตาล็อก' ?></h2><p class="native-reader__hint"><?= $lang === 'en' ? 'Turn pages here on our website. No external viewer.' : 'พลิกอ่านได้บนเว็บไซต์นี้ ไม่ต้องเปิดเว็บภายนอก' ?></p></div>
            <div class="native-reader__header-actions"><button type="button" data-reader-thumbnails aria-expanded="false"><?= $lang === 'en' ? 'Pages' : 'ดูทุกหน้า' ?></button><button type="button" data-reader-fullscreen><?= $lang === 'en' ? 'Full screen' : 'เต็มหน้าจอ' ?></button><a href="<?= e($downloadUrl) ?>"><?= $lang === 'en' ? 'Download PDF' : 'ดาวน์โหลด PDF' ?></a></div>
        </div>
        <div class="native-reader__body">
            <div class="native-reader__stage" data-reader-stage tabindex="0"><div class="native-reader__book" data-reader-book></div><p class="native-reader__message" data-reader-message role="status"><?= $lang === 'en' ? 'Loading book…' : 'กำลังโหลดหนังสือ…' ?></p></div>
            <div class="native-reader__thumbnails" data-reader-thumbnails-panel hidden></div>
        </div>
        <div class="native-reader__toolbar"><button type="button" data-reader-prev aria-label="<?= $lang === 'en' ? 'Previous page' : 'หน้าก่อนหน้า' ?>" disabled>← <span><?= $lang === 'en' ? 'Previous' : 'ก่อนหน้า' ?></span></button><span class="native-reader__counter" data-reader-counter>— / —</span><button type="button" data-reader-next aria-label="<?= $lang === 'en' ? 'Next page' : 'หน้าถัดไป' ?>" disabled><span><?= $lang === 'en' ? 'Next' : 'ถัดไป' ?></span> →</button><span class="native-reader__toolbar-separator"></span><button type="button" data-reader-zoom-out aria-label="<?= $lang === 'en' ? 'Zoom out' : 'ย่อ' ?>">−</button><span class="native-reader__zoom" data-reader-zoom-label>100%</span><button type="button" data-reader-zoom-in aria-label="<?= $lang === 'en' ? 'Zoom in' : 'ขยาย' ?>">+</button></div>
        <p class="native-reader__tip"><?= $lang === 'en' ? 'Click a page or drag its corner to turn it. Arrow keys and swipes also work.' : 'คลิกหน้ากระดาษหรือลากมุมหน้าเพื่อพลิกได้ ใช้ปุ่มลูกศรหรือปัดหน้าจอได้เช่นกัน' ?></p>
    </section>
    <link rel="stylesheet" href="/assets/vendor/page-flip/stPageFlip.css?v=2.0.7">
    <script type="module" src="/assets/js/ecatalog-reader.mjs?v=5"></script>
    <?php
    return ob_get_clean();
}

function serve_ecatalog_pdf(string $slug, bool $download = false): void
{
    $catalog = find_ecatalog_by_slug($slug, !is_admin());
    if (!$catalog || empty($catalog['pdf_path'])) {
        not_found();
    }
    $base = realpath(__DIR__ . '/storage/ecatalog-pdfs');
    $file = realpath(__DIR__ . '/storage/' . ltrim((string) $catalog['pdf_path'], '/'));
    if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
        not_found();
    }
    $filename = slugify(ecatalog_localized($catalog, 'title')) . '.pdf';
    $asciiFilename = 'ecatalog-' . (int) $catalog['id'] . '.pdf';
    $size = filesize($file);
    $modified = filemtime($file);
    $etag = '"' . hash('sha256', $size . ':' . $modified . ':' . $file) . '"';
    $public = (int) $catalog['is_published'] === 1;
    header_remove('Expires');
    header_remove('Pragma');
    if ($public) {
        header_remove('Set-Cookie');
    }
    header('Cache-Control: ' . ($public ? 'public, no-cache, must-revalidate' : 'private, no-store'));
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
    if (!$download && !isset($_SERVER['HTTP_RANGE']) && (
        trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag
        || (!isset($_SERVER['HTTP_IF_NONE_MATCH']) && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
            && strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $modified)
    )) {
        http_response_code(304);
        exit;
    }
    $start = 0;
    $end = $size - 1;
    if (!$download && isset($_SERVER['HTTP_RANGE'])
        && (!isset($_SERVER['HTTP_IF_RANGE']) || in_array((string) $_SERVER['HTTP_IF_RANGE'], [$etag, gmdate('D, d M Y H:i:s', $modified) . ' GMT'], true))) {
        $range = (string) $_SERVER['HTTP_RANGE'];
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) || ($matches[1] === '' && $matches[2] === '')) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        if ($matches[1] === '') {
            $start = max(0, $size - (int) $matches[2]);
        } else {
            $start = (int) $matches[1];
            if ($matches[2] !== '') {
                $end = min($end, (int) $matches[2]);
            }
        }
        if ($start >= $size || $end < $start) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . ($end - $start + 1));
    header('Accept-Ranges: bytes');
    $disposition = $download ? 'attachment' : 'inline';
    header("Content-Disposition: {$disposition}; filename=\"{$asciiFilename}\"; filename*=UTF-8''" . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    $handle = fopen($file, 'rb');
    if (!$handle) {
        http_response_code(500);
        exit;
    }
    fseek($handle, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(65536, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    exit;
}

function download_ecatalog_pdf(string $slug): void
{
    serve_ecatalog_pdf($slug, true);
}

function render_home_ecatalog_section(): void
{
    $catalogs = db()->query("SELECT * FROM ecatalogs WHERE is_published = 1 AND is_featured = 1 ORDER BY sort_order ASC, published_at DESC, id DESC LIMIT 6")->fetchAll();
    if (!$catalogs) {
        return;
    }
    $lang = current_lang();
    ?>
    <section class="ecatalog-home-section bg-slate-950 px-4 py-16 text-white sm:px-6 lg:px-8" aria-labelledby="home-ecatalog-heading">
        <div class="mx-auto max-w-7xl">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div>
                    <p class="text-sm font-black uppercase tracking-[.25em] text-gold">Digital publications</p>
                    <h2 id="home-ecatalog-heading" class="mt-3 text-3xl font-black sm:text-4xl"><?= $lang === 'en' ? 'Explore our E-Catalogs' : 'ชั้นหนังสือ E-Catalog' ?></h2>
                    <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-300"><?= $lang === 'en' ? 'Choose a cover and flip through each book on our website.' : 'เลือกปกที่สนใจ แล้วเปิดอ่านแบบพลิกหน้าได้บนเว็บไซต์ของเรา' ?></p>
                </div>
                <a href="<?= e(url_for('/ecatalog')) ?>" class="inline-flex shrink-0 items-center gap-2 text-sm font-extrabold text-white hover:text-gold"><?= $lang === 'en' ? 'View all catalogs' : 'ดู E-Catalog ทั้งหมด' ?><i data-lucide="arrow-right" class="h-4 w-4"></i></a>
            </div>
            <div class="ecatalog-home-shelf mt-10">
                <?php foreach ($catalogs as $catalog): ?>
                    <?php $title = ecatalog_localized($catalog, 'title'); $client = ecatalog_localized($catalog, 'client_name'); ?>
                    <a href="<?= e(ecatalog_url($catalog)) ?>" class="ecatalog-home-item group" aria-label="<?= e(($lang === 'en' ? 'Open ' : 'เปิด ') . $title) ?>">
                        <span class="ecatalog-home-item__display">
                            <?= ecatalog_book_mockup($catalog, 'home') ?>
                        </span>
                        <span class="ecatalog-home-item__meta">
                            <span class="ecatalog-home-item__eyebrow">E-Catalog<?= !empty($catalog['publication_year']) ? ' · ' . (int) $catalog['publication_year'] : '' ?></span>
                            <span class="ecatalog-home-item__title"><?= e($title) ?></span>
                            <?php if ($client !== ''): ?><span class="ecatalog-home-item__client"><?= e($client) ?></span><?php endif; ?>
                            <span class="ecatalog-home-item__action"><?= $lang === 'en' ? 'Open book' : 'เปิดอ่านหนังสือ' ?><i data-lucide="arrow-up-right" class="h-4 w-4"></i></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
}

function admin_ecatalogs(): void
{
    require_admin();
    $q = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $page = current_page();
    $perPage = per_page();
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(title LIKE ? OR title_en LIKE ? OR client_name LIKE ? OR client_name_en LIKE ? OR category LIKE ? OR category_en LIKE ?)';
        $needle = '%' . $q . '%';
        array_push($params, $needle, $needle, $needle, $needle, $needle, $needle);
    }
    if ($status === 'published' || $status === 'draft') {
        $where[] = 'is_published = ?';
        $params[] = $status === 'published' ? 1 : 0;
    } elseif ($status === 'featured') {
        $where[] = 'is_featured = 1';
    }
    $whereSql = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM ecatalogs WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $stmt = db()->prepare("SELECT * FROM ecatalogs WHERE {$whereSql} ORDER BY sort_order ASC, id DESC LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    admin_layout('E-Catalog', function () use ($q, $status, $rows, $total, $page, $perPage): void {
        ?>
        <div class="mb-5 flex flex-col justify-between gap-4 sm:flex-row sm:items-center"><div><h1 class="text-2xl font-black">E-Catalog</h1><p class="mt-1 text-sm font-semibold text-slate-500">จัดการแคตตาล็อกดิจิทัล หนังสือออนไลน์ และไฟล์ PDF</p></div><a href="/admin/ecatalog/new" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-950 px-5 py-3 text-sm font-extrabold text-white hover:bg-coral"><i data-lucide="plus" class="h-4 w-4"></i>เพิ่ม E-Catalog</a></div>
        <form method="get" action="/admin/ecatalog" class="mb-5 grid gap-3 rounded-[1.5rem] bg-white p-4 shadow-sm md:grid-cols-[1fr_220px_auto]"><input class="admin-field" type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาชื่อ ลูกค้า หรือหมวดหมู่"><select class="admin-field" name="status"><option value="">ทุกสถานะ</option><option value="published" <?= $status === 'published' ? 'selected' : '' ?>>เผยแพร่</option><option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>ฉบับร่าง</option><option value="featured" <?= $status === 'featured' ? 'selected' : '' ?>>แนะนำหน้าแรก</option></select><button class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-extrabold text-white">ค้นหา</button></form>
        <div class="overflow-hidden rounded-[1.5rem] bg-white shadow-sm"><div class="overflow-x-auto"><table class="w-full min-w-[900px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-4">รายการ</th><th class="px-5 py-4">ปี / ลำดับ</th><th class="px-5 py-4">สถานะ</th><th class="px-5 py-4 text-right">จัดการ</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($rows as $row): ?><tr><td class="px-5 py-4"><div class="flex items-center gap-4"><img src="<?= e(ecatalog_cover($row)) ?>" class="h-16 w-24 rounded-xl object-cover" alt=""><div><div class="font-extrabold"><?= e($row['title']) ?></div><div class="mt-1 text-xs text-slate-500"><?= e($row['client_name'] ?: $row['category'] ?: $row['slug']) ?></div></div></div></td><td class="px-5 py-4 text-slate-600"><?= e((string) ($row['publication_year'] ?: '-')) ?> / <?= (int) $row['sort_order'] ?></td><td class="px-5 py-4"><div class="flex flex-wrap gap-2"><span class="rounded-full px-3 py-1 text-xs font-bold <?= (int) $row['is_published'] === 1 ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>"><?= (int) $row['is_published'] === 1 ? 'เผยแพร่' : 'ฉบับร่าง' ?></span><?php if ((int) $row['is_featured'] === 1): ?><span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700">หน้าแรก</span><?php endif; ?></div></td><td class="px-5 py-4"><div class="flex justify-end gap-2"><?php if ((int) $row['is_published'] === 1): ?><a href="<?= e(ecatalog_url($row, 'th')) ?>" target="_blank" class="rounded-xl bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700">ดูหน้า</a><?php endif; ?><a href="/admin/ecatalog/edit?id=<?= (int) $row['id'] ?>" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold">แก้ไข</a><form method="post" action="/admin/ecatalog/delete" onsubmit="return confirm('ยืนยันการลบ E-Catalog นี้?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="rounded-xl bg-red-50 px-3 py-2 text-xs font-bold text-red-600">ลบ</button></form></div></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="4" class="px-5 py-12 text-center text-slate-500">ไม่พบรายการ</td></tr><?php endif; ?></tbody></table></div></div>
        <?= pagination_html($total, $page, $perPage) ?>
        <?php
    });
}

function admin_ecatalog_form(?int $id = null): void
{
    require_admin();
    $row = $id ? find_row('ecatalogs', $id) : null;
    if ($id && !$row) {
        not_found();
    }
    admin_layout(($id ? 'แก้ไข' : 'เพิ่ม') . ' E-Catalog', function () use ($row, $id): void {
        ?>
        <form method="post" enctype="multipart/form-data" action="/admin/ecatalog/save" class="space-y-6">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
            <section class="rounded-[1.5rem] bg-white p-5 shadow-sm sm:p-6"><h2 class="text-lg font-extrabold">ข้อมูล E-Catalog</h2><div class="mt-5 grid gap-5 md:grid-cols-2"><?= input('title', 'ชื่อภาษาไทย *', $row['title'] ?? '') ?><?= input('title_en', 'ชื่อภาษาอังกฤษ', $row['title_en'] ?? '') ?><?= input('slug', 'Slug URL ภาษาไทย', $row['slug'] ?? '') ?><?= input('slug_en', 'Slug URL ภาษาอังกฤษ', $row['slug_en'] ?? '') ?><?= textarea('description', 'รายละเอียดภาษาไทย', $row['description'] ?? '', 'md:col-span-2') ?><?= textarea('description_en', 'รายละเอียดภาษาอังกฤษ', $row['description_en'] ?? '', 'md:col-span-2') ?><?= input('client_name', 'ชื่อลูกค้า', $row['client_name'] ?? '') ?><?= input('client_name_en', 'ชื่อลูกค้า EN', $row['client_name_en'] ?? '') ?><?= input('category', 'หมวดหมู่', $row['category'] ?? '') ?><?= input('category_en', 'หมวดหมู่ EN', $row['category_en'] ?? '') ?><?= input('publication_year', 'ปีเผยแพร่', $row['publication_year'] ?? date('Y'), 'number') ?><?= input('published_at', 'วันที่เผยแพร่', $row['published_at'] ?? date('Y-m-d'), 'date') ?><?= input('book_url', 'URL หนังสือภายนอก (ใช้เมื่อไม่มี PDF)', $row['book_url'] ?? '', 'url') ?><div><label class="admin-label">ลำดับการแสดง</label><input class="admin-field" type="number" name="sort_order" value="<?= (int) ($row['sort_order'] ?? 0) ?>"></div><div class="md:col-span-2 grid gap-4 sm:grid-cols-2"><?= checkbox('is_published', 'เผยแพร่บนหน้าบ้าน', (int) ($row['is_published'] ?? 0)) ?><?= checkbox('is_featured', 'แสดงเป็นรายการแนะนำหน้าแรก', (int) ($row['is_featured'] ?? 0)) ?></div></div></section>
            <?= image_input('cover_image', 'รูปหน้าปกหนังสือ E-Catalog (แนะนำภาพแนวตั้งแบบ A4 เช่น 1414 × 2000 px)', $row['cover_image_path'] ?? null) ?>
            <section class="rounded-[1.5rem] bg-white p-5 shadow-sm sm:p-6"><div class="flex items-start gap-3"><span class="grid h-11 w-11 place-items-center rounded-2xl bg-red-50 text-red-600"><i data-lucide="file-text" class="h-5 w-5"></i></span><div><h2 class="text-lg font-extrabold">ไฟล์ PDF สำหรับเปิดอ่านบนเว็บ</h2><p class="mt-1 text-sm leading-6 text-slate-500">อัปโหลด PDF แล้วระบบจะเปิดอ่าน/พลิกหน้าบนเว็บเราเองและให้ดาวน์โหลดได้ · สูงสุด 200 MB · หากมีทั้ง PDF และ URL ภายนอก ระบบจะเลือก PDF</p></div></div><?php if (!empty($row['pdf_path'])): ?><div class="mt-4 flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3 text-sm"><span class="font-bold text-slate-600">มีไฟล์ PDF อยู่แล้ว</span><label class="flex items-center gap-2 text-red-600"><input type="checkbox" name="remove_pdf" value="1">ลบไฟล์เดิม</label></div><?php endif; ?><input class="admin-field mt-5" type="file" name="pdf_file" accept="application/pdf,.pdf"><p class="mt-2 text-xs text-slate-500">ถ้าเลือกไฟล์ใหม่ ระบบจะใช้ไฟล์ใหม่แทน แม้เลือก “ลบไฟล์เดิม” ไว้</p></section>
            <section class="rounded-[1.5rem] bg-white p-5 shadow-sm sm:p-6"><h2 class="text-lg font-extrabold">SEO รายเล่ม</h2><div class="mt-5 grid gap-5 md:grid-cols-2"><?= input('seo_focus_keyphrase', 'Focus keyphrase TH', $row['seo_focus_keyphrase'] ?? '') ?><?= input('seo_focus_keyphrase_en', 'Focus keyphrase EN', $row['seo_focus_keyphrase_en'] ?? '') ?><?= input('seo_title', 'SEO title TH', $row['seo_title'] ?? '') ?><?= input('seo_title_en', 'SEO title EN', $row['seo_title_en'] ?? '') ?><?= textarea('meta_description', 'Meta description TH', $row['meta_description'] ?? '', 'md:col-span-2') ?><?= textarea('meta_description_en', 'Meta description EN', $row['meta_description_en'] ?? '', 'md:col-span-2') ?></div></section>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a href="/admin/ecatalog" class="rounded-2xl bg-slate-100 px-5 py-3 text-center text-sm font-extrabold text-slate-700">ยกเลิก</a><button class="rounded-2xl bg-slate-950 px-6 py-3 text-sm font-extrabold text-white hover:bg-coral">บันทึก E-Catalog</button></div>
        </form>
        <?php
    });
}

function normalize_ecatalog_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        throw new RuntimeException('URL หนังสือต้องเป็น http หรือ https ที่ถูกต้อง');
    }
    return $url;
}

function store_ecatalog_pdf(string $field, ?string $current): ?string
{
    $file = $_FILES[$field] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $current;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลด PDF ไม่สำเร็จ กรุณาตรวจขนาดไฟล์และลองใหม่');
    }
    if ((int) ($file['size'] ?? 0) > ECATALOG_PDF_MAX_BYTES) {
        throw new RuntimeException('ไฟล์ PDF ต้องมีขนาดไม่เกิน 200 MB');
    }
    $tmp = (string) $file['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $header = file_get_contents($tmp, false, null, 0, 5);
    if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true) || $header !== '%PDF-') {
        throw new RuntimeException('รองรับเฉพาะไฟล์ PDF ที่ถูกต้องเท่านั้น');
    }
    $dir = __DIR__ . '/storage/ecatalog-pdfs';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('ไม่สามารถสร้างพื้นที่เก็บ PDF ได้');
    }
    $name = date('YmdHis') . '-' . bin2hex(random_bytes(8)) . '.pdf';
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
        throw new RuntimeException('ไม่สามารถบันทึกไฟล์ PDF ได้');
    }
    return 'ecatalog-pdfs/' . $name;
}

function delete_ecatalog_file(?string $relative, string $baseDir): void
{
    if (!$relative) {
        return;
    }
    $base = realpath($baseDir);
    $file = realpath($baseDir . '/' . basename($relative));
    if ($base && $file && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) {
        @unlink($file);
        if (basename($baseDir) === 'uploads') {
            $key = substr(sha1(basename($file)), 0, 12);
            foreach ([480, 960] as $width) {
                $variant = $base . '/responsive/' . $key . '-' . $width . '.webp';
                if (is_file($variant)) @unlink($variant);
            }
        }
    }
}

function save_ecatalog(): void
{
    require_admin();
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $existing = $id ? find_row('ecatalogs', $id) : null;
    if ($id && !$existing) {
        not_found();
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') {
        flash('กรุณากรอกชื่อ E-Catalog ภาษาไทย', 'error');
        redirect($id ? '/admin/ecatalog/edit?id=' . $id : '/admin/ecatalog/new');
    }
    $newPdf = null;
    $newCover = null;
    try {
        $bookUrl = normalize_ecatalog_url((string) ($_POST['book_url'] ?? ''));
        $currentPdf = $existing['pdf_path'] ?? null;
        $currentCover = $existing['cover_image_path'] ?? null;
        $pdfUploadError = $_FILES['pdf_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $hasPdfUpload = $pdfUploadError !== UPLOAD_ERR_NO_FILE;
        if (!$hasPdfUpload && isset($_POST['remove_pdf']) && $bookUrl === '') {
            throw new RuntimeException('กรุณากรอก URL หนังสือหรืออัปโหลด PDF ก่อนลบไฟล์เดิม');
        }
        if (!$hasPdfUpload && !isset($_POST['remove_pdf']) && !$currentPdf && $bookUrl === '') {
            throw new RuntimeException('กรุณากรอก URL หนังสือหรืออัปโหลด PDF อย่างน้อยหนึ่งรายการ');
        }
        $slug = slugify(trim((string) ($_POST['slug'] ?? '')) ?: $title);
        $titleEn = trim((string) ($_POST['title_en'] ?? ''));
        $slugEnInput = trim((string) ($_POST['slug_en'] ?? ''));
        $slugEn = $slugEnInput !== '' ? slugify($slugEnInput) : ($titleEn !== '' ? slugify($titleEn) : null);
        if ($slugEn === $slug) {
            $slugEn = null;
        }
        $slugCheck = db()->prepare("SELECT COUNT(*) FROM ecatalogs WHERE id != ? AND (slug = ? OR slug = ? OR slug_en = ? OR slug_en = ?)");
        $slugCheck->execute([$id, $slug, $slugEn ?? $slug, $slug, $slugEn ?? $slug]);
        if ((int) $slugCheck->fetchColumn() > 0) {
            throw new RuntimeException('Slug ภาษาไทยหรือภาษาอังกฤษชนกับ E-Catalog ที่มีอยู่แล้ว');
        }
        $pdf = $hasPdfUpload ? store_ecatalog_pdf('pdf_file', null) : (isset($_POST['remove_pdf']) ? null : $currentPdf);
        if ($pdf && $pdf !== $currentPdf) {
            $newPdf = $pdf;
        }
        $cover = upload_image('cover_image', $currentCover, 'ecatalog_cover');
        if ($cover && $cover !== $currentCover) {
            $newCover = $cover;
        }
        $values = [
            $title, $titleEn, $slug, $slugEn,
            trim((string) ($_POST['description'] ?? '')), trim((string) ($_POST['description_en'] ?? '')),
            trim((string) ($_POST['client_name'] ?? '')), trim((string) ($_POST['client_name_en'] ?? '')),
            trim((string) ($_POST['category'] ?? '')), trim((string) ($_POST['category_en'] ?? '')),
            max(1900, min(2200, (int) ($_POST['publication_year'] ?? date('Y')))),
            $cover, $bookUrl, $pdf,
            isset($_POST['is_published']) ? 1 : 0, isset($_POST['is_featured']) ? 1 : 0,
            (int) ($_POST['sort_order'] ?? 0), trim((string) ($_POST['published_at'] ?? date('Y-m-d'))),
            trim((string) ($_POST['seo_focus_keyphrase'] ?? '')), trim((string) ($_POST['seo_focus_keyphrase_en'] ?? '')),
            trim((string) ($_POST['seo_title'] ?? '')), trim((string) ($_POST['seo_title_en'] ?? '')),
            trim((string) ($_POST['meta_description'] ?? '')), trim((string) ($_POST['meta_description_en'] ?? '')),
        ];
        db()->beginTransaction();
        if ($id) {
            $stmt = db()->prepare("UPDATE ecatalogs SET title=?, title_en=?, slug=?, slug_en=?, description=?, description_en=?, client_name=?, client_name_en=?, category=?, category_en=?, publication_year=?, cover_image_path=?, book_url=?, pdf_path=?, is_published=?, is_featured=?, sort_order=?, published_at=?, seo_focus_keyphrase=?, seo_focus_keyphrase_en=?, seo_title=?, seo_title_en=?, meta_description=?, meta_description_en=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([...$values, $id]);
        } else {
            $stmt = db()->prepare("INSERT INTO ecatalogs (title, title_en, slug, slug_en, description, description_en, client_name, client_name_en, category, category_en, publication_year, cover_image_path, book_url, pdf_path, is_published, is_featured, sort_order, published_at, seo_focus_keyphrase, seo_focus_keyphrase_en, seo_title, seo_title_en, meta_description, meta_description_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute($values);
            $id = (int) db()->lastInsertId();
        }
        db()->commit();
        if ($existing && $currentPdf && $pdf !== $currentPdf) {
            delete_ecatalog_file($currentPdf, __DIR__ . '/storage/ecatalog-pdfs');
        }
        if ($existing && $currentCover && $cover !== $currentCover && str_starts_with((string) $currentCover, '/uploads/')) {
            delete_ecatalog_file(basename((string) $currentCover), __DIR__ . '/uploads');
        }
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($newPdf) {
            delete_ecatalog_file($newPdf, __DIR__ . '/storage/ecatalog-pdfs');
        }
        if ($newCover && str_starts_with($newCover, '/uploads/')) {
            delete_ecatalog_file(basename($newCover), __DIR__ . '/uploads');
        }
        $message = $error instanceof PDOException
            ? ((str_contains(strtolower($error->getMessage()), 'unique') || str_contains(strtolower($error->getMessage()), 'duplicate')) ? 'Slug นี้ถูกใช้งานแล้ว กรุณาเปลี่ยน Slug' : 'บันทึกฐานข้อมูลไม่สำเร็จ')
            : ($error instanceof RuntimeException ? $error->getMessage() : 'บันทึก E-Catalog ไม่สำเร็จ');
        flash($message, 'error');
        redirect($id ? '/admin/ecatalog/edit?id=' . $id : '/admin/ecatalog/new');
    }
    flash('บันทึก E-Catalog เรียบร้อยแล้ว');
    redirect('/admin/ecatalog');
}

function delete_ecatalog(): void
{
    require_admin();
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $catalog = find_row('ecatalogs', $id);
    if ($catalog) {
        db()->prepare("DELETE FROM ecatalogs WHERE id = ?")->execute([$id]);
        delete_ecatalog_file($catalog['pdf_path'] ?? null, __DIR__ . '/storage/ecatalog-pdfs');
        if (str_starts_with((string) ($catalog['cover_image_path'] ?? ''), '/uploads/')) {
            delete_ecatalog_file(basename((string) $catalog['cover_image_path']), __DIR__ . '/uploads');
        }
        flash('ลบ E-Catalog เรียบร้อยแล้ว');
    }
    redirect('/admin/ecatalog');
}
