<?php
declare(strict_types=1);
require __DIR__ . '/api/config.php';

header('Cache-Control: no-store, max-age=0');
header('Content-Type: text/html; charset=UTF-8');

function storyEscape(mixed $value): string
{
    return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Match the Content media contract at the rendering boundary, including encoded traversal checks.
function storyMediaUrl(mixed $value): ?string
{
    if (!is_string($value)) return null;
    $url = trim($value);
    if ($url === '' || preg_match('//u', $url) !== 1 || mb_strlen($url, 'UTF-8') > 255 || preg_match('/[\x00-\x20\x7f]/', $url)) return null;
    $parts = parse_url($url);
    if ($parts === false) return null;
    if (isset($parts['scheme']) || isset($parts['host'])) {
        return filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) ? $url : null;
    }
    $path = explode('?', explode('#', $url, 2)[0], 2)[0];
    if ($path === '' || str_starts_with($path, '/') || str_contains($url, '\\')
        || !preg_match("#\\A[A-Za-z0-9][A-Za-z0-9._~!$&'()*+,;=:@%/?\\x23-]*\\z#D", $url)
        || preg_match('/%(?![a-f0-9]{2})/i', $path)) return null;
    for ($pass = 0; $pass < 8; $pass++) {
        foreach (explode('/', $path) as $segment) if ($segment === '.' || $segment === '..') return null;
        if (str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)) return null;
        $decoded = rawurldecode($path);
        if ($decoded === $path) return $url;
        $path = $decoded;
    }
    return null;
}

function storyFigure(stdClass $image, bool $caption = false): string
{
    $url = storyMediaUrl($image->url ?? null);
    if ($url === null) throw new RuntimeException('Invalid saved image URL.');
    $html = '<figure class="article-figure"><img src="' . storyEscape($url) . '" alt="' . storyEscape($image->alt ?? '') . '" loading="lazy" decoding="async">';
    if ($caption && isset($image->caption) && $image->caption !== '') $html .= '<figcaption>' . storyEscape($image->caption) . '</figcaption>';
    return $html . '</figure>';
}

function storyRenderBlock(string $type, stdClass $payload): string
{
    switch ($type) {
        case 'paragraph':
            return '<p class="article-paragraph">' . storyEscape($payload->text ?? '') . '</p>';
        case 'heading':
            if (!isset($payload->level) || !in_array($payload->level, [2, 3], true)) throw new RuntimeException('Invalid saved heading level.');
            $tag = 'h' . $payload->level;
            return '<' . $tag . '>' . storyEscape($payload->text ?? '') . '</' . $tag . '>';
        case 'image':
            return storyFigure($payload);
        case 'image_caption':
            return storyFigure($payload, true);
        case 'video':
            $videoId = $payload->videoId ?? null;
            if (($payload->provider ?? '') !== 'youtube' || !is_string($videoId) || !preg_match('/\A[A-Za-z0-9_-]{11}\z/', $videoId)) throw new RuntimeException('Invalid saved video.');
            return '<div class="article-video"><iframe src="https://www.youtube-nocookie.com/embed/' . $videoId . '" title="YouTube video" loading="lazy" allow="encrypted-media; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>';
        case 'quote':
            $html = '<figure class="article-quote"><blockquote><p>' . storyEscape($payload->text ?? '') . '</p></blockquote>';
            if (isset($payload->attribution) && $payload->attribution !== '') $html .= '<figcaption>— ' . storyEscape($payload->attribution) . '</figcaption>';
            return $html . '</figure>';
        case 'divider':
            return '<hr>';
        case 'gallery':
            if (!isset($payload->images) || !is_array($payload->images) || count($payload->images) < 1 || count($payload->images) > 20) throw new RuntimeException('Invalid saved gallery.');
            $html = '<div class="article-gallery">';
            foreach ($payload->images as $image) {
                if (!$image instanceof stdClass) throw new RuntimeException('Invalid saved gallery image.');
                $html .= storyFigure($image, true);
            }
            return $html . '</div>';
        default:
            throw new RuntimeException('Unsupported saved block.');
    }
}

// No authoritative production URL is configured. Use this request's direct host/scheme and
// the deployed script directory; forwarded host/protocol headers are deliberately not trusted.
function storyBaseUrl(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    if (!is_string($host) || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[a-f0-9:]+\])(?::[0-9]{1,5})?\z/i', $host)) $host = 'localhost';
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/story.php'));
    return $scheme . '://' . $host . ($directory === '/' || $directory === '.' ? '/' : rtrim($directory, '/') . '/');
}

$slug = $_GET['slug'] ?? '';
$entry = null;
$articleHtml = '';
$status = 404;
$canonical = '';
$structuredData = '';
$cover = null;
$ogImage = null;
$types = ['blog' => 'Blog', 'news' => 'News', 'update' => 'Update', 'announcement' => 'Announcement'];

try {
    if (is_string($slug) && preg_match('/\A[a-z0-9-]{1,180}\z/', $slug)) {
        $stmt = db()->prepare("SELECT id, slug, title, type, excerpt, body, publish_date, cover_image, cover_alt, featured, seo_title, meta_description, og_title, og_description, og_image, noindex FROM content_entries WHERE slug = ? AND status = 'published' LIMIT 1");
        $stmt->execute([$slug]);
        $entry = $stmt->fetch() ?: null;
        if ($entry) {
            $blockStmt = db()->prepare('SELECT block_type, payload FROM content_blocks WHERE content_entry_id = ? ORDER BY block_order');
            $blockStmt->execute([$entry['id']]);
            $blocks = $blockStmt->fetchAll();
            foreach ($blocks as $block) {
                $payload = json_decode($block['payload'], false, 64, JSON_THROW_ON_ERROR);
                if (!$payload instanceof stdClass) throw new RuntimeException('Invalid saved block payload.');
                $articleHtml .= storyRenderBlock($block['block_type'], $payload);
            }
            if (!$blocks) {
                foreach (preg_split('/(?:\r?\n\s*){2,}/', trim($entry['body'])) as $paragraph) {
                    if ($paragraph !== '') $articleHtml .= '<p class="article-paragraph">' . storyEscape($paragraph) . '</p>';
                }
            }
            $firstText = static function (...$values): string {
                foreach ($values as $value) if (is_string($value) && trim($value) !== '') return trim($value);
                return '';
            };
            $pageTitle = $firstText($entry['seo_title'], $entry['title']);
            $description = $firstText($entry['meta_description'], $entry['excerpt']);
            $ogTitle = $firstText($entry['og_title'], $entry['seo_title'], $entry['title']);
            $ogDescription = $firstText($entry['og_description'], $entry['meta_description'], $entry['excerpt']);
            $cover = storyMediaUrl($entry['cover_image']);
            $ogImage = storyMediaUrl($entry['og_image']) ?? $cover;
            $baseUrl = storyBaseUrl();
            $canonical = $baseUrl . 'story.php?slug=' . rawurlencode($entry['slug']);
            if ($ogImage && !preg_match('#^https?://#i', $ogImage)) $ogImage = $baseUrl . $ogImage;
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => $entry['type'] === 'blog' ? 'BlogPosting' : 'Article',
                'headline' => $entry['title'],
                'description' => $description,
                'datePublished' => $entry['publish_date'],
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical]
            ];
            if ($ogImage) $schema['image'] = $ogImage;
            $structuredData = json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $status = 200;
        }
    }
} catch (Throwable $error) {
    $entry = null;
    $articleHtml = '';
    $status = 503;
}

http_response_code($status);
if (!$entry) {
    $pageTitle = $status === 404 ? 'Story not found | Dyndel Pino' : 'Story unavailable | Dyndel Pino';
    $description = 'Browse stories, ideas, and updates from Dyndel Pino.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= storyEscape($pageTitle) ?></title>
    <meta name="description" content="<?= storyEscape($description) ?>">
    <?php if (!$entry || $entry['noindex']): ?><meta name="robots" content="noindex,follow"><?php endif; ?>
    <?php if ($entry): ?>
    <link rel="canonical" href="<?= storyEscape($canonical) ?>">
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="Dyndel Pino">
    <meta property="og:title" content="<?= storyEscape($ogTitle) ?>">
    <meta property="og:description" content="<?= storyEscape($ogDescription) ?>">
    <meta property="og:url" content="<?= storyEscape($canonical) ?>">
    <meta property="article:published_time" content="<?= storyEscape($entry['publish_date']) ?>">
    <?php if ($ogImage): ?><meta property="og:image" content="<?= storyEscape($ogImage) ?>"><?php endif; ?>
    <script type="application/ld+json"><?= $structuredData ?></script>
    <?php endif; ?>
    <link rel="icon" type="image/png" href="img/icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Patrick+Hand&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=20261005.nav2">
</head>
<body data-page="story">
    <header class="header">
    <div class="nav-container">
        <div class="logo-wrap">
            <img src="img/icon.png" alt="Dyndel Pino logo">
            <div class="brand">Dyndel <span>Pino</span></div>
        </div>
        <nav class="nav" aria-label="Main navigation">
            <ul class="nav-list">
                <li><a href="index.html" data-nav-section="home">Home</a></li>
                <li class="nav-works">
                    <button class="nav-works-toggle" type="button" data-works-toggle aria-expanded="false" aria-controls="works-menu">Works <span class="nav-caret" aria-hidden="true">&#9662;</span></button>
                    <ul class="nav-submenu" id="works-menu">
                        <li><a href="illustration.html" data-works-section="illustration">Illustration</a></li>
                        <li><a href="portraits.html" data-works-section="portraits">Portraits</a></li>
                        <li><a href="logos.html" data-works-section="logos">Logos</a></li>
                    </ul>
                </li>
                <li><a href="stories.php" data-nav-section="stories">Stories</a></li>
                <li><a href="index.html#contact" data-nav-section="contact">Contact</a></li>
                <li><a href="store.html" data-nav-section="store">Store</a></li>
            </ul>
        </nav>
        <div class="header-cart-slot">
            <button class="shop-header-cart" type="button" data-open-cart aria-expanded="false" aria-hidden="true" tabindex="-1">Cart <span class="shop-header-cart-count" data-cart-count hidden>0</span></button>
        </div>
        <button class="menu-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>
    <main class="page-shell article-page">
        <a class="back-link" href="stories.php">&larr; Back to Stories</a>
        <?php if ($entry): ?>
        <article class="story-article">
            <header class="article-heading">
                <div class="story-meta"><span><?= storyEscape($types[$entry['type']] ?? '') ?></span><?php if ($entry['featured']): ?><span class="story-featured">Featured</span><?php endif; ?></div>
                <h1><?= storyEscape($entry['title']) ?></h1>
                <time datetime="<?= storyEscape($entry['publish_date']) ?>"><?= storyEscape((new DateTimeImmutable($entry['publish_date']))->format('M j, Y')) ?></time>
                <?php if ($entry['excerpt'] !== ''): ?><p class="article-excerpt"><?= storyEscape($entry['excerpt']) ?></p><?php endif; ?>
            </header>
            <?php if ($cover): ?><img class="article-cover" src="<?= storyEscape($cover) ?>" alt="<?= storyEscape($entry['cover_alt'] ?: 'Cover image for ' . $entry['title']) ?>" decoding="async"><?php endif; ?>
            <div class="article-body"><?= $articleHtml ?></div>
        </article>
        <?php else: ?>
        <section class="article-error">
            <h1><?= $status === 404 ? 'Story not found.' : 'This story is unavailable right now.' ?></h1>
            <p><?= $status === 404 ? 'This story isn’t available. Browse Stories for the latest notes from the studio.' : 'Please try again in a little while, or browse Stories.' ?></p>
            <a class="btn btn-secondary" href="stories.php">Browse Stories</a>
        </section>
        <?php endif; ?>
    </main>
    <footer class="footer"><p>&copy; 2026 Dyndel Pino</p></footer>
    <script src="script.js?v=20261005.nav2"></script>
</body>
</html>
