<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stories | Dyndel Pino</title>
    <meta name="description" content="Ideas, studio notes, creative projects, and things Dyndel Pino is learning. Browse the latest blog posts, news, updates, and announcements.">
    <link rel="icon" type="image/png" href="img/icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Patrick+Hand&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=20261004.f1">
</head>
<body data-page="stories">
    <header class="header">
        <div class="nav-container">
            <div class="logo-wrap">
                <img src="img/icon.png" alt="Dyndel Pino logo">
                <div class="brand">Dyndel <span>Pino</span></div>
            </div>
            <button class="menu-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
            <nav class="nav" aria-label="Main navigation">
                <ul>
                    <li><a href="index.html">Home</a></li>
                    <li><a href="illustration.html">Illustration</a></li>
                    <li><a href="portraits.html">Portraits</a></li>
                    <li><a href="logos.html">Logos</a></li>
                    <li><a href="graphic-design.html">Shop</a></li>
                    <li><a href="stories.php" aria-current="page">Stories</a></li>
                    <li><a href="index.html#contact">Contact</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="page-shell stories-page" data-stories-archive>
        <section class="stories-heading" aria-labelledby="stories-heading">
            <p class="eyebrow">From the studio</p>
            <h1 id="stories-heading">Stories</h1>
            <p class="subtitle">Ideas, updates, projects, and things I'm learning.</p>
        </section>

        <div class="stories-toolbar">
            <div class="stories-filters" role="group" aria-label="Filter stories by type">
                <button type="button" class="btn btn-secondary is-active" data-story-filter="all" aria-pressed="true" aria-controls="stories-list" disabled>All</button>
                <button type="button" class="btn btn-secondary" data-story-filter="blog" aria-pressed="false" aria-controls="stories-list" disabled>Blog</button>
                <button type="button" class="btn btn-secondary" data-story-filter="news" aria-pressed="false" aria-controls="stories-list" disabled>News</button>
                <button type="button" class="btn btn-secondary" data-story-filter="update" aria-pressed="false" aria-controls="stories-list" disabled>Updates</button>
                <button type="button" class="btn btn-secondary" data-story-filter="announcement" aria-pressed="false" aria-controls="stories-list" disabled>Announcements</button>
            </div>
            <p class="stories-count" data-stories-count role="status" aria-live="polite"></p>
        </div>

        <section aria-label="Published stories" aria-busy="true" data-stories-results>
            <div class="stories-state" data-stories-state role="status" aria-live="polite">
                <h2 data-stories-state-heading>Gathering stories…</h2>
                <p data-stories-state-copy>Loading the latest notes from the studio.</p>
                <button type="button" class="btn btn-secondary" data-stories-retry hidden>Try again</button>
            </div>
            <div class="stories-grid" id="stories-list" data-stories-list></div>
            <noscript><p class="stories-noscript">Enable JavaScript to browse published stories.</p></noscript>
        </section>
    </main>

    <footer class="footer">
        <p>&copy; 2026 Dyndel Pino</p>
    </footer>
    <script src="script.js?v=20261004.f"></script>
</body>
</html>
