<?php
// Prevent direct access
if (!isset($_SESSION['username']) || !isset($cacheFile) || !isset($xml_data)) {
    die('Acceso no autorizado o falta de contexto.');
}

// Article Search
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$search_results = [];
$search_performed = !empty($search_query);

if ($search_performed && file_exists($cacheFile)) {
    $cache_xml = simplexml_load_file($cacheFile);
    if ($cache_xml) {
        $keywords = array_filter(explode(' ', $search_query));
        if (!empty($keywords)) {
            foreach ($cache_xml->item as $item) {
                $title = (string)$item->title_original;
                $content = (string)$item->content_original;
                $searchable_text = $title . ' ' . strip_tags($content);
                
                $all_keywords_found = true;
                foreach ($keywords as $keyword) {
                    if (stripos($searchable_text, $keyword) === false) {
                        $all_keywords_found = false;
                        break;
                    }
                }
                
                if ($all_keywords_found) {
                    $search_results[] = $item;
                }
            }
        }
    }
}

usort($search_results, function($a, $b) {
    return strtotime((string)$b->pubDate) - strtotime((string)$a->pubDate);
});

$favicon_map = [];
if (isset($xml_feeds->folder)) {
    foreach ($xml_feeds->xpath('//feed') as $feed) {
        $favicon_map[(string)$feed['url']] = (string)$feed['favicon'];
    }
}

// Notes Search
$notes_search_query = isset($_GET['q_notes']) ? trim($_GET['q_notes']) : '';
$include_received = isset($_GET['include_received']) && $_GET['include_received'] == '1';
$notes_search_results = [];
$notes_search_performed = !empty($notes_search_query);

if ($notes_search_performed) {
    $keywords = array_filter(explode(' ', $notes_search_query));

    if (!empty($keywords)) {
        // Search in own notes
        if (isset($xml_notes->note)) {
            foreach ($xml_notes->note as $note) {
                $title = (string)$note->article_title;
                $content = (string)$note->content;
                $searchable_text = $title . ' ' . $content;

                $all_keywords_found = true;
                foreach ($keywords as $keyword) {
                    if (stripos($searchable_text, $keyword) === false) {
                        $all_keywords_found = false;
                        break;
                    }
                }

                if ($all_keywords_found) {
                    $notes_search_results[] = ['type' => 'own', 'data' => $note];
                }
            }
        }

        // Search in received notes if requested
        if ($include_received && isset($xml_received_notes->note)) {
            foreach ($xml_received_notes->note as $note) {
                $title = (string)$note->title;
                $content = (string)$note->content;
                $searchable_text = $title . ' ' . $content;

                $all_keywords_found = true;
                foreach ($keywords as $keyword) {
                    if (stripos($searchable_text, $keyword) === false) {
                        $all_keywords_found = false;
                        break;
                    }
                }

                if ($all_keywords_found) {
                    $notes_search_results[] = ['type' => 'received', 'data' => $note];
                }
            }
        }
    }
}

// Sort results by date, newest first
usort($notes_search_results, function($a, $b) {
    $date_a = isset($a['data']->date) ? strtotime((string)$a['data']->date) : 0;
    $date_b = isset($b['data']->date) ? strtotime((string)$b['data']->date) : 0;
    return $date_b - $date_a;
});

?>

<h2>Buscador de Artículos</h2>
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
    <div style="margin-bottom: 1em; display: flex; gap: 0.5rem;"><?php echo $toggle_button_html; ?></div>
    
    <?php if (empty($search_results)): ?>
        <p class="text-muted mt-3">No se encontraron artículos que coincidan con todos los términos de búsqueda.</p>
    <?php else:
        // Article list rendering remains the same as in the original old_string
    ?>
        <ul class="article-list">
            <?php foreach ($search_results as $item):
                $is_read = (string)$item->read === '1';
                if ($is_read && !$show_read_for_this_request) continue;
                $feed_url = (string)$item->feed_url;
                $favicon_url = $favicon_map[$feed_url] ?? 'nisaba.png';
                $display_title = normalize_feed_text((string)$item->title_original);
                $display_desc = normalize_feed_text((string)$item->content_original);
            ?>
                <li class="article-item<?php echo $is_read ? ' read' : ''; ?>" data-guid="<?php echo htmlspecialchars($item->guid); ?>">
                    <?php if (!empty($item->image)):
                    ?>
                        <img src="<?php echo htmlspecialchars($item->image); ?>" alt="" class="article-image">
                    <?php endif;
                    ?>
                    
                    <h3><a href="?article_guid=<?php echo urlencode($item->guid); ?>&return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>"><?php echo htmlspecialchars($display_title); ?></a></h3>
                    
                    <p><?php echo htmlspecialchars(truncate_by_words($display_desc ?? '', 80)); ?> <img src="<?php echo htmlspecialchars($favicon_url); ?>" style="width: 16px; height: 16px; vertical-align: middle;"></p>
                    
                    <?php if (!$is_read):
                    ?>
                        <div style="clear: both; padding-top: 10px;">
                            <a href="?action=mark_read&guid=<?php echo urlencode($item->guid); ?>&return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" onclick="markAsRead(this, '<?php echo urlencode($item->guid); ?>'); return false;" class="btn btn-outline-secondary btn-sm mark-as-read-btn">Marcar leído</a>
                        </div>
                    <?php endif;
                    ?>
                </li>
            <?php endforeach;
            ?>
        </ul>
    <?php endif;
    ?>
<?php endif;
?>

<hr>

<h2>Buscar en Notas</h2>
<form method="GET" action="nisaba.php" class="mb-4">
    <input type="hidden" name="view" value="search">
    <div class="input-group">
        <input type="search" name="q_notes" class="form-control" placeholder="Buscar en tus notas y notas recibidas..." value="<?php echo htmlspecialchars($notes_search_query); ?>" aria-label="Búsqueda de notas">
        <button class="btn btn-primary" type="submit">Buscar en Notas</button>
    </div>
    <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" name="include_received" value="1" id="include_received" <?php if ($include_received) echo 'checked'; ?>> 
        <label class="form-check-label" for="include_received">
            Incluir recibidas
        </label>
    </div>
</form>

<?php if ($notes_search_performed):
?>
    <hr>
    <h4>Resultados de búsqueda en notas para "<?php echo htmlspecialchars($notes_search_query); ?>" (<?php echo count($notes_search_results); ?>)</h4>
    
    <?php if (empty($notes_search_results)):
    ?>
        <p class="text-muted mt-3">No se encontraron notas que coincidan con todos los términos de búsqueda.</p>
    <?php else:
        // Notes list rendering remains the same as in the original old_string
    ?>
        <div class="notes-container">
            <?php 
            $received_colors = ['#fff3a8', '#c9f2ff', '#ffcfdf', '#baffc9', '#f9f871', '#d0a9f5'];
            $own_colors = ['#ffc', '#cfc', '#ccf', '#fcc', '#fcf', '#cff'];
            $i_own = 0;
            $i_received = 0;

            foreach ($notes_search_results as $result):
                $note = $result['data'];
                if ($result['type'] === 'own'):
                    $color = $own_colors[$i_own % count($own_colors)];
                    $i_own++;
            ?>
                    <div class="note" style="background-color:<?php echo $color; ?>">
                        <div class="note-display">
                            <h4><a href="<?php echo htmlspecialchars($note->article_link); ?>" target="_blank"><?php echo htmlspecialchars($note->article_title); ?></a></h4>
                            <p><?php echo nl2br(htmlspecialchars($note->content)); ?></p>
                            <div style="display: flex; gap: 10px; margin-top: 1em;">
                                <button class="btn btn-outline-secondary btn-sm" onclick="toggleNoteEdit(this)">Editar</button>
                                <form method="POST" action="nisaba.php?view=notes" onsubmit="return confirm('¿Seguro que quieres eliminar esta nota?');">
                                    <input type="hidden" name="delete_note" value="<?php echo htmlspecialchars($note->article_guid); ?>">
                                    <button type="submit" class="btn btn-danger">Eliminar</button>
                                </form>
                                <?php if (!empty($telegram_bot_token) && !empty($telegram_chat_id)):
                                ?>
                                <form method="POST" action="nisaba.php?view=notes">
                                    <input type="hidden" name="send_to_telegram" value="<?php echo htmlspecialchars($note->article_guid); ?>">
                                    <button type="submit" class="btn btn-primary">Enviar a Telegram</button>
                                </form>
                                <?php endif;
                                ?>
                            </div>
                        </div>
                        <form method="POST" action="nisaba.php?view=notes" class="note-edit-form" style="display:none;">
                            <input type="hidden" name="article_guid" value="<?php echo htmlspecialchars($note->article_guid); ?>">
                            <div class="form-group"><label>Título</label><input type="text" name="article_title" value="<?php echo htmlspecialchars($note->article_title); ?>" class="form-group input"></div>
                            <div class="form-group"><label>Contenido</label><textarea name="note_content" class="postit-textarea"><?php echo htmlspecialchars($note->content); ?></textarea></div>
                            <div style="display: flex; gap: 10px;"><button type="submit" name="edit_note" class="btn btn-primary">Guardar</button><button type="button" onclick="toggleNoteEdit(this)" class="btn btn-danger">Cancelar</button></div>
                        </form>
                    </div>
            <?php 
                elseif ($result['type'] === 'received'):
                    $color = $received_colors[$i_received % count($received_colors)];
                    $i_received++;
                    $title = normalize_feed_text($note->title ?? '');
                    if ($title === '') $title = 'Nota recibida';
                    $content_text = normalize_feed_text($note->content ?? '');
                    $source_name = normalize_feed_text($note->source_name ?? '');
                    $favicon = (string)($note->favicon ?? '');
                    $link = (string)($note->link ?? '');
            ?>
                    <div class="note" style="background-color:<?php echo $color; ?>">
                        <?php if ($favicon !== ''):
                        ?>
                            <img src="<?php echo htmlspecialchars($favicon); ?>" alt="" class="note-source-favicon">
                        <?php endif;
                        ?>
                        <div class="note-display">
                            <?php if ($link !== ''):
                            ?>
                                <h4><a href="<?php echo htmlspecialchars($link); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($title); ?></a></h4>
                            <?php else:
                            ?>
                                <h4><?php echo htmlspecialchars($title); ?></h4>
                            <?php endif;
                            ?>
                            <?php if ($source_name !== ''):
                            ?>
                                <p><small>Compartida por <?php echo htmlspecialchars($source_name); ?></small></p>
                            <?php endif;
                            ?>
                            <p><?php echo nl2br(htmlspecialchars($content_text)); ?></p>
                            <?php
                                // Form to import the note
                                $full_content_for_import = normalize_feed_text($note->content ?? '');
                                $import_content = $full_content_for_import . "\n\n" . 'Compartido por ' . $source_name;
                            ?>
                            <form method="POST" action="nisaba.php" style="margin-top: 10px;">
                                <input type="hidden" name="save_note" value="true">
                                <input type="hidden" name="article_guid" value="<?php echo uniqid('note_'); ?>">
                                <input type="hidden" name="article_title" value="<?php echo htmlspecialchars($title); ?>">
                                <input type="hidden" name="article_link" value="<?php echo htmlspecialchars($link); ?>">
                                <input type="hidden" name="note_content" value="<?php echo htmlspecialchars($import_content); ?>">
                                <input type="hidden" name="return_url" value="nisaba.php?view=search&q_notes=<?php echo urlencode($notes_search_query); ?>&include_received=<?php echo $include_received ? '1' : '0'; ?>">
                                <button type="submit" class="btn btn-sm btn-primary">Importar</button>
                            </form>
                        </div>
                    </div>
            <?php 
                endif;
            endforeach;
            ?>
        </div>
    <?php endif;
    ?>
<?php endif;
?>