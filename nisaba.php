<?php
session_start();

// --- 1. CONFIGURACIÓN Y FUNCIONES AUXILIARES ---

define('DATA_DIR', __DIR__ . '/data');
define('FAVICON_DIR', __DIR__ . '/data/favicons');

$error = ''; $feed_error = ''; $settings_success = '';

function get_favicon($url) {
    $default_favicon = 'nisaba.png';
    $url_parts = parse_url($url);
    if (!$url_parts || !isset($url_parts['host'])) return $default_favicon;
    $domain = $url_parts['scheme'] . '://' . $url_parts['host'];
    $favicon_path = '';
    $context = stream_context_create(['http' => ['user_agent' => 'Nisaba Feed Reader', 'timeout' => 5]]);
    $html = @file_get_contents($domain, false, $context);
    if ($html) {
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $links = $doc->getElementsByTagName('link');
        foreach ($links as $link) {
            $rel = strtolower($link->getAttribute('rel'));
            if (in_array($rel, ['icon', 'shortcut icon'])) {
                $favicon_url = $link->getAttribute('href');
                if (substr($favicon_url, 0, 2) === '//') $favicon_url = 'http:' . $favicon_url;
                elseif (substr($favicon_url, 0, 1) === '/') $favicon_url = $domain . $favicon_url;
                elseif (substr($favicon_url, 0, 4) !== 'http') $favicon_url = $domain . '/' . $favicon_url;
                $favicon_path = $favicon_url;
                break;
            }
        }
    }
    if (empty($favicon_path)) {
        $ico_url = $domain . '/favicon.ico';
        $headers = @get_headers($ico_url);
        if ($headers && strpos($headers[0], '200')) {
            $favicon_path = $ico_url;
        }
    }
    if (!empty($favicon_path)) {
        $favicon_data = @file_get_contents($favicon_path, false, $context);
        if ($favicon_data) {
            $filename = hash('md5', $url) . '_' . basename(parse_url($favicon_path, PHP_URL_PATH));
            $save_path = FAVICON_DIR . '/' . $filename;
            if (file_put_contents($save_path, $favicon_data)) {
                return 'data/favicons/' . $filename;
            }
        }
    }
    return $default_favicon;
}

function get_gemini_models($api_key) {
    if (empty($api_key)) return [];
    
    $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
    $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $result = @file_get_contents($url, false, $context);

    if ($result === FALSE) return [];

    $response = json_decode($result, true);
    if (!isset($response['models'])) return [];

    $generative_models = [];
    foreach ($response['models'] as $model) {
        if (isset($model['supportedGenerationMethods']) && in_array('generateContent', $model['supportedGenerationMethods']) && strpos($model['name'], 'gemini') !== false) {
            $model_id = str_replace('models/', '', $model['name']);
            $generative_models[] = [
                'id' => $model_id,
                'name' => $model['displayName'] . ' (' . $model_id . ')'
            ];
        }
    }
    return $generative_models;
}

function generate_notes_rss($xml_data, $username) {
    $rss = new SimpleXMLElement('<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"></rss>');
    $channel = $rss->addChild('channel');
    $channel->addChild('title', 'Notas de Nisaba para ' . $username);
    $channel->addChild('link', 'https://ruralnext.org/nisaba/notas.xml');
    $channel->addChild('description', 'Un feed de las notas personales guardadas en Nisaba.');
    $channel->addChild('language', 'es-es');

    if (isset($xml_data->notes)) {
        $notes_nodes = $xml_data->xpath('//note');
        $notes_array = [];
        foreach ($notes_nodes as $note) {
            $notes_array[] = $note;
        }
        usort($notes_array, function($a, $b) {
            return strtotime((string)$b->date) - strtotime((string)$a->date);
        });

        foreach ($notes_array as $note) {
            $item = $channel->addChild('item');
            $item->addChild('title', htmlspecialchars($note->article_title));
            $item->addChild('link', htmlspecialchars($note->article_link));
            $item->addChild('guid', htmlspecialchars($note->article_guid));
            $item->addChild('pubDate', date(DATE_RSS, strtotime((string)$note->date)));
            addChildWithCDATA($item, 'description', $note->content);
        }
    }

    $rss->asXML(__DIR__ . '/notas.xml');
}

function mask_api_key($key) {
    if (strlen($key) < 8) {
        return 'No guardada o demasiado corta.';
    }
    return substr($key, 0, 4) . str_repeat('x', 20) . substr($key, -4);
}

function translate_text($text, $target_lang, $api_key) {
    if (empty($api_key)) return ['success' => false, 'message' => 'API Key de Google Translate no configurada.'];
    if (empty($text)) return ['success' => true, 'text' => $text];

    $url = 'https://translation.googleapis.com/language/translate/v2?key=' . $api_key;
    $data = ['q' => $text, 'target' => $target_lang, 'format' => 'html'];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($result === false) {
        $error_message = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'message' => 'Error de cURL: ' . $error_message];
    }
    
    curl_close($ch);

    $response = json_decode($result, true);

    if (isset($response['error'])) {
        return ['success' => false, 'message' => 'Error de la API de Google: ' . $response['error']['message']];
    }

    if (isset($response['data']['translations'][0]['translatedText'])) {
        return ['success' => true, 'text' => $response['data']['translations'][0]['translatedText']];
    }

    return ['success' => false, 'message' => 'Respuesta inesperada de la API (HTTP Code: ' . $http_code . ').'];
}

function get_gemini_summary($content, $api_key, $model, $prompt_template) {
    if (empty($api_key) || empty($content)) return "No hay nada que resumir o la API key de Gemini no está configurada.";
    if (empty($prompt_template)) $prompt_template = "Eres un asistente experto en análisis de noticias. Resume los siguientes artículos en un único informe coherente en español. El resumen debe ser claro, conciso y destacar los puntos más importantes y las posibles conexiones entre las noticias. No uses markdown. Aquí están los artículos:";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent?key=" . $api_key;
    $prompt = $prompt_template . "\n\n" . $content;
    $data = ['contents' => [['parts' => [['text' => $prompt]]]]];
    $options = ['http' => ['header'  => "Content-Type: application/json\r\n", 'method'  => 'POST', 'content' => json_encode($data), 'ignore_errors' => true]];
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    if ($result === FALSE) {
        return "Error al contactar la API de Gemini.";
    }

    $response = json_decode($result, true);

    if (isset($response['error'])) {
        return "Error de la API de Gemini: " . $response['error']['message'];
    }

    if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
        return "No se pudo generar un resumen. Respuesta de la API: " . $result;
    }

    return $response['candidates'][0]['content']['parts'][0]['text'];
}

function truncate_text($text, $word_limit) {
    $plain_text = strip_tags($text);
    $words = str_word_count($plain_text, 1, 'àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝŸ');
    if (count($words) > $word_limit) {
        $truncated_words = array_slice($words, 0, $word_limit);
        return implode(' ', $truncated_words) . '...';
    }
    return $plain_text;
}

function addChildWithCDATA(SimpleXMLElement $parent, $name, $value) {
    $new_child = $parent->addChild($name);
    if ($new_child !== NULL) {
        $node = dom_import_simplexml($new_child);
        $owner = $node->ownerDocument;
        $node->appendChild($owner->createCDATASection($value));
    }
}

// --- 2. LÓGICA DE AUTENTICACIÓN ---

