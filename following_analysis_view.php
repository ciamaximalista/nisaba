<?php
// This file will be included in nisaba.php
// $xml_data is available here.
?>
<h2>Análisis que sigues</h2>

<?php
$followed_sources = [];
if (isset($xml_data->external_notes_sources)) {
    foreach ($xml_data->external_notes_sources->source as $source) {
        $followed_sources[] = $source;
    }
}

if (empty($followed_sources)) {
    echo '<p>No sigues a ningún usuario todavía. Puedes añadir usuarios desde la sección "Gestionar Fuentes".</p>';
} else {
    foreach ($followed_sources as $source) {
        $source_url = rtrim((string)$source->url, '/');
        $source_name = (string)$source->name;
        $source_favicon = (string)$source->favicon;

        echo '<div class="summary-box" style="margin-bottom: 2em;">';

        $local_analysis_file = update_and_get_analysis($source_url);

        $latest_analysis_date_str = '';
        $items = [];
        $analysis_xml = null;
        $analysis_content = null;

        if ($local_analysis_file !== null) {
            $analysis_content = @file_get_contents($local_analysis_file);
            if ($analysis_content !== false) {
                libxml_use_internal_errors(true);
                $analysis_xml = simplexml_load_string($analysis_content);
                if ($analysis_xml !== false) {
                    libxml_clear_errors();
                    $items = $analysis_xml->xpath('//item');
                    if (!empty($items)) {
                        usort($items, function($a, $b) {
                            $date_a = isset($a->pubDate) ? strtotime((string)$a->pubDate) : 0;
                            $date_b = isset($b->pubDate) ? strtotime((string)$b->pubDate) : 0;
                            return $date_b - $date_a;
                        });
                        if (isset($items[0]->pubDate)) {
                            $latest_analysis_timestamp = strtotime((string)$items[0]->pubDate);
                            $latest_analysis_date_str = ' - ' . date('d/m/Y H:i', $latest_analysis_timestamp);
                        }
                    }
                } else {
                    libxml_clear_errors();
                }
            }
        }

        echo '<h3 style="display: flex; align-items: center; gap: 0.5em;">';
        if (!empty($source_favicon)) {
            echo '<img src="' . htmlspecialchars($source_favicon) . '" alt="" style="width: 24px; height: 24px; border-radius: 4px;">';
        }
        echo htmlspecialchars($source_name) . $latest_analysis_date_str;
        echo '</h3>';

        if ($local_analysis_file === null) {
            echo '<p>No se pudo cargar el análisis desde ' . htmlspecialchars($source_url) . '. Y no hay copia local disponible.</p>';
        } else if ($analysis_content === false) {
            echo '<p>No se pudo cargar el análisis desde la copia local: ' . htmlspecialchars($local_analysis_file) . '</p>';
        } else if ($analysis_xml === false) {
            echo '<p>Error al procesar el XML del análisis desde ' . htmlspecialchars($source_url) . '</p>';
        } else {
            if (empty($items)) {
                echo '<p>Este usuario no tiene entradas de análisis públicas.</p>';
            } else {
                // Show only the latest 3 entries
                $latest_items = array_slice($items, 0, 3);

                foreach ($latest_items as $item) {
                    $title = (string)$item->title;
                    
                    // Correctly access the content:encoded element with namespace
                    $content_encoded = (string)$item->children('http://purl.org/rss/1.0/modules/content/')->encoded;

                    echo '<h4>' . htmlspecialchars($title) . '</h4>';
                    echo '<div class="summary-container" style="margin-top: 1em;">';
                    
                    if (trim($content_encoded) !== '') {
                        echo '<button class="copy-btn" onclick="copySummary(this)">Copiar</button>';
                        echo '<div class="summary-box" style="background-color: #2d2d2d; color: #33ff33; border: 1px solid #444; border-radius: 8px; padding: 1.5em; font-family: \'VT323\', monospace; white-space: pre-wrap; overflow-x: auto; margin-top: 0; font-size: 22px;">';
                        echo $content_encoded; // Echo HTML content directly
                        echo '</div>';
                    } else {
                        echo '<p>' . htmlspecialchars((string)$item->description) . '</p>';
                    }
                    
                    echo '</div>';
                }
            }
        }
        echo '</div>';
    }
}
?>