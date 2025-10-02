<?php
// Prevent direct access
if (!isset($_SESSION['username']) || !isset($cacheFile) || !isset($xml_data)) {
    die('Acceso no autorizado o falta de contexto.');
}

$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$search_results = [];
$search_performed = !empty($search_query);

if ($search_performed && file_exists($cacheFile)) {
    $cache_xml = simplexml_load_file($cacheFile);
    if ($cache_xml) {
        // Prepare search terms, removing empty strings
        $keywords = array_filter(explode(' ', $search_query));

        if (!empty($keywords)) {
            foreach ($cache_xml->item as $item) {
                $title = (string)$item->title_original;
                $content = (string)$item->content_original;
                
                // Combine title and content for a full search
                $searchable_text = $title . ' ' . strip_tags($content);
                
                $all_keywords_found = true;
                foreach ($keywords as $keyword) {
                    if (stripos($searchable_text, $keyword) === false) {
                        $all_keywords_found = false;
                        break; // Move to next item if one keyword is missing
                    }
                }
                
                if ($all_keywords_found) {
                    $search_results[] = $item;
                }
            }
        }
    }
}

// Sort results by date, newest first
usort($search_results, function($a, $b) {
    return strtotime((string)$b->pubDate) - strtotime((string)$a->pubDate);
});

// Get favicon map for display
$favicon_map = [];
if (isset($xml_data->feeds)) {
    foreach ($xml_data->xpath('//feed') as $feed) {
        $favicon_map[(string)$feed['url']] = (string)$feed['favicon'];
    }
}

?>

<h2>Buscador</h2>
<p class="text-muted">Busca en todos los artículos guardados en la caché, incluyendo los ya leídos.</p>

<form method="GET" action="nisaba.php" class="mb-4">
    <input type="hidden" name="view" value="search">
    <div class="input-group">
        <input type="search" name="q" class="form-control" placeholder="Introduce tu búsqueda..." value="<?php echo htmlspecialchars($search_query); ?>" aria-label="Búsqueda">
        <button class="btn btn-primary" type="submit">Buscar</button>
    </div>
</form>

<?php if ($search_performed): ?>
    <hr>
    <h4>Resultados de búsqueda para "<?php echo htmlspecialchars($search_query); ?>" (<?php echo count($search_results); ?>)</h4>
    
    <?php if (empty($search_results)): ?>
        <p class="text-muted mt-3">No se encontraron artículos que coincidan con todos los términos de búsqueda.</p>
    <?php else: ?>
        <ul class="article-list">
            <?php foreach ($search_results as $item): ?>
                <?php
                    $is_read = (string)$item->read === '1';
                    $feed_url = (string)$item->feed_url;
                    $favicon_url = $favicon_map[$feed_url] ?? 'nisaba.png';
                    $display_title = normalize_feed_text((string)$item->title_original);
                    $display_desc = normalize_feed_text((string)$item->content_original);
                ?>
                <li class="article-item<?php echo $is_read ? ' read' : ''; ?>" data-guid="<?php echo htmlspecialchars($item->guid); ?>">
                    <?php if (!empty($item->image)): ?>
                        <img src="<?php echo htmlspecialchars($item->image); ?>" alt="" class="article-image">
                    <?php endif; ?>
                    
                    <h3><a href="?article_guid=<?php echo urlencode($item->guid); ?>&return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>"><?php echo htmlspecialchars($display_title); ?></a></h3>
                    
                    <p><?php echo htmlspecialchars(truncate_by_words($display_desc ?? '', 80)); ?> <img src="<?php echo htmlspecialchars($favicon_url); ?>" style="width: 16px; height: 16px; vertical-align: middle;"></p>
                    
                    <?php if (!$is_read): ?>
                        <div style="clear: both; padding-top: 10px;">
                            <a href="?action=mark_read&guid=<?php echo urlencode($item->guid); ?>&return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" onclick="markAsRead(this, '<?php echo urlencode($item->guid); ?>'); return false;" class="btn btn-outline-secondary btn-sm mark-as-read-btn">Marcar leído</a>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>