if (isset($_GET['logout'])) { session_destroy(); header('Location: nisaba.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $user_files = glob(DATA_DIR . '/*.xml');
    if (empty($user_files)) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        if (empty($username) || empty($password)) {
            $error = 'El nombre de usuario y la contraseña no pueden estar vacíos.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $error = 'El nombre de usuario solo puede contener letras, números y guiones bajos.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $xml = new SimpleXMLElement('<user><password/><feeds/><notes/><settings/><read_guids/></user>');
            $xml->password = $passwordHash;
            $xml->asXML(DATA_DIR . '/' . $username . '.xml');
            $_SESSION['username'] = $username;
            header('Location: nisaba.php?view=all_feeds');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $userFile = DATA_DIR . '/' . $username . '.xml';
    if (file_exists($userFile)) {
        $xml = simplexml_load_file($userFile);
        if (password_verify($password, (string)$xml->password)) {
            $_SESSION['username'] = $username;
            header('Location: nisaba.php?view=all_feeds');
            exit;
        }
    }
    $error = 'Nombre de usuario o contraseña incorrectos.';
}


// --- 3. LÓGICA PRINCIPAL DE LA APLICACIÓN ---

if (isset($_SESSION['username'])) {

    // --- 3.1. DEFINICIONES INICIALES ---
    $username = $_SESSION['username'];
    $userFile = DATA_DIR . '/' . $username . '.xml';
    $cacheFile = DATA_DIR . '/cache_' . $username . '.xml';

    $xml_data = simplexml_load_file($userFile);
    if ($xml_data === false) {
        die('ERROR CRÍTICO: No se pudo cargar el archivo de datos del usuario. Puede que esté corrupto, vacío o no sea accesible. Por favor, comprueba el archivo en la ruta: ' . htmlspecialchars($userFile));
    }

    $google_api_key = isset($xml_data->settings->google_translate_api_key) ? (string)$xml_data->settings->google_translate_api_key : '';
    $gemini_api_key = isset($xml_data->settings->gemini_api_key) ? (string)$xml_data->settings->gemini_api_key : '';
    $gemini_model = isset($xml_data->settings->gemini_model) ? (string)$xml_data->settings->gemini_model : 'gemini-1.5-pro-latest';
    $gemini_prompt = isset($xml_data->settings->gemini_prompt) ? (string)$xml_data->settings->gemini_prompt : '';
    $cache_duration = isset($xml_data->settings->cache_duration) ? (string)$xml_data->settings->cache_duration : '24';
    $show_read_articles = isset($xml_data->settings->show_read_articles) ? (string)$xml_data->settings->show_read_articles : 'true';


    // --- 3.2. MANEJO DE ACCIONES (GET y POST) ---

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'mark_all_read') {
        // Defensively reload user data to ensure settings are fresh
        $fresh_xml_data = simplexml_load_file($userFile);
        $fresh_cache_duration = isset($fresh_xml_data->settings->cache_duration) ? (string)$fresh_xml_data->settings->cache_duration : '24';

        if (isset($_POST['guids']) && is_array($_POST['guids'])) {
            $guids_to_mark = array_flip($_POST['guids']);
            if (file_exists($cacheFile) && !empty($guids_to_mark)) {
                $cache_xml = simplexml_load_file($cacheFile);
                $guids_to_persist = [];

                for ($i = count($cache_xml->item) - 1; $i >= 0; $i--) {
                    $item = $cache_xml->item[$i];
                    $item_guid = (string)$item->guid;

                    if (isset($guids_to_mark[$item_guid])) {
                        if ($fresh_cache_duration === '0') {
                            $guids_to_persist[] = $item_guid;
                            unset($cache_xml->item[$i]);
                        } else {
                            $item->read = 1;
                            if (!isset($item->read_at)) {
                                $item->addChild('read_at', time());
                            }
                        }
                    }
                }

                if (!empty($guids_to_persist)) {
                    if (!isset($fresh_xml_data->read_guids)) $fresh_xml_data->addChild('read_guids');
                    foreach ($guids_to_persist as $guid) {
                         if (count($fresh_xml_data->xpath('//read_guids/guid[.="' . htmlspecialchars($guid) . '"]')) == 0) {
                            $fresh_xml_data->read_guids->addChild('guid', $guid);
                        }
                    }
                    $fresh_xml_data->asXML($userFile);
                }

                $cache_xml->asXML($cacheFile);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'purge_cache') {
        // Defensively reload user data to ensure settings are fresh
        $fresh_xml_data = simplexml_load_file($userFile);
        $fresh_cache_duration = isset($fresh_xml_data->settings->cache_duration) ? (string)$fresh_xml_data->settings->cache_duration : '24';

        if (file_exists($cacheFile) && $fresh_cache_duration > 0) {
            $cache_xml = simplexml_load_file($cacheFile);
            $expiration_time = time() - ($fresh_cache_duration * 3600);
            $guids_to_persist = [];
            $items_purged = 0;

            for ($i = count($cache_xml->item) - 1; $i >= 0; $i--) {
                $item = $cache_xml->item[$i];
                if (isset($item->read, $item->read_at) && (string)$item->read === '1' && (int)$item->read_at < $expiration_time) {
                    $guids_to_persist[] = (string)$item->guid;
                    unset($cache_xml->item[$i]);
                    $items_purged++;
                }
            }

            if (!empty($guids_to_persist)) {
                if (!isset($xml_data->read_guids)) $xml_data->addChild('read_guids');
                foreach ($guids_to_persist as $guid) {
                    if (count($xml_data->xpath('//read_guids/guid[.="' . htmlspecialchars($guid) . '"]')) == 0) {
                        $xml_data->read_guids->addChild('guid', $guid);
                    }
                }
                $xml_data->asXML($userFile);
            }
            
            $cache_xml->asXML($cacheFile);
            if ($items_purged > 0) {
                $_SESSION['translate_feedback'] = ['type' => 'success', 'message' => "Purga completada. Se eliminaron {$items_purged} artículos leídos hace más de {$fresh_cache_duration} horas."];
            } else {
                $_SESSION['translate_feedback'] = ['type' => 'info', 'message' => "No se encontraron artículos para purgar que sean más antiguos que la duración de caché seleccionada ({$fresh_cache_duration} horas)."];
            }
        } else {
            $_SESSION['translate_feedback'] = ['type' => 'info', 'message' => 'La purga manual solo está activa cuando la duración de la caché es de 24 o 48 horas.'];
        }
        header('Location: nisaba.php?view=all_feeds');
        exit;
    }


    if (isset($_GET['action']) && $_GET['action'] === 'mark_read' && isset($_GET['guid'])) {
        // Defensively reload user data to ensure settings are fresh
        $fresh_xml_data = simplexml_load_file($userFile);
        $fresh_cache_duration = isset($fresh_xml_data->settings->cache_duration) ? (string)$fresh_xml_data->settings->cache_duration : '24';

        if (file_exists($cacheFile)) {
            $cache_xml = simplexml_load_file($cacheFile);
            $article_guid = $_GET['guid'];
            $articles = $cache_xml->xpath('//item[guid="' . htmlspecialchars($article_guid) . '"]');
            if (!empty($articles)) {
                if ($fresh_cache_duration === '0') {
                    if (!isset($fresh_xml_data->read_guids)) $fresh_xml_data->addChild('read_guids');
                    if (count($fresh_xml_data->xpath('//read_guids/guid[.="' . htmlspecialchars((string)$articles[0]->guid) . '"]')) == 0) {
                        $fresh_xml_data->read_guids->addChild('guid', (string)$articles[0]->guid);
                        $fresh_xml_data->asXML($userFile);
                    }
                    unset($articles[0][0]);
                } else {
                    $articles[0]->read = 1;
                    if (!isset($articles[0]->read_at)) {
                        $articles[0]->addChild('read_at', time());
                    }
                }
                $cache_xml->asXML($cacheFile);
            }
        }
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success']);
            exit;
        }
        $return_url = $_GET['return_url'] ?? 'nisaba.php?view=all_feeds';
        header('Location: ' . $return_url);
        exit;
    }

    if (isset($_GET['update_cache'])) {
        set_time_limit(300);

        // Load cache ONCE, or create if not exists
        if (file_exists($cacheFile)) {
            $cache_xml = simplexml_load_file($cacheFile);
        } else {
            $cache_xml = new SimpleXMLElement('<articles/>');
        }

        // 1. Purge old read articles from the cache object
        if ($cache_duration > 0) {
            $expiration_time = time() - ($cache_duration * 3600);
            $guids_to_persist = [];
            
            $i = count($cache_xml->item) - 1;
            while($i > -1){
                $item = $cache_xml->item[$i];
                if (isset($item->read, $item->read_at) && (string)$item->read === '1' && (int)$item->read_at < $expiration_time) {
                    $guids_to_persist[] = (string)$item->guid;
                    unset($cache_xml->item[$i]);
                }
                $i--;
            }

            if (!empty($guids_to_persist)) {
                if (!isset($xml_data->read_guids)) $xml_data->addChild('read_guids');
                foreach ($guids_to_persist as $guid) {
                    if (count($xml_data->xpath('//read_guids/guid[.="' . htmlspecialchars($guid) . '"]')) == 0) {
                        $xml_data->read_guids->addChild('guid', $guid);
                    }
                }
                $xml_data->asXML($userFile);
            }
        }

        // 2. Get all GUIDs that should be skipped (permanently read OR already in cache)
        $skip_guids = [];
        if (isset($xml_data->read_guids)) {
            foreach ($xml_data->read_guids->guid as $guid) {
                $skip_guids[(string)$guid] = true;
            }
        }
        foreach ($cache_xml->item as $item) {
            $skip_guids[(string)$item->guid] = true;
        }
        
        // 3. Fetch new articles
        $context = stream_context_create(['http' => ['user_agent' => 'Nisaba Feed Reader', 'timeout' => 10]]);
        
        foreach ($xml_data->xpath('//feed') as $feed) {
            $feed_url = (string)$feed['url'];
            $feed_content = @file_get_contents($feed_url, false, $context);
            if (!$feed_content) continue;
            
            libxml_use_internal_errors(true);
            $source_xml = simplexml_load_string($feed_content);
            if ($source_xml === false) {
                libxml_clear_errors();
                continue;
            }

            $source_xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
            $items = $source_xml->xpath('//item | //atom:entry');

            foreach ($items as $item) {
                $is_atom = (strpos($item->getName(), 'entry') !== false);

                // Robust GUID Generation Strategy
                $guid = '';
                if ($is_atom) {
                    $guid = (string)$item->id;
                } else {
                    if (isset($item->link) && !empty((string)$item->link)) {
                        $guid = (string)$item->link;
                    } elseif (isset($item->guid) && !empty((string)$item->guid)) {
                        $guid = (string)$item->guid;
                    }
                }

                if (!empty($guid)) {
                    // Normalize the GUID by trimming and removing trailing slash
                    $guid = trim($guid);
                    if (strlen($guid) > 1) {
                        $guid = rtrim($guid, '/');
                    }
                } else {
                    // Final fallback: if no id, link, or guid, hash the title and date.
                    $guid = 'hash-' . md5((string)$item->title . (string)$item->pubDate);
                }

                if (isset($skip_guids[$guid])) {
                    continue;
                }
                
                $title = (string)$item->title;
                if(empty($title)) continue;

                // Since this is a new article, add it to the main cache object and the skip list
                $skip_guids[$guid] = true;
                $article = $cache_xml->addChild('item');
                
                $pubDate = $is_atom ? (string)$item->updated : (string)$item->pubDate;
                
                $link = '';
                if ($is_atom) {
                    foreach($item->link as $l) {
                        if ($l['rel'] == 'alternate' || $l['rel'] == '') {
                            $link = (string)$l['href'];
                            break;
                        }
                    }
                    if(empty($link) && isset($item->link['href'])) $link = (string)$item->link['href'];
                } else {
                    $link = (string)$item->link;
                }

                $content = '';
                if ($is_atom) {
                    $content = (string)$item->content;
                    if(empty($content)) $content = (string)$item->summary;
                } else {
                    $content_ns = $item->children('content', true);
                    if (isset($content_ns->encoded)) {
                        $content = (string)$content_ns->encoded;
                    }
                    elseif (isset($item->content)) {
                        $content = (string)$item->content;
                    } else {
                        $content = (string)$item->description;
                    }
                }

                $image = '';
                $media_ns = $item->children('media', true);
                if (isset($media_ns->thumbnail)) {
                    $image = (string)$media_ns->thumbnail->attributes()->url;
                } elseif (isset($media_ns->content)) {
                    $image = (string)$media_ns->content->attributes()->url;
                }
                if (empty($image) && isset($item->enclosure)) {
                    $image = (string)$item->enclosure['url'];
                }

                $title = html_entity_decode($title, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $content = html_entity_decode($content, ENT_QUOTES | ENT_XML1, 'UTF-8');

                $article->addChild('feed_url', $feed_url);
                addChildWithCDATA($article, 'title_original', $title);
                addChildWithCDATA($article, 'content_original', $content);
                addChildWithCDATA($article, 'title_translated', $title);
                addChildWithCDATA($article, 'content_translated', $content);
                $article->addChild('pubDate', $pubDate);
                $article->addChild('guid', $guid);
                $article->addChild('link', $link);
                $article->addChild('read', '0');
                $article->addChild('image', $image);
            }
        }
        
        // 4. Save the modified cache object once
        $cache_xml->asXML($cacheFile);

        // 5. Set a new update ID to invalidate old summaries
        if (!isset($xml_data->settings)) $xml_data->addChild('settings');
        $xml_data->settings->last_update_id = uniqid('update_');
        $xml_data->asXML($userFile);

        header('Location: nisaba.php?view=all_feeds');
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'translate') {
        $error_message = '';
        $success_count = 0;
        $processed_articles = [];

        if (file_exists($cacheFile)) {
            $cache_xml = simplexml_load_file($cacheFile);
            $non_es_feeds = [];
            foreach ($xml_data->xpath('//feed[not(starts-with(@lang, "es"))]') as $feed) {
                $non_es_feeds[] = (string)$feed['url'];
            }

            if (!empty($non_es_feeds)) {
                $xpath_query = '//item[read="0" and (' . implode(' or ', array_map(function($url) {
                    return 'feed_url="' . $url . '"';
                }, $non_es_feeds)) . ')]';
                
                $articles_to_translate = $cache_xml->xpath($xpath_query);

                foreach ($articles_to_translate as $article) {
                    $article_guid = (string)$article->guid;
                    $translated_something = false;

                    if (empty($error_message) && (string)$article->title_original === (string)$article->title_translated) {
                        $result = translate_text((string)$article->title_original, 'es', $google_api_key);
                        if ($result['success']) {
                            $article->title_translated = html_entity_decode($result['text'], ENT_QUOTES, 'UTF-8');
                            $translated_something = true;
                        } else {
                            $error_message = $result['message'];
                        }
                    }

                    if (empty($error_message) && (string)$article->content_original === (string)$article->content_translated) {
                        $result = translate_text((string)$article->content_original, 'es', $google_api_key);
                        if ($result['success']) {
                            $article->content_translated = html_entity_decode($result['text'], ENT_QUOTES, 'UTF-8');
                            $translated_something = true;
                        } else {
                            $error_message = $result['message'];
                        }
                    }

                    if ($translated_something && !in_array($article_guid, $processed_articles)) {
                        $success_count++;
                        $processed_articles[] = $article_guid;
                    }
                }
                $cache_xml->asXML($cacheFile);
            }
        }

        if (!empty($error_message)) {
            $_SESSION['translate_feedback'] = ['type' => 'error', 'message' => $error_message];
        } elseif ($success_count > 0) {
            $_SESSION['translate_feedback'] = ['type' => 'success', 'message' => "Traducción completada para {$success_count} artículos."];
        } else {
            $_SESSION['translate_feedback'] = ['type' => 'info', 'message' => 'No había artículos nuevos que traducir.'];
        }

        header('Location: nisaba.php?view=all_feeds');
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'export_opml') {
        if (file_exists($userFile)) {
            $opml = new SimpleXMLElement('<opml version="2.0"></opml>');
            $head = $opml->addChild('head');
            $head->addChild('title', 'Suscripciones de Nisaba para ' . $username);
            $body = $opml->addChild('body');
            if (isset($xml_data->feeds)) {
                foreach ($xml_data->feeds->folder as $folder) {
                    $folder_outline = $body->addChild('outline');
                    $folder_outline->addAttribute('text', (string)$folder['name']);
                    $folder_outline->addAttribute('title', (string)$folder['name']);
                    foreach ($folder->feed as $feed) {
                        $feed_outline = $folder_outline->addChild('outline');
                        $feed_outline->addAttribute('type', 'rss');
                        $feed_outline->addAttribute('text', (string)$feed['name']);
                        $feed_outline->addAttribute('title', (string)$feed['name']);
                        $feed_outline->addAttribute('xmlUrl', (string)$feed['url']);
                    }
                }
            }
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="nisaba_feeds.opml"');
            echo $opml->asXML();
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['import_opml'])) {
            if (isset($_FILES['opml_file']) && $_FILES['opml_file']['error'] === UPLOAD_ERR_OK) {
                $opml_content = file_get_contents($_FILES['opml_file']['tmp_name']);
                $opml_xml = @simplexml_load_string($opml_content);
                if ($opml_xml && isset($opml_xml->body)) {
                    if (!isset($xml_data->feeds)) $xml_data->addChild('feeds');
                    
                    $existing_urls = [];
                    foreach ($xml_data->xpath('//feed[@url]') as $feed) {
                        $existing_urls[] = (string)$feed['url'];
                    }

                    $process_opml_outline = function($outline, $current_folder) use (&$process_opml_outline, &$xml_data, &$existing_urls) {
                        foreach ($outline as $entry) {
                            if (isset($entry['xmlUrl'])) {
                                $feed_url = (string)$entry['xmlUrl'];
                                if (!in_array($feed_url, $existing_urls)) {
                                    $feed_title = (string)($entry['text'] ?? $entry['title'] ?? $feed_url);
                                    $favicon_path = get_favicon($feed_url);
                                    $folder_name = $current_folder ?: 'General';
                                    $folder = $xml_data->xpath('//folder[@name="' . htmlspecialchars($folder_name) . '"]');
                                    $folder_node = !empty($folder) ? $folder[0] : $xml_data->feeds->addChild('folder');
                                    if (empty($folder)) $folder_node->addAttribute('name', $folder_name);
                                    $new_feed = $folder_node->addChild('feed');
                                    $new_feed->addAttribute('url', $feed_url);
                                    $new_feed->addAttribute('name', $feed_title);
                                    $new_feed->addAttribute('favicon', $favicon_path);
                                    $existing_urls[] = $feed_url;
                                }
                            }
                            if (isset($entry->outline)) {
                                $folder_name = (string)($entry['text'] ?? $entry['title'] ?? 'Sin nombre');
                                $process_opml_outline($entry->outline, $folder_name, $xml_data, $existing_urls);
                            }
                        }
                    };
                    $process_opml_outline($opml_xml->body->outline, '', $xml_data, $existing_urls);
                    $xml_data->asXML($userFile);
                    header('Location: nisaba.php');
                    exit;
                }
                else {
                    $feed_error = 'Error al procesar el archivo OPML. Asegúrate de que es un archivo OPML válido.';
                }
            } else {
                $feed_error = 'Error al subir el archivo. Código: ' . ($_FILES['opml_file']['error'] ?? ' desconocido');
            }
        }

        if (isset($_POST['save_note'])) {
            $guid = $_POST['article_guid'];
            $note_content = $_POST['note_content'];
            $existing_note = $xml_data->xpath('//note[article_guid="' . htmlspecialchars($guid) . '"]');
            if (!empty($existing_note)) {
                $existing_note[0]->content = $note_content;
            } else {
                if(!isset($xml_data->notes)) $xml_data->addChild('notes');
                $note = $xml_data->notes->addChild('note');
                $note->addChild('article_guid', $guid);
                $note->addChild('article_title', $_POST['article_title']);
                $note->addChild('article_link', $_POST['article_link']);
                $note->addChild('content', $note_content);
                $note->addChild('date', date('c'));
            }
            $xml_data->asXML($userFile);
            generate_notes_rss($xml_data, $username);
            header('Location: nisaba.php?article_guid=' . urlencode($guid));
            exit;
        }

        if (isset($_POST['edit_note'])) {
            $guid = $_POST['article_guid'];
            $new_title = $_POST['article_title'];
            $new_content = $_POST['note_content'];
            $note_to_edit = $xml_data->xpath('//note[article_guid="' . htmlspecialchars($guid) . '"]');
            if (!empty($note_to_edit)) {
                $note_to_edit[0]->article_title = $new_title;
                $note_to_edit[0]->content = $new_content;
            }
            $xml_data->asXML($userFile);
            generate_notes_rss($xml_data, $username);
            header('Location: nisaba.php?view=notes');
            exit;
        }

        if (isset($_POST['delete_note'])) {
            $guid = $_POST['delete_note'];
            $notes = $xml_data->xpath('//note[article_guid="' . htmlspecialchars($guid) . '"]');
            if (!empty($notes)) {
                unset($notes[0][0]);
            }
            $xml_data->asXML($userFile);
            generate_notes_rss($xml_data, $username);
            header('Location: nisaba.php?view=notes');
            exit;
        }

        if (isset($_POST['save_settings'])) {
            if (!isset($xml_data->settings)) {
                $xml_data->addChild('settings');
            }
            if (isset($_POST['gemini_api_key']) && !empty($_POST['gemini_api_key'])) {
                $xml_data->settings->gemini_api_key = $_POST['gemini_api_key'];
            }
            if (isset($_POST['google_translate_api_key'])) {
                $xml_data->settings->google_translate_api_key = $_POST['google_translate_api_key'];
            }
            if (isset($_POST['gemini_model'])) {
                $xml_data->settings->gemini_model = $_POST['gemini_model'];
            }
            if (isset($_POST['gemini_prompt']) && !empty($_POST['gemini_prompt'])) {
                $xml_data->settings->gemini_prompt = $_POST['gemini_prompt'];
            }
            if (isset($_POST['cache_duration'])) {
                $xml_data->settings->cache_duration = $_POST['cache_duration'];
            }
            if (isset($_POST['show_read_articles'])) {
                $xml_data->settings->show_read_articles = $_POST['show_read_articles'];
            }

            if ($xml_data->asXML($userFile)) {
                $_SESSION['settings_feedback'] = 'Configuración guardada correctamente.';
            } else {
                $_SESSION['settings_feedback'] = 'ERROR: No se pudo guardar la configuración en el archivo.';
            }
            
            header('Location: nisaba.php?view=settings');
            exit;
        }

        if (isset($_POST['add_feed'])) {
            $feed_url = trim($_POST['feed_url']);
            $folder_name = trim($_POST['folder_name']);
            if (empty($folder_name)) $folder_name = 'General';
            if (empty($feed_url) || !filter_var($feed_url, FILTER_VALIDATE_URL)) {
                $feed_error = 'Por favor, introduce una URL de feed válida.';
            } else {
                if (count($xml_data->xpath('//feed[@url="' . htmlspecialchars($feed_url) . '"]')) > 0) {
                    $feed_error = 'Ya estás suscrito a este feed.';
                } else {
                    $feed_title = $feed_url;
                    $context = stream_context_create(['http' => ['user_agent' => 'Nisaba Feed Reader']]);
                    $feed_content = @file_get_contents($feed_url, false, $context);
                    $feed_lang = '';
                    if ($feed_content) {
                        $feed_xml = @simplexml_load_string($feed_content);
                        if ($feed_xml) {
                            if (isset($feed_xml->channel->title)) {
                                $feed_title = (string)$feed_xml->channel->title;
                            } elseif (isset($feed_xml->title)) {
                                $feed_title = (string)$feed_xml->title;
                            }
                            if (isset($feed_xml->channel->language)) {
                                $feed_lang = strtolower((string)$feed_xml->channel->language);
                            } else {
                                $attrs = $feed_xml->attributes('xml', true);
                                if(isset($attrs['lang'])) {
                                    $feed_lang = strtolower((string)$attrs['lang']);
                                }
                            }
                        }
                    }
                    $favicon_path = get_favicon($feed_url);
                    if(!isset($xml_data->feeds)) $xml_data->addChild('feeds');
                    $folder = $xml_data->xpath('//folder[@name="' . htmlspecialchars($folder_name) . '"]');
                    $folder_node = !empty($folder) ? $folder[0] : $xml_data->feeds->addChild('folder');
                    if (empty($folder)) $folder_node->addAttribute('name', $folder_name);
                    $new_feed = $folder_node->addChild('feed');
                    $new_feed->addAttribute('url', $feed_url);
                    $new_feed->addAttribute('name', $feed_title);
                    $new_feed->addAttribute('favicon', $favicon_path);
                    $new_feed->addAttribute('lang', $feed_lang);
                    $xml_data->asXML($userFile);
                    header('Location: nisaba.php');
                    exit;
                }
            }
        }

        if (isset($_POST['delete_feed'])) {
            $feed_url = $_POST['feed_url'];
            $feeds = $xml_data->xpath('//feed[@url="' . htmlspecialchars($feed_url) . '"]');
            if (!empty($feeds)) {
                $parent = $feeds[0]->xpath('parent::*')[0];
                unset($feeds[0][0]);
                if ($parent->getName() === 'folder' && $parent->count() == 0) unset($parent[0]);
            }
            $xml_data->asXML($userFile);
            header('Location: nisaba.php');
            exit;
        }

        if (isset($_POST['edit_feed'])) {
            $original_url = $_POST['original_url'];
            $new_name = trim($_POST['feed_name']);
            $new_folder_name = trim($_POST['folder_name']);
            $new_lang = trim($_POST['feed_lang']);
            if(empty($new_folder_name)) $new_folder_name = 'General';
            $feeds = $xml_data->xpath('//feed[@url="' . htmlspecialchars($original_url) . '"]');
            if (!empty($feeds)) {
                $feed_node = $feeds[0];
                $feed_node['name'] = $new_name;
                $feed_node['lang'] = $new_lang;
                $old_folder = $feed_node->xpath('parent::*')[0];
                $old_folder_name = (string)$old_folder['name'];
                if ($old_folder_name !== $new_folder_name) {
                    $new_folder = $xml_data->xpath('//folder[@name="' . htmlspecialchars($new_folder_name) . '"]');
                    $new_folder_node = !empty($new_folder) ? $new_folder[0] : $xml_data->feeds->addChild('folder');
                    if (empty($new_folder)) $new_folder_node->addAttribute('name', $new_folder_name);
                    $dom_feed = dom_import_simplexml($feed_node);
                    $dom_new_folder = dom_import_simplexml($new_folder_node);
                    $dom_new_folder->appendChild($dom_feed);
                    if ($old_folder->count() == 0) unset($old_folder[0]);
                }
            }
            $xml_data->asXML($userFile);
            header('Location: nisaba.php?view=sources');
            exit;
        }
    }
}

// --- 4. RENDERIZADO HTML ---
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nisaba Feed Reader</title>
    <link rel="icon" href="nisaba.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700&family=Poppins:wght@500;700&family=VT323&display=swap" rel="stylesheet">
    <style>
        :root { --font-headline: 'Poppins', sans-serif; --font-body: 'Lato', sans-serif; --text-color: #333; --bg-color: #fff; --border-color: #e0e0e0; --accent-color: #007bff; --danger-color: #d9534f; }
        body { font-family: var(--font-body); color: var(--text-color); background-color: var(--bg-color); margin: 0; display: flex; flex-direction: column; min-height: 100vh; }
        .main-container { display: flex; flex-grow: 1; width: 80%; margin: 0 auto; }
        .sidebar { font-size: 1rem; width: 400px; border-right: 1px solid var(--border-color); padding: 1.5em; background: #f9f9f9; }
        .content { flex-grow: 1; padding: 1.5em; font-size: 1.2em; }
        .content h1 { font-size: 2.2em; }
        .content h2 { font-size: 1.8em; }
        .content h3 { font-size: 1.5em; }
        .content h4 { font-size: 1.2em; }
        a { color: var(--accent-color); text-decoration: none; }
        a:hover { text-decoration: underline; }
        footer { text-align: center; padding: 1em; border-top: 1px solid var(--border-color); font-size: 0.8em; color: #777; }
        footer img { vertical-align: middle; height: 1.2em; margin-left: 0.5em; }
        .logo { display: flex; justify-content: center; margin-bottom: 1em; }
        .logo img { height: 160px; }
        .auth-container { max-width: 400px; margin: 5em auto; padding: 2em; border: 1px solid var(--border-color); border-radius: 8px; }
        .form-group { margin-bottom: 1em; }
        .form-group label { display: block; margin-bottom: 0.5em; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 0.8em; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-family: var(--font-body); }
        .btn { display: inline-block; background-color: var(--accent-color); color: white; padding: 0.8em 1.2em; border: none; border-radius: 4px; cursor: pointer; text-align: center; font-family: var(--font-body); font-size: 1em;}
        .btn-danger { background-color: var(--danger-color); }
        .error { color: var(--danger-color); margin-bottom: 1em; }
        .sidebar nav ul { list-style: none; padding: 0; margin: 0; }
        .sidebar nav > ul > li { margin-bottom: 1em; }
        .sidebar-folder h4 { margin: 0 0 0.5em 0; cursor: pointer; }
        .sidebar-feeds { list-style: none; padding-left: 1em; }
        .sidebar-feeds li { margin-bottom: 0.5em; display: flex; align-items: center; gap: 8px; }
        .sidebar-feeds img { width: 16px; height: 16px; border-radius: 8px; }
        .feed-manage-list { list-style: none; padding: 0; }
        .feed-manage-list li { display: flex; justify-content: space-between; align-items: center; padding: 0.5em; border-bottom: 1px solid var(--border-color); }
        .feed-manage-list .feed-info { display: flex; align-items: center; gap: 10px; }
        .feed-manage-list .feed-info img { width: 20px; height: 20px; border-radius: 8px; }
        .feed-manage-list .feed-actions { display: flex; gap: 10px; }
        .feed-manage-list .edit-form { display: none; gap: 10px; }
        .article-list { list-style: none; padding: 0; }
        .article-item { border-bottom: 1px solid var(--border-color); padding: 1.5em 0; overflow: hidden; }
        .article-item.read h3 a, .article-item.read p { color: #999; }
        .article-item.read .article-image { filter: grayscale(100%); }
        .article-item:first-child { padding-top: 0; }
        .article-item .article-image { float: left; margin-right: 1.5em; width: 150px; height: 150px; object-fit: cover; border-radius: 8px; }
        .article-item h3 { margin: 0 0 0.2em 0; }
        .article-item h3 a { text-decoration: none; color: var(--text-color); }
        .article-item h3 a:hover { color: var(--accent-color); }
        .article-item p { margin: 0; }
        .article-full-content img { max-width: 100%; height: auto; border-radius: 8px; }
        .note-form textarea { width: 100%; min-height: 150px; }
        .summary-box { border: 1px solid #eee; padding: 1em; margin-bottom: 1em; border-radius: 4px; }
        .sidebar-feeds .folder-container { display: block; }
        .article-full-content { font-size: 1.1em; line-height: 1.6; }
        .font-size-controls { position: fixed; top: 10px; right: 10px; z-index: 1000; background: rgba(255,255,255,0.8); padding: 5px; border-radius: 5px; border: 1px solid #ccc; }
        .font-size-controls button { background: #fff; border: 1px solid #ccc; padding: 5px 10px; cursor: pointer; }
        .notes-container { column-count: 2; column-gap: 1em; }
        .note { display: inline-block; width: 100%; padding: 1em; margin-bottom: 1em; box-shadow: 2px 2px 5px rgba(0,0,0,0.2); transition: transform 0.2s; }
        .note:hover { transform: scale(1.05); }

        .summary-container {
            position: relative;
            margin-bottom: 1.5em;
        }
        .summary-box pre {
            background-color: #2d2d2d;
            color: #f1f1f1;
            border: 1px solid #444;
            border-radius: 8px;
            padding: 1.5em;
            font-family: 'VT323', monospace;
            white-space: pre-wrap;
            overflow-x: auto;
            margin-top: 0;
            font-size: 22px;
        }
        .summary-h1 {
            font-size: 1.2em;
            font-weight: bold;
        }
        .summary-h2 {
            text-decoration: underline;
        }

        .postit-textarea {
            background-color: #ffc;
            border: 1px solid #e5e5e5;
            box-shadow: 2px 2px 5px rgba(0,0,0,0.2);
            font-size: 18px;
            padding: 1em;
            min-height: 150px;
            width: 100%;
            box-sizing: border-box;
            font-family: var(--font-body);
            border-radius: 4px;
        }
        .copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: #e0e0e0;
            color: #333;
            border: none;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.8em;
            z-index: 10;
        }
        .copy-btn:hover {
            background-color: #ccc;
        }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['username'])): 
    ?>
        <div class="font-size-controls">
            <button id="decrease-font">-</button>
            <button id="increase-font">+</button>
        </div>
    <?php
        // --- 4.1. LÓGICA DE VISTA ---
        $current_view = 'all_feeds';
        if (isset($_GET['view'])) $current_view = $_GET['view'];
        if (isset($_GET['feed'])) $current_view = 'feed_articles';
        if (isset($_GET['article_guid'])) $current_view = 'single_article';


    ?>
        <div class="main-container">
            <aside class="sidebar">
                <div class="logo"><img src="nisaba.png" alt="Logo Nisaba"></div>
                <div style="display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 1.5em;">
                    <a href="?update_cache=1" class="btn">Actualizar Feeds</a>
                    <a href="?action=translate" class="btn">Traducir Nuevos</a>
                    <a href="?view=nisaba_summary" class="btn">Análisis</a>
                </div>
                <nav>
                    <ul>
                        <li class="sidebar-folder">
                            <h4>Tus Fuentes</h4>
                            <ul class="sidebar-feeds">
                                <?php
                                $sidebar_cache_xml = null;
                                if (file_exists($cacheFile)) {
                                    $sidebar_cache_xml = simplexml_load_file($cacheFile);
                                }
                                $total_unread_count = 0;
                                if ($sidebar_cache_xml) {
                                    $total_unread_count = count($sidebar_cache_xml->xpath('//item[read="0"]'));
                                }
                                ?>
                                <li>
                                    <a href="?view=all_feeds">Todas</a>
                                    <?php if ($total_unread_count > 0): ?>
                                        <span style="color: SkyBlue; font-size: 0.8em; margin-left: 5px;">(<?php echo $total_unread_count; ?>)</span>
                                    <?php endif; ?>
                                </li>
                                <?php if(isset($xml_data->feeds)) { foreach ($xml_data->feeds->folder as $folder): ?>
                                    <?php
                                        $folder_unread_count = 0;
                                        if ($sidebar_cache_xml) {
                                            $feed_urls_in_folder = [];
                                            foreach ($folder->feed as $feed) {
                                                $feed_urls_in_folder[] = (string)$feed['url'];
                                            }
                                            if (!empty($feed_urls_in_folder)) {
                                                $xpath_query = '//item[read="0" and (' . implode(' or ', array_map(function($url) {
                                                    return 'feed_url="' . $url . '"';
                                                }, $feed_urls_in_folder)) . ')]';
                                                $folder_unread_count = count($sidebar_cache_xml->xpath($xpath_query));
                                            }
                                        }
                                    ?>
                                    <li class="folder-container">
                                        <a href="?view=folder&name=<?php echo urlencode((string)$folder['name']); ?>">
                                            <strong><?php echo htmlspecialchars($folder['name']); ?></strong>
                                        </a>
                                        <?php if ($folder_unread_count > 0): ?>
                                            <span style="color: SkyBlue; font-size: 0.8em; margin-left: 5px;">(<?php echo $folder_unread_count; ?>)</span>
                                        <?php endif; ?>

                                        <?php
                                            $folder_name_for_check = (string)$folder['name'];
                                            $is_current_folder = (isset($_GET['view']) && $_GET['view'] === 'folder' && isset($_GET['name']) && $_GET['name'] === $folder_name_for_check) || 
                                                                 (isset($_GET['folder']) && $_GET['folder'] === $folder_name_for_check);
                                            $display_style = $is_current_folder ? 'block' : 'none';
                                        ?>
                                        <ul class="sidebar-feeds feed-list" style="display: <?php echo $display_style; ?>; padding-left: 1em; margin-top: 0.5em;">
                                            <?php foreach ($folder->feed as $feed): ?>
                                                <?php
                                                    $feed_unread_count = 0;
                                                    if ($sidebar_cache_xml) {
                                                        $feed_url_for_xpath = (string)$feed['url'];
                                                        $feed_unread_count = count($sidebar_cache_xml->xpath('//item[feed_url="' . $feed_url_for_xpath . '" and read="0"]'));
                                                    }
                                                ?>
                                                <li>
                                                    <img src="<?php echo htmlspecialchars($feed['favicon']); ?>" alt="">
                                                    <a href="?feed=<?php echo urlencode($feed['url']); ?>&folder=<?php echo urlencode((string)$folder['name']); ?>"><?php echo htmlspecialchars($feed['name']); ?></a>
                                                    <?php if ($feed_unread_count > 0): ?>
                                                        <span style="color: SkyBlue; font-size: 0.8em; margin-left: 5px;">(<?php echo $feed_unread_count; ?>)</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </li>
                                <?php endforeach; } ?>
                            </ul>
                        </li>
                        
                        <hr style="margin: 1.5em 0;">

                        <li><a href="?view=notes">Notas</a></li>
                        <li><a href="?view=sources">Gestionar Fuentes</a></li>
                        <li><a href="?view=settings">Configuración y Preferencias</a></li>
                        <li><a href="?logout=1">Cerrar sesión</a></li>
                    </ul>
                </nav>
            </aside>
            <main class="content">
                <?php
                if (isset($_SESSION['translate_feedback'])) {
                    $feedback = $_SESSION['translate_feedback'];
                    $color = $feedback['type'] === 'error' ? 'var(--danger-color)' : ($feedback['type'] === 'success' ? 'green' : 'blue');
                    echo '<p style="color: ' . $color . '; border: 1px solid ' . $color . '; padding: 1em; border-radius: 4px;">' . htmlspecialchars($feedback['message']) . '</p>';
                    unset($_SESSION['translate_feedback']);
                }
                ?>
                <?php 
                if ($current_view === 'nisaba_summary'):
                ?>
                    <h2>Nisaba</h2>
                    <?php
                    $has_any_unread = false;
                    if (file_exists($cacheFile)) {
                        $cache_xml = simplexml_load_file($cacheFile);
                        if($cache_xml) {
                            $current_update_id = isset($xml_data->settings->last_update_id) ? (string)$xml_data->settings->last_update_id : '';

                            foreach ($xml_data->feeds->folder as $folder) {
                                $folder_name = (string)$folder['name'];
                                $content_for_folder = '';
                                foreach ($folder->feed as $feed) {
                                    $feed_url = (string)$feed['url'];
                                    $unread_articles = $cache_xml->xpath('//item[read="0" and feed_url="' . $feed_url . '"]');
                                    foreach ($unread_articles as $item) {
                                        $title = !empty($item->title_translated) ? $item->title_translated : $item->title_original;
                                        $content = !empty($item->content_translated) ? $item->content_translated : $item->content_original;
                                        $content_for_folder .= "Título: " . $title . "\nContenido: " . strip_tags($content) . "\n---\n";
                                    }
                                }

                                if (!empty($content_for_folder)) {
                                    $has_any_unread = true;

                                    $summary_text = '';
                                    $existing_summary = null;
                                    if (!empty($current_update_id)) {
                                         $summaries = $xml_data->xpath('//summary[@folder="' . htmlspecialchars($folder_name) . '" and @update_id="' . $current_update_id . '"]');
                                         if (!empty($summaries)) {
                                             $existing_summary = $summaries[0];
                                         }
                                    }

                                    if ($existing_summary) {
                                        $summary_text = (string)$existing_summary;
                                    } else {
                                        $summary_text = get_gemini_summary($content_for_folder, $gemini_api_key, $gemini_model, $gemini_prompt);
                                        if (!str_starts_with($summary_text, 'Error')) {
                                            $old_summaries = $xml_data->xpath('//summary[@folder="' . htmlspecialchars($folder_name) . '"]');
                                            foreach ($old_summaries as $old_summary) {
                                                unset($old_summary[0]);
                                            }
                                            if (!isset($xml_data->summaries)) $xml_data->addChild('summaries');
                                            $new_summary = $xml_data->summaries->addChild('summary', $summary_text);
                                            $new_summary->addAttribute('folder', $folder_name);
                                            if (!empty($current_update_id)) {
                                                $new_summary->addAttribute('update_id', $current_update_id);
                                            }
                                            $xml_data->asXML($userFile);
                                        }
                                    }

                                    echo '<h3>' . htmlspecialchars($folder_name) . '</h3>';
                                    echo '<div class="summary-container">';
                                    echo '<button class="copy-btn" onclick="copySummary(this)">Copiar</button>';
                                    echo '<div class="summary-box"><pre class="summary-content">';
                                    
                                    $replacements = [
                                        "Análisis General del Bloque" => '<span class="summary-h1">Análisis General del Bloque</span>',
                                        "Señales Débiles y Disrupciones Identificadas" => '<span class="summary-h1">Señales Débiles y Disrupciones Identificadas</span>',
                                        "Síntesis de Impacto" => '<span class="summary-h2">Síntesis de Impacto</span>'
                                    ];
                                    $styled_summary = str_replace(array_keys($replacements), array_values($replacements), $summary_text);

                                    echo $styled_summary;

                                    echo '</pre></div></div>';

                                    $note_guid = 'summary_' . md5($folder_name . $current_update_id);
                                    $note_title = "Análisis de la carpeta: " . htmlspecialchars($folder_name);
                                    $note_link = "nisaba.php?view=nisaba_summary";
                                    $note_text = '';
                                    if(isset($xml_data->notes)) {
                                        $notes = $xml_data->xpath('//note[article_guid="' . htmlspecialchars($note_guid) . '"]');
                                        if (!empty($notes)) $note_text = (string)$notes[0]->content;
                                    }

                                    echo '<form method="POST" action="nisaba.php?view=nisaba_summary" class="note-form" style="margin-top: 1em;">';
                                    echo '    <input type="hidden" name="article_guid" value="' . htmlspecialchars($note_guid) . '">';
                                    echo '    <input type="hidden" name="article_title" value="' . htmlspecialchars($note_title) . '">';
                                    echo '    <input type="hidden" name="article_link" value="' . htmlspecialchars($note_link) . '">';
                                    echo '    <div class="form-group">';
                                    echo '        <textarea name="note_content" class="postit-textarea" placeholder="Escribe una nota sobre este análisis...">' . htmlspecialchars($note_text) . '</textarea>';
                                    echo '    </div>';
                                    echo '    <button type="submit" name="save_note" class="btn">Guardar Nota</button>';
                                    echo '</form>';

                                    echo '<hr>';
                                }
                            }
                        }
                    }
                    if (!$has_any_unread) {
                        echo "<p>¡Estás al día! No hay artículos nuevos que resumir.</p>";
                    }
                    ?>
                <?php elseif ($current_view === 'single_article'): ?>
                    <?php
                        $article_guid = $_GET['article_guid'];
                        $article = null;
                        $favicon_url = 'nisaba.png';

                        // Defensively reload user data to ensure settings are fresh
                        $fresh_xml_data = simplexml_load_file($userFile);
                        $fresh_cache_duration = isset($fresh_xml_data->settings->cache_duration) ? (string)$fresh_xml_data->settings->cache_duration : '24';

                        if (file_exists($cacheFile)) {
                            $cache_xml = simplexml_load_file($cacheFile);
                            if($cache_xml) {
                                $articles = $cache_xml->xpath('//item[guid="' . htmlspecialchars($article_guid) . '"]');
                                if(!empty($articles)) {
                                    $article = clone $articles[0]; // Clone for rendering before modification

                                    // Perform update/delete logic on the original object
                                    if ($fresh_cache_duration === '0') {
                                        if (!isset($fresh_xml_data->read_guids)) $fresh_xml_data->addChild('read_guids');
                                        if (count($fresh_xml_data->xpath('//read_guids/guid[.="' . htmlspecialchars((string)$articles[0]->guid) . '"]')) == 0) {
                                            $fresh_xml_data->read_guids->addChild('guid', (string)$articles[0]->guid);
                                            $fresh_xml_data->asXML($userFile);
                                        }
                                        unset($articles[0][0]);
                                    } else {
                                        $articles[0]->read = 1;
                                        if (!isset($articles[0]->read_at)) {
                                           $articles[0]->addChild('read_at', time());
                                        }
                                    }
                                    $cache_xml->asXML($cacheFile);

                                    // Get favicon from the cloned object
                                    $feed_url = (string)$article->feed_url;
                                    $feeds = $xml_data->xpath('//feed[@url="' . htmlspecialchars($feed_url) . '"]');
                                    if (!empty($feeds)) {
                                        $favicon_url = (string)$feeds[0]['favicon'];
                                    }
                                }
                            }
                        }
                    ?>
                    <?php if ($article): ?>
                        <a href="?feed=<?php echo urlencode((string)$article->feed_url); ?>&folder=<?php echo urlencode($_GET['folder'] ?? ''); ?>">&larr; Volver al feed</a>
                        <hr>
                        <?php 
                            $article_title = !empty($article->title_translated) ? $article->title_translated : $article->title_original;
                            $article_content = !empty($article->content_translated) ? $article->content_translated : $article->content_original;
                            $article_image = (string)$article->image;
                        ?>
                        <h2><img src="<?php echo htmlspecialchars($favicon_url); ?>" alt="" style="width: 32px; height: 32px; vertical-align: middle; margin-right: 10px; border-radius: 4px;"> <?php echo htmlspecialchars($article_title); ?></h2>
                        <?php if (!empty($article_image)): ?>
                            <img src="<?php echo htmlspecialchars($article_image); ?>" alt="" style="max-width: 100%; height: auto; border-radius: 8px; margin-bottom: 1em;">
                        <?php endif; ?>
                        <div class="article-full-content">
                            <?php 
                                if (strpos($article_content, '<p>') !== false || strpos($article_content, '<div>') !== false) {
                                    echo $article_content;
                                } else {
                                    echo nl2br($article_content);
                                }
                            ?>
                        </div>
                        <hr>
                        <a href="<?php echo htmlspecialchars($article->link); ?>" target="_blank" class="btn">Ver original</a>
                        <hr>
                        <h3>Notas</h3>
                        <form method="POST" action="nisaba.php" class="note-form">
                            <input type="hidden" name="article_guid" value="<?php echo htmlspecialchars($article->guid); ?>">
                            <input type="hidden" name="article_title" value="<?php echo htmlspecialchars($article_title); ?>">
                            <input type="hidden" name="article_link" value="<?php echo htmlspecialchars($article->link); ?>">
                            <div class="form-group">
                                <?php
                                    $note_text = '';
                                    if(isset($xml_data->notes)) {
                                        $notes = $xml_data->xpath('//note[article_guid="' . htmlspecialchars($article->guid) . '"]');
                                        if (!empty($notes)) $note_text = (string)$notes[0]->content;
                                    }
                                ?>
                                <textarea name="note_content" class="postit-textarea"><?php echo htmlspecialchars($note_text); ?></textarea>
                            </div>
                            <button type="submit" name="save_note" class="btn">Guardar Nota</button>
                        </form>
                    <?php else: ?>
                        <p>Artículo no encontrado.</p>
                    <?php endif; ?>
                <?php elseif ($current_view === 'all_feeds'): ?>
                    <div style="margin-bottom: 1em;"><button onclick="markAllRead()" class="btn">Todos leídos</button></div>
                    <?php
                        $unread_count = 0;
                        if (file_exists($cacheFile)) {
                            $count_cache_xml = simplexml_load_file($cacheFile);
                            if ($count_cache_xml) {
                                $unread_count = count($count_cache_xml->xpath('//item[read="0"]'));
                            }
                        }
                    ?>
                    <h2>Todas las Fuentes (<?php echo $unread_count; ?>)</h2>
                    <ul class="article-list">
                        <?php
                        if (file_exists($cacheFile)) {
                            $cache_xml = simplexml_load_file($cacheFile);
                            if($cache_xml) {
                                $articles = $cache_xml->xpath('//item');
                                if (empty($articles)) { echo "<li>No hay artículos. Prueba a actualizar.</li>"; } 
                                else { 
                                    $sorted_articles = [];
                                    foreach ($articles as $item) {
                                        $sorted_articles[] = $item;
                                    }
                                    usort($sorted_articles, function($a, $b) {
                                        return strtotime((string)$b->pubDate) - strtotime((string)$a->pubDate);
                                    });

                                    foreach ($sorted_articles as $item) {
                                        $is_read = (string)$item->read === '1';
                                        if ($is_read && $show_read_articles === 'false') continue;

                                        $display_title = !empty($item->title_translated) ? $item->title_translated : $item->title_original;
                                        $display_desc = !empty($item->content_translated) ? $item->content_translated : $item->content_original;
                                        echo '<li class="article-item' . ($is_read ? ' read' : '') . '" data-guid="' . htmlspecialchars($item->guid) . '">';
                                        if (!empty($item->image)) echo '<img src="' . htmlspecialchars($item->image) . '" alt="" class="article-image">';
                                        echo '<h3><a href="?article_guid=' . urlencode($item->guid) . '">' . htmlspecialchars($display_title) . '</a></h3>';
                                        echo '<p>' . htmlspecialchars(truncate_text($display_desc, 100)) . '</p>';
                                        if (!$is_read) {
                                            echo '<div style="clear: both; padding-top: 10px;"><a href="?action=mark_read&guid=' . urlencode($item->guid) . '&return_url=' . urlencode($_SERVER['REQUEST_URI']) . '" onclick="markAsRead(this, \'' . urlencode($item->guid) . '\'); return false;" class="btn mark-as-read-btn">Marcar leído</a></div>';
                                        }
                                        echo '</li>';
                                    }
                                }
                            } else { echo "<li>Error al leer la caché.</li>"; }
                        } else { echo "<li>No se ha generado la cache.</li>"; }
                        ?>
                    </ul>
                <?php elseif ($current_view === 'folder'): ?>
                    <?php 
                        $folder_name = isset($_GET['name']) ? $_GET['name'] : '';
                        $unread_count = 0;
                        if (file_exists($cacheFile) && !empty($folder_name)) {
                            $feeds_in_folder = $xml_data->xpath('//folder[@name="' . htmlspecialchars($folder_name) . '"]/feed');
                            $feed_urls = [];
                            foreach ($feeds_in_folder as $feed) {
                                $feed_urls[] = (string)$feed['url'];
                            }
                            $count_cache_xml = simplexml_load_file($cacheFile);
                            if (!empty($feed_urls) && $count_cache_xml) {
                                $unread_xpath_query = '//item[read="0" and (' . implode(' or ', array_map(function($url) {
                                    return 'feed_url="' . $url . '"';
                                }, $feed_urls)) . ')]';
                                $unread_count = count($count_cache_xml->xpath($unread_xpath_query));
                            }
                        }
                    ?>
                    <div style="margin-bottom: 1em;"><button onclick="markAllRead()" class="btn">Todos leídos</button></div>
                    <h2>Carpeta: <?php echo htmlspecialchars($folder_name); ?> (<?php echo $unread_count; ?>)</h2>
                    <ul class="article-list">
                        <?php
                        if (file_exists($cacheFile) && !empty($folder_name)) {
                            $feeds_in_folder = $xml_data->xpath('//folder[@name="' . htmlspecialchars($folder_name) . '"]/feed');
                            $feed_urls = [];
                            foreach ($feeds_in_folder as $feed) {
                                $feed_urls[] = (string)$feed['url'];
                            }
                            $cache_xml = simplexml_load_file($cacheFile);
                            $articles = [];
                            if (!empty($feed_urls) && $cache_xml) {
                                $xpath_query = '//item[' . implode(' or ', array_map(function($url) {
                                    return 'feed_url="' . $url . '"';
                                }, $feed_urls)) . ']';
                                $articles = $cache_xml->xpath($xpath_query);
                            }
                            if (empty($articles)) { echo "<li>No hay artículos en esta carpeta. Prueba a actualizar.</li>"; } 
                            else { 
                                $sorted_articles = [];
                                foreach ($articles as $item) {
                                    $sorted_articles[] = $item;
                                }
                                usort($sorted_articles, function($a, $b) {
                                    return strtotime((string)$b->pubDate) - strtotime((string)$a->pubDate);
                                });
                                foreach ($sorted_articles as $item) {
                                    $is_read = (string)$item->read === '1';
                                    if ($is_read && $show_read_articles === 'false') continue;

                                    $display_title = !empty($item->title_translated) ? $item->title_translated : $item->title_original;
                                    $display_desc = !empty($item->content_translated) ? $item->content_translated : $item->content_original;
                                    echo '<li class="article-item' . ($is_read ? ' read' : '') . '" data-guid="' . htmlspecialchars($item->guid) . '">';
                                    if (!empty($item->image)) echo '<img src="' . htmlspecialchars($item->image) . '" alt="" class="article-image">';
                                    echo '<h3><a href="?article_guid=' . urlencode($item->guid) . '&folder=' . urlencode($folder_name) . '">' . htmlspecialchars($display_title) . '</a></h3>';
                                    echo '<p>' . htmlspecialchars(truncate_text($display_desc, 250)) . '</p>';
                                    if (!$is_read) {
                                        echo '<div style="clear: both; padding-top: 10px;"><a href="?action=mark_read&guid=' . urlencode($item->guid) . '&return_url=' . urlencode($_SERVER['REQUEST_URI']) . '" onclick="markAsRead(this, \'' . urlencode($item->guid) . '\'); return false;" class="btn mark-as-read-btn">Marcar leído</a></div>';
                                    }
                                    echo '</li>';
                                }
                            }
                        } else { echo "<li>Carpeta no encontrada o cache no generada.</li>"; }
                        ?>
                    </ul>
                <?php elseif ($current_view === 'feed_articles'): ?>
                    <?php
                        $selected_feed_url = $_GET['feed'];
                        $feed_name = 'Feed Desconocido';
                        $favicon_url = 'nisaba.png';
                        $unread_count = 0;

                        $feeds = $xml_data->xpath('//feed[@url="' . htmlspecialchars($selected_feed_url) . '"]');
                        if (!empty($feeds)) {
                            $feed_name = (string)$feeds[0]['name'];
                            $favicon_url = (string)$feeds[0]['favicon'];
                        }

                        if (file_exists($cacheFile)) {
                            $count_cache_xml = simplexml_load_file($cacheFile);
                            if ($count_cache_xml) {
                                $unread_count = count($count_cache_xml->xpath('//item[read="0" and feed_url="' . $selected_feed_url . '"]'));
                            }
                        }
                    ?>
                    <div style="margin-bottom: 1em;"><button onclick="markAllRead()" class="btn">Todos leídos</button></div>
                    <h2><img src="<?php echo htmlspecialchars($favicon_url); ?>" alt="" style="width: 32px; height: 32px; vertical-align: middle; margin-right: 10px; border-radius: 4px;"> <?php echo htmlspecialchars($feed_name); ?> (<?php echo $unread_count; ?>)</h2>
                    <ul class="article-list">
                        <?php
                        if (file_exists($cacheFile)) {
                            $cache_xml = simplexml_load_file($cacheFile);
                            if($cache_xml){
                                $articles = $cache_xml->xpath('//item[feed_url="' . $selected_feed_url . '"]');
                                if (empty($articles)) { echo "<li>No hay artículos. Prueba a actualizar.</li>"; } 
                                else { foreach ($articles as $item) {
                                    $is_read = (string)$item->read === '1';
                                    if ($is_read && $show_read_articles === 'false') continue;

                                    $display_title = !empty($item->title_translated) ? $item->title_translated : $item->title_original;
                                    $display_desc = !empty($item->content_translated) ? $item->content_translated : $item->content_original;
                                    echo '<li class="article-item' . ($is_read ? ' read' : '') . '" data-guid="' . htmlspecialchars($item->guid) . '">';
                                    if (!empty($item->image)) echo '<img src="' . htmlspecialchars($item->image) . '" alt="" class="article-image">';
                                    echo '<h3><a href="?article_guid=' . urlencode($item->guid) . '&folder=' . urlencode($_GET['folder'] ?? '') . '">' . htmlspecialchars($display_title) . '</a></h3>';
                                    echo '<p>' . htmlspecialchars(truncate_text($display_desc, 250)) . '</p>';
                                    if (!$is_read) {
                                        echo '<div style="clear: both; padding-top: 10px;"><a href="?action=mark_read&guid=' . urlencode($item->guid) . '&return_url=' . urlencode($_SERVER['REQUEST_URI']) . '" onclick="markAsRead(this, \'' . urlencode($item->guid) . '\'); return false;" class="btn mark-as-read-btn">Marcar leído</a></div>';
                                    }
                                    echo '</li>';
                                }}
                            } else { echo "<li>Error al leer la caché.</li>"; }
                        } else { echo "<li>No se ha generado la cache.</li>"; }
                        ?>
                    </ul>
                <?php elseif ($current_view === 'notes'): ?>
                    <h2>Notas</h2>
                    <div class="notes-container">
                        <?php
                        if (isset($xml_data->notes)) {
                            $notes_nodes = $xml_data->xpath('//note');
                            $notes_array = [];
                            foreach ($notes_nodes as $note) {
                                $notes_array[] = $note;
                            }
                            usort($notes_array, function($a, $b) {
                                return strtotime((string)$b->date) - strtotime((string)$a->date);
                            });

                            $colors = ['#ffc', '#cfc', '#ccf', '#fcc', '#fcf', '#cff'];
                            $i = 0;
                            foreach ($notes_array as $note) {
                                $color = $colors[$i % count($colors)];
                                echo '<div class="note" style="background-color:' . $color . '">';
                                
                                echo '<div class="note-display">';
                                echo '<h4><a href="' . htmlspecialchars($note->article_link) . '" target="_blank">' . htmlspecialchars($note->article_title) . '</a></h4>';
                                echo '<p>' . nl2br(htmlspecialchars($note->content)) . '</p>';
                                echo '<div style="display: flex; gap: 10px; margin-top: 1em;">';
                                echo '<button class="btn" onclick="toggleNoteEdit(this)">Editar</button>';
                                echo '<form method="POST" action="nisaba.php?view=notes" onsubmit="return confirm(\'¿Seguro que quieres eliminar esta nota?\');">';
                                echo '<input type="hidden" name="delete_note" value="' . htmlspecialchars($note->article_guid) . '">';
                                echo '<button type="submit" class="btn btn-danger">Eliminar</button>';
                                echo '</form></div></div>';

                                echo '<form method="POST" action="nisaba.php?view=notes" class="note-edit-form" style="display:none;">';
                                echo '<input type="hidden" name="article_guid" value="' . htmlspecialchars($note->article_guid) . '">';
                                echo '<div class="form-group"><label>Título</label><input type="text" name="article_title" value="' . htmlspecialchars($note->article_title) . '" class="form-group input"></div>';
                                echo '<div class="form-group"><label>Contenido</label><textarea name="note_content" class="postit-textarea">' . htmlspecialchars($note->content) . '</textarea></div>';
                                echo '<div style="display: flex; gap: 10px;"><button type="submit" name="edit_note" class="btn">Guardar</button>';
                                echo '<button type="button" onclick="toggleNoteEdit(this)" class="btn btn-danger">Cancelar</button></div>';
                                echo '</form>';

                                echo '</div>';
                                $i++;
                            }
                        } else {
                            echo '<p>No hay notas todavía.</p>';
                        }
                        ?>
                    </div>
                <?php elseif ($current_view === 'settings'): ?>
                    <h2>Configuración y Preferencias</h2>
                    <?php 
                        if (isset($_SESSION['settings_feedback'])) {
                            echo '<p style="color: green;">' . htmlspecialchars($_SESSION['settings_feedback']) . '</p>';
                            unset($_SESSION['settings_feedback']);
                        }
                    ?>
                    <form method="POST" action="?view=settings">
                        <div class="form-group">
                            <label for="gemini_api_key">API Key de Google Gemini</label>
                            <input type="password" id="gemini_api_key" name="gemini_api_key" placeholder="************">
                        </div>
                        <div class="form-group">
                            <label for="gemini_model">Modelo de Gemini</label>
                            <select id="gemini_model" name="gemini_model" class="form-group input">
                                <?php
                                    $available_models = get_gemini_models($gemini_api_key);
                                    $selected_model = isset($xml_data->settings->gemini_model) ? (string)$xml_data->settings->gemini_model : '';

                                    if (!empty($available_models)) {
                                        if (empty($selected_model)) $selected_model = $available_models[0]['id']; // Default to first model in list
                                        foreach($available_models as $model) {
                                            echo '<option value="' . htmlspecialchars($model['id']) . '"' . ($selected_model === $model['id'] ? ' selected' : '') . '>' . htmlspecialchars($model['name']) . '</option>';
                                        }
                                    } else {
                                        // Fallback if API key is not set or API call fails
                                        $default_models = ['gemini-1.5-pro-latest', 'gemini-1.5-flash-latest', 'gemini-pro'];
                                        if (empty($selected_model) || !in_array($selected_model, $default_models)) $selected_model = 'gemini-1.5-pro-latest';
                                        foreach($default_models as $model_id) {
                                             echo '<option value="' . $model_id . '"' . ($selected_model === $model_id ? ' selected' : '') . '>' . $model_id . '</option>';
                                        }
                                        if (empty($gemini_api_key)) {
                                            echo '<option value="" disabled>Introduce una API key para ver los modelos</option>';
                                        } else {
                                            echo '<option value="" disabled>No se pudieron cargar los modelos desde la API</option>';
                                        }
                                    }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="gemini_prompt">Prompt para el análisis de Gemini</label>
                            <textarea id="gemini_prompt" name="gemini_prompt" style="min-height: 150px;"><?php echo htmlspecialchars(isset($xml_data->settings->gemini_prompt) ? (string)$xml_data->settings->gemini_prompt : 'ROL Y OBJETIVO
Actúa como un analista de prospectiva estratégica y horizon scanning. Tu misión es analizar bloques de noticias y artículos de opinión provenientes de una misma región o ámbito para identificar "señales débiles" (weak signals). Estas señales son eventos, ideas o tendencias sutiles, emergentes o inesperadas que podrían anticipar o catalizar disrupciones significativas a nivel político, económico, tecnológico, social, cultural o medioambiental.

Tu objetivo principal es sintetizar y destacar únicamente las piezas que apunten a una potencial ruptura de tendencia, un cambio de paradigma naciente o una tensión estratégica emergente, ignorando por completo la información rutinaria, predecible o de seguimiento.

CONTEXTO Y VECTORES DE DISRUPCIÓN
Evaluarás cada noticia dentro del bloque en función de su potencial para señalar un cambio en los siguientes vectores de disrupción:

1. Geopolítica y Política:

Reconfiguración de Alianzas: Acuerdos o tensiones inesperadas entre países, cambios en bloques de poder.

Nuevas Regulaciones Estratégicas: Leyes que alteran radicalmente un sector clave (energía, tecnología, finanzas).

Inestabilidad o Movimientos Sociales: Protestas con nuevas formas de organización, surgimiento de movimientos políticos disruptivos, crisis institucionales.

Cambios en Doctrina Militar o de Seguridad: Nuevas estrategias de defensa, ciberseguridad o control de fronteras con implicaciones amplias.

2. Economía y Mercado:

Nuevos Modelos de Negocio: Empresas que ganan tracción con una lógica de mercado radicalmente diferente.

Fragilidades en Cadenas de Suministro: Crisis en nodos logísticos, escasez de materiales críticos que fuerzan una reorganización industrial.

Anomalías Financieras: Inversiones de capital riesgo en sectores o geografías "olvidadas", comportamientos extraños en los mercados, surgimiento de activos no tradicionales.

Conflictos Laborales Paradigmáticos: Huelgas, negociaciones o movimientos sindicales que apuntan a un cambio en la relación capital-trabajo.

3. Tecnología y Ciencia:

Avances Fundamentales: Descubrimientos científicos o tecnológicos (no incrementales) que abren campos completamente nuevos (ej. computación cuántica, biotecnología, nuevos materiales).

Adopción Inesperada de Tecnología: Una tecnología nicho que empieza a ser adoptada masivamente en un sector imprevisto.

Vulnerabilidades Sistémicas: Descubrimiento de fallos de seguridad o éticos en tecnologías de uso generalizado.

Democratización del Acceso: Tecnologías avanzadas (IA, biohacking, etc.) que se vuelven accesibles y de código abierto, permitiendo usos no controlados.

4. Sociedad y Cultura:

Cambios en Valores o Comportamientos: Datos que indican un cambio rápido en la opinión pública sobre temas fundamentales (familia, trabajo, privacidad), nuevos patrones de consumo.

Surgimiento de Subculturas Influyentes: Movimientos contraculturales o nichos que empiezan a permear en la cultura mayoritaria.

Tensiones Demográficas o Migratorias: Cambios en flujos migratorios, envejecimiento poblacional o tasas de natalidad que generan nuevas presiones sociales.

Narrativas y Debates Emergentes: Ideas o debates marginales que ganan repentinamente visibilidad mediática o académica.

5. Medio Ambiente y Energía:

Eventos Climáticos Extremos con Impacto Sistémico: Desastres naturales que revelan fragilidades críticas en la infraestructura o la economía.

Innovación en Energía o Recursos: Avances en fuentes de energía, almacenamiento o reciclaje que podrían alterar el paradigma energético.

Escasez Crítica de Recursos: Agotamiento o conflicto por recursos básicos (agua, minerales raros) que escala a nivel político o económico.

Activismo y Litigios Climáticos: Acciones legales o movimientos de activismo que logran un impacto significativo en la política corporativa o gubernamental.

PROCESO DE RAZONAMIENTO (Paso a Paso)
Al recibir un bloque de noticias, sigue internamente este proceso:

Visión de Conjunto: Lee rápidamente los titulares del bloque para entender el contexto general ({{contexto_del_bloque}}).

Análisis Individual: Para cada noticia del bloque, evalúa:

Clasificación: ¿Se alinea con alguno de los vectores de disrupción listados?

Evaluación de Señal: ¿Es un evento predecible y esperado (ruido) o es una señal genuina de cambio? Mide su nivel de "sorpresa", "anomalía" o "potencial de segundo orden".

Filtrado: Descarta mentalmente todas las noticias que sean ruido o información incremental.

Síntesis y Agrupación: De las noticias filtradas, agrúpalas si apuntan a una misma macrotendencia. Formula una síntesis global que conecte los puntos.

Generación de la Salida: Construye el informe final siguiendo el formato estricto.

DATOS DE ENTRADA
Contexto del Bloque: {{contexto_del_bloque}} (Ej: "Noticias de España", "Artículos de opinión de medios europeos", "Actualidad tecnológica de China")

Bloque de Noticias: {{bloque_de_noticias}} (Una lista o conjunto de artículos, cada uno con título, descripción y enlace)

FORMATO DE SALIDA Y REGLAS
Existen dos posibles salidas: un informe de disrupción o una notificación de ausencia de señales.

1. Si identificas al menos una señal relevante, genera un informe con ESTE formato EXACTO:

Análisis General del Bloque
Síntesis ejecutiva (máximo 4 frases) que resume las principales corrientes de cambio o tensiones detectadas en el bloque de noticias. Conecta las señales si es posible.

Señales Débiles y Disrupciones Identificadas

Título conciso del primer hallazgo en español
{{enlace_de_la_noticia}}

Síntesis de Impacto: Una o dos frases que capturan por qué esta noticia es estratégicamente relevante, no un simple resumen.

Explicación de la Señal: Explicación concisa (máximo 5 frases) que justifica la elección, conectando la noticia con uno o más vectores de disrupción y explorando sus posibles implicaciones de segundo o tercer orden.

Título conciso del segundo hallazgo en español
{{enlace_de_la_noticia}}

Síntesis de Impacto: ...

Explicación de la Señal: ...

(Repetir para cada señal identificada)

Reglas estrictas para el informe:

Jerarquía: El análisis general siempre va primero y debe ofrecer una visión conectada.

Enfoque en la Implicación: Tanto la síntesis como la explicación deben centrarse en el "y qué" (so what?), no en el "qué" (what).

Sin Adornos: No añadas emojis, comillas innecesarias, etiquetas extra, ni texto introductorio o de cierre.

2. Si el bloque de noticias NO contiene ninguna señal de disrupción genuina, responde únicamente con:

No se han detectado señales de disrupción significativas en este bloque de noticias.'); ?></textarea>
                        </div>
                        <hr>
                        <div class="form-group">
                            <label for="google_translate_api_key">API Key de Google Translate</label>
                            <input type="password" id="google_translate_api_key" name="google_translate_api_key" placeholder="************" value="<?php echo htmlspecialchars($google_api_key); ?>">
                            <p style="font-size: 0.8em; color: #555;">Clave guardada: <code><?php echo mask_api_key($google_api_key); ?></code></p>
                        </div>
                        <div class="form-group">
                            <label for="cache_duration">Borrar artículos leídos de la caché</label>
                            <select id="cache_duration" name="cache_duration" class="form-group input">
                                <?php
                                    $durations = ['0' => 'Al leer', '24' => 'A las 24 horas', '48' => 'A las 48 horas'];
                                    $selected_duration = isset($xml_data->settings->cache_duration) ? (string)$xml_data->settings->cache_duration : '24';
                                    foreach($durations as $value => $label) {
                                        echo '<option value="' . $value . '"' . ($selected_duration === $value ? ' selected' : '') . '>' . $label . '</option>';
                                    }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Gestión de la Caché</label>
                            <a href="?action=purge_cache" class="btn">Purgar artículos antiguos ahora</a>
                            <p style="font-size: 0.8em; color: #555;">Esto eliminará de la vista los artículos leídos que hayan superado la duración de caché seleccionada (24 o 48 horas).</p>
                        </div>
                        <div class="form-group">
                            <label for="show_read_articles">Artículos leídos</label>
                            <select id="show_read_articles" name="show_read_articles" class="form-group input">
                                <?php
                                    $show_read_options = ['true' => 'Mostrar en gris hasta que se borren de caché', 'false' => 'Ocultar en cuanto se marcan como leídos'];
                                    $selected_show_read = isset($xml_data->settings->show_read_articles) ? (string)$xml_data->settings->show_read_articles : 'true';
                                    foreach($show_read_options as $value => $label) {
                                        echo '<option value="' . $value . '"' . ($selected_show_read === $value ? ' selected' : '') . '>' . $label . '</option>';
                                    }
                                ?>
                            </select>
                        </div>
                        <button type="submit" name="save_settings" class="btn">Guardar Configuración</button>
                    </form>
                <?php else: ?>
                    <h2>Gestionar Fuentes</h2>
                    <?php if ($feed_error): ?><p class="error"><?php echo $feed_error; ?></p><?php endif; ?>
                    <?php if(isset($xml_data->feeds)) { foreach ($xml_data->feeds->folder as $folder): ?>
                        <h4><?php echo htmlspecialchars($folder['name']); ?></h4>
                        <ul class="feed-manage-list">
                            <?php foreach ($folder->feed as $feed): ?>
                                <li>
                                    <div class="feed-info">
                                        <img src="<?php echo htmlspecialchars($feed['favicon']); ?>" alt="">
                                        <span><?php echo htmlspecialchars($feed['name']); ?><br><small><?php echo htmlspecialchars($feed['url']); ?></small></span>
                                    </div>
                                    <div class="feed-actions">
                                        <form method="POST" action="nisaba.php" class="edit-form">
                                            <input type="hidden" name="original_url" value="<?php echo htmlspecialchars($feed['url']); ?>">
                                            <input type="text" name="feed_name" value="<?php echo htmlspecialchars($feed['name']); ?>" required>
                                            <input type="text" name="folder_name" value="<?php echo htmlspecialchars($folder['name']); ?>" required>
                                            <input type="text" name="feed_lang" value="<?php echo htmlspecialchars($feed['lang']); ?>" placeholder="ej: es, en, fr">
                                            <button type="submit" name="edit_feed" class="btn">Guardar</button>
                                            <button type="button" onclick="toggleEditForm(this)" class="btn btn-danger">Cancelar</button>
                                        </form>
                                        <button onclick="toggleEditForm(this)" class="btn">Editar</button>
                                        <form method="POST" action="nisaba.php" onsubmit="return confirm('¿Seguro que quieres eliminar este feed?');">
                                            <input type="hidden" name="feed_url" value="<?php echo htmlspecialchars($feed['url']); ?>">
                                            <button type="submit" name="delete_feed" class="btn btn-danger">Eliminar</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; } ?>
                    <hr style="margin: 2em 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em;">
                        <h3>Añadir/Importar Fuentes</h3>
                        <a href="?action=export_opml">Exportar a OPML</a>
                    </div>
                    <div style="display: flex; gap: 2em; align-items: flex-start; border: 1px solid var(--border-color); padding: 1.5em; border-radius: 8px;">
                        <div style="flex: 1;">
                            <form method="POST" action="nisaba.php">
                                <h4>Añadir Nueva Fuente</h4>
                                <div class="form-group">
                                    <label for="feed_url">URL del Feed RSS</label>
                                    <input type="url" id="feed_url" name="feed_url" required>
                                </div>
                                <div class="form-group">
                                    <label for="folder_name">Carpeta (opcional)</label>
                                    <input type="text" id="folder_name" name="folder_name" placeholder="General">
                                </div>
                                <button type="submit" name="add_feed" class="btn">Añadir Fuente</button>
                            </form>
                        </div>
                        <div style="flex: 1; border-left: 1px solid var(--border-color); padding-left: 2em;">
                            <form method="POST" action="nisaba.php" enctype="multipart/form-data">
                                <h4>Importar desde OPML</h4>
                                <div class="form-group">
                                    <label for="opml_file">Archivo OPML</label>
                                    <input type="file" id="opml_file" name="opml_file" accept=".opml, .xml" required>
                                </div>
                                <button type="submit" name="import_opml" class="btn">Importar</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
        <script>
        function markAsRead(button, guid) {
            fetch('?action=mark_read&guid=' + guid + '&ajax=1')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const articleItem = button.closest('.article-item');
                        if (articleItem) {
                            articleItem.classList.add('read');
                        }
                        button.parentElement.remove();
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        function copySummary(button) {
            const container = button.closest('.summary-container');
            const summaryText = container.querySelector('.summary-content').innerText;
            navigator.clipboard.writeText(summaryText).then(() => {
                const originalText = button.innerText;
                button.innerText = '¡Copiado!';
                setTimeout(() => {
                    button.innerText = originalText;
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        }

        function toggleNoteEdit(button) {
            const note = button.closest('.note');
            const displayElements = note.querySelectorAll('.note-display');
            const editForm = note.querySelector('.note-edit-form');

            if (editForm.style.display === 'none' || editForm.style.display === '') {
                editForm.style.display = 'block';
                displayElements.forEach(el => el.style.display = 'none');
            } else {
                editForm.style.display = 'none';
                displayElements.forEach(el => el.style.display = 'block');
            }
        }

        function markAllRead() {
            const unreadItems = document.querySelectorAll('.article-item:not(.read)');
            if (unreadItems.length === 0) return;

            const guids = [];
            unreadItems.forEach(item => {
                guids.push(item.dataset.guid);
            });

            const formData = new FormData();
            guids.forEach(guid => {
                formData.append('guids[]', guid);
            });

            fetch('?action=mark_all_read', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        unreadItems.forEach(item => {
                            item.classList.add('read');
                            const markReadButton = item.querySelector('.mark-as-read-btn');
                            if (markReadButton) {
                                 markReadButton.parentElement.remove();
                            }
                        });
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        function toggleEditForm(button) {
            const li = button.closest('li');
            const editForm = li.querySelector('.edit-form');
            const allActionButtons = li.querySelectorAll('.feed-actions > button, .feed-actions > form:not(.edit-form)');
            if (editForm.style.display === 'none' || editForm.style.display === '') {
                editForm.style.display = 'flex';
                allActionButtons.forEach(el => el.style.display = 'none');
            } else {
                editForm.style.display = 'none';
                allActionButtons.forEach(el => el.style.display = 'flex');
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('increase-font')) {
                const body = document.querySelector('.content');
                const increaseButton = document.getElementById('increase-font');
                const decreaseButton = document.getElementById('decrease-font');
                
                let currentSize = localStorage.getItem('fontSize');
                if (!currentSize) {
                    currentSize = 1.2; // Default size
                } else {
                    currentSize = parseFloat(currentSize);
                }
                body.style.fontSize = currentSize + 'em';

                increaseButton.addEventListener('click', function() {
                    currentSize = parseFloat(body.style.fontSize) + 0.1;
                    body.style.fontSize = currentSize + 'em';
                    localStorage.setItem('fontSize', currentSize);
                });

                decreaseButton.addEventListener('click', function() {
                    currentSize = parseFloat(body.style.fontSize) - 0.1;
                    body.style.fontSize = currentSize + 'em';
                    localStorage.setItem('fontSize', currentSize);
                });
            }
        });
        </script>
    <?php else: ?>
        <div class="auth-container">
            <div class="logo"><img src="nisaba.png" alt="Logo Nisaba"></div>
            <?php if ($error): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>
            <?php
                $user_files = glob(DATA_DIR . '/*.xml');
                if (empty($user_files)): 
            ?>
                <form method="POST" action="nisaba.php"><h3>Crear cuenta de administrador</h3><div class="form-group"><label for="reg-username">Usuario</label><input type="text" id="reg-username" name="username" required></div><div class="form-group"><label for="reg-password">Contraseña</label><input type="password" id="reg-password" name="password" required></div><button type="submit" name="register" class="btn">Registrar</button></form>
            <?php else: ?>
                <form method="POST" action="nisaba.php"><h3>Iniciar Sesión</h3><div class="form-group"><label for="login-username">Usuario</label><input type="text" id="login-username" name="username" required></div><div class="form-group"><label for="login-password">Contraseña</label><input type="password" id="login-password" name="password" required></div><button type="submit" name="login" class="btn">Entrar</button></form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <footer>
        <img src="maximalista.png" alt="Logo Maximalista" style="height: 1.5em; margin-bottom: 0.5em;">
        <br>
        Nisaba es software libre bajo licencia <a href="https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12" target="_blank">EUPL v1.2</a>
        <br>
        Creado por <a href="https://maximalista.coop" target="_blank">Compañía Maximalista S.Coop.</a>
    </footer>
</body>
</html>