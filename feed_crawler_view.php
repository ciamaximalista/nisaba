<?php
// feed_crawler_view.php

$crawl_results = [];
$potential_links = [];
$crawl_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['crawl_url'])) {
    $url_to_crawl = filter_input(INPUT_POST, 'crawl_url', FILTER_SANITIZE_URL);

    if (filter_var($url_to_crawl, FILTER_VALIDATE_URL)) {
        $context = stream_context_create(['http' => [
            'user_agent' => 'Nisaba Feed Crawler/1.0',
            'timeout' => 10,
            'follow_location' => 1,
            'max_redirects' => 5
        ]]);
        
        $html = @file_get_contents($url_to_crawl, false, $context);

        if ($html) {
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            // 1. Buscar <link> tags de feeds
            $feed_links = $xpath->query('//link[@rel="alternate" and (contains(@type, "rss") or contains(@type, "atom"))]');
            foreach ($feed_links as $link) {
                $feed_url = $link->getAttribute('href');
                $crawl_results[] = nisaba_resolve_url($url_to_crawl, $feed_url);
            }
            $crawl_results = array_unique($crawl_results);

            if (empty($crawl_results)) {
                // 2. Buscar enlaces internos a páginas de feeds
                $keyword_links = $xpath->query('//a[contains(translate(text(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "feed") or contains(translate(text(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "fuentes") or contains(translate(text(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "rss")]');
                foreach ($keyword_links as $link) {
                    $potential_links[] = [
                        'text' => $link->nodeValue,
                        'href' => nisaba_resolve_url($url_to_crawl, $link->getAttribute('href'))
                    ];
                }
                $potential_links = array_unique($potential_links, SORT_REGULAR);

                // 3. Buscar feeds en rutas comunes
                $url_parts = parse_url($url_to_crawl);
                if (isset($url_parts['scheme']) && isset($url_parts['host'])) {
                    $base_url = $url_parts['scheme'] . '://' . $url_parts['host'];
                    $common_paths = [
                        '/feed', '/feeds', '/rss', '/atom.xml', '/feed.xml', '/rss.xml',
                        '/index.xml', '/feed.rss', '/rss/feed.xml', '/feeds/posts/default',
                        '/blog/feed', '/news/feed'
                    ];

                    foreach ($common_paths as $path) {
                        $test_url = $base_url . $path;
                        $headers = @get_headers($test_url, 1);
                        if ($headers && strpos($headers[0], '200') !== false) {
                            $crawl_results[] = $test_url;
                        }
                    }
                    $crawl_results = array_unique($crawl_results);
                }
            }
        } else {
            $crawl_error = "No se pudo acceder a la URL proporcionada. Comprueba que sea correcta y esté accesible.";
        }
    } else {
        $crawl_error = "La URL introducida no es válida.";
    }
}
?>

<div class="card mt-4">
    <div class="card-body">
        <h3 class="card-title">Rastreador de Feeds</h3>
        <p>Introduce la URL de un sitio web para encontrar sus feeds RSS o Atom.</p>
        
        <form action="?view=sources" method="POST">
            <div class="form-group mb-2">
                <label for="crawl_url" class="visually-hidden">URL del sitio</label>
                <input type="url" class="form-control" id="crawl_url" name="crawl_url" placeholder="https://ejemplo.com" required>
            </div>
            <button type="submit" class="btn btn-primary">Buscar Feeds</button>
        </form>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['crawl_url'])): ?>
            <hr>
            <h4>Resultados:</h4>
            <?php if (!empty($crawl_error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($crawl_error); ?></div>
            <?php endif; ?>

            <?php if (!empty($crawl_results)): ?>
                <p>¡Feeds encontrados! Puedes añadirlos directamente:</p>
                <ul>
                    <?php foreach ($crawl_results as $result): ?>
                        <li>
                            <form action="?view=sources" method="POST" class="d-inline">
                                <input type="hidden" name="feed_url" value="<?php echo htmlspecialchars($result); ?>">
                                <input type="hidden" name="folder_name" value="General">
                                <button type="submit" name="add_feed" class="btn btn-link p-0" style="vertical-align: baseline;"><?php echo htmlspecialchars($result); ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif (empty($crawl_error)): ?>
                <?php if (!empty($potential_links)): ?>
                    <p>No se encontraron feeds directos, pero la página enlaza a estas secciones que podrían contenerlos:</p>
                    <ul>
                        <?php foreach ($potential_links as $link): ?>
                            <li><a href="<?php echo htmlspecialchars($link['href']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($link['text']); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <p>Se intentó también buscar en rutas comunes, sin éxito.</p>
                <?php endif; ?>

                <div class="alert alert-warning mt-3">
                    <p class="mb-0">No parece que los desarrolladores de este sitio hayan incluido feeds. Sin embargo, si el sitio tiene una API abierta o el contenido dinámico está formateado de manera clara, podríamos añadir a Nisaba un módulo que la generara. Consulta a nuestro equipo de desarrollo a través de Telegram (escribiendo un mensaje a "@cia_maximalista_bot") para que evaluemos añadir esa nueva funcionalidad.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
