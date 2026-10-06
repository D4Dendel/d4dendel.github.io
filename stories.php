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
    <link rel="stylesheet" href="css/style.css?v=20261006.shopf3">
</head>
<body data-page="stories">
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
        <div class="footer-inner">
            <p>&copy; 2026 Dyndel Pino</p>
            <nav class="footer-policy-nav" aria-label="Store policies">
                <a href="terms.html">Terms</a><a href="privacy.html">Privacy</a><a href="shipping-delivery.html">Shipping</a><a href="returns-refunds.html">Returns</a><a href="digital-products.html">Digital Products</a>
            </nav>
        </div>
    </footer>
    <script src="script.js?v=20261006.shopf3"></script>
</body>
</html>
