<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
header('Content-Type: text/html; charset=UTF-8');

// --- 1. CONFIGURACIÓN Y FUNCIONES AUXILIARES ---

define('DATA_DIR', __DIR__ . '/data');
define('FAVICON_DIR', __DIR__ . '/data/favicons');
define('PROMPT_FILE', __DIR__ . '/prompt.txt');
define('DEFAULT_CACHE_DURATION_HOURS', 1440); // 2 meses ≈ 60 días

require_once __DIR__ . '/feed_parser.php';

$error = ''; $feed_error = ''; $feed_success = ''; $settings_success = ''; $cacheFile = null;

if (isset($_SESSION['feed_error'])) {
    $feed_error = $_SESSION['feed_error'];
    unset($_SESSION['feed_error']);
}

if (isset($_SESSION['feed_success'])) {
    $feed_success = $_SESSION['feed_success'];
    unset($_SESSION['feed_success']);
}

function get_favicon($url) {
    $default_favicon = 'nisaba.png';
    $url_parts = parse_url($url);
    if (!$url_parts || !isset($url_parts['host'])) return $default_favicon;
    $domain = $url_parts['scheme'] . '://' . $url_parts['host'];
    $favicon_path = '';
    $context = stream_context_create(['http' => ['user_agent' => 'Nisaba Feed Reader', 'timeout' => 5]]);
    $html = file_get_contents($domain, false, $context);
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

function nisaba_get_base_url() {
    if (!isset($_SERVER['HTTP_HOST'])) {
        return '';
    }
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $is_https ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $script_dir = rtrim(str_replace('\\', '/', dirname($script_name)), '/');
    if ($script_dir === '.' || $script_dir === '/') {
        $script_dir = '';
    }
    return rtrim($scheme . $host . $script_dir, '/');
}

function nisaba_public_url(string $path): string {
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $base = nisaba_get_base_url();
    if ($base === '') {
        return $path;
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function nisaba_resolve_url(string $base_url, string $relative_path): string {
    if ($relative_path === '' || preg_match('#^https?://#i', $relative_path)) {
        return $relative_path;
    }
    if (strpos($relative_path, '//') === 0) {
        $scheme = parse_url($base_url, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $relative_path;
    }
    $parts = parse_url($base_url);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) {
        return $relative_path;
    }
    $scheme = $parts['scheme'];
    $host = $parts['host'];
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $base_path = $parts['path'] ?? '';

    if (isset($relative_path[0]) && $relative_path[0] === '/') {
        $path = $relative_path;
    } else {
        $base_directory = $base_path;
        if ($base_directory === '' || substr($base_directory, -1) !== '/') {
            $base_directory = substr($base_directory, 0, strrpos($base_directory, '/') !== false ? strrpos($base_directory, '/') + 1 : 0);
        }
        $path = rtrim($base_directory, '/') . '/' . $relative_path;
    }

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
        } else {
            $segments[] = $segment;
        }
    }
    $normalized_path = '/' . implode('/', $segments);
    return $scheme . '://' . $host . $port . $normalized_path;
}

function generate_notes_rss($xml_data, $username) {
    $rss = new SimpleXMLElement('<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"></rss>');
    $channel = $rss->addChild('channel');

    $display_name = '';
    if (isset($xml_data->settings->display_name)) {
        $display_name = trim((string)$xml_data->settings->display_name);
    }

    $channel_title = $display_name !== '' ? 'Notas de ' . $display_name : 'Notas de Nisaba para ' . $username;
    $channel->addChild('title', $channel_title);

    $feed_link = nisaba_public_url('notas.xml');
    if ($feed_link === '') {
        $feed_link = 'notas.xml';
    }
    $channel->addChild('link', $feed_link);
    $channel->addChild('description', 'Un feed de las notas personales guardadas en Nisaba.');
    $channel->addChild('language', 'es-es');
    $channel->addChild('generator', 'Nisaba');
    $channel->addChild('owner_username', $username);
    if ($display_name !== '') {
        $channel->addChild('owner_name', $display_name);
        $channel->addChild('managingEditor', $display_name);
    }

    $user_favicon_path = isset($xml_data->settings->user_favicon) ? trim((string)$xml_data->settings->user_favicon) : '';
    $user_favicon_url = $user_favicon_path !== '' ? nisaba_public_url($user_favicon_path) : '';
    if ($user_favicon_url !== '') {
        $image = $channel->addChild('image');
        $image->addChild('url', $user_favicon_url);
        $image->addChild('title', $channel_title);
        $image->addChild('link', $feed_link);
        $channel->addChild('owner_favicon', $user_favicon_url);
    }

    if (isset($xml_data->notes)) {
        $notes_nodes = $xml_data->notes->note;
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
    if (empty($api_key) || empty($content)) return "No hay nada que analizar o la API key de Gemini no está configurada.";
    if (empty($prompt_template)) $prompt_template = "ROL Y OBJETIVO\nActúa como un analista de prospectiva estratégica y horizon scanning. Tu misión es analizar bloques de noticias y artículos de opinión provenientes de una misma región o ámbito para identificar \"señales débiles\" (weak signals). Estas señales son eventos, ideas o tendencias sutiles, emergentes o inesperadas que podrían anticipar o catalizar disrupciones significativas a nivel político, económico, tecnológico, social, cultural o medioambiental.\n\nTu objetivo principal es sintetizar y destacar únicamente las piezas que apunten a una potencial ruptura de tendencia, un cambio de paradigma naciente o una tensión estratégica emergente, ignorando por completo la información rutinaria, predecible o de seguimiento.\n\nCONTEXTO Y VECTORES DE DISRUPCIÓN\nEvaluarás cada noticia dentro del bloque en función de su potencial para señalar un cambio en los siguientes vectores de disrupción:\n\n1. Geopolítica y Política:\n\nReconfiguración de Alianzas: Acuerdos o tensiones inesperadas entre países, cambios en bloques de poder.\n\nNuevas Regulaciones Estratégicas: Leyes que alteran radicalmente un sector clave (energía, tecnología, finanzas).\n\nInestabilidad o Movimientos Sociales: Protestas con nuevas formas de organización, surgimiento de movimientos políticos disruptivos, crisis institucionales.\n\nCambios en Doctrina Militar o de Seguridad: Nuevas estrategias de defensa, ciberseguridad o control de fronteras con implicaciones amplias.\n\n2. Economía y Mercado:\n\nNuevos Modelos de Negocio: Empresas que ganan tracción con una lógica de mercado radicalmente diferente.\n\nFragilidades en Cadenas de Suministro: Crisis en nodos logísticos, escasez de materiales críticos que fuerzan una reorganización industrial.\n\nAnomalías Financieras: Inversiones de capital riesgo en sectores o geografías \"olvidadas\", comportamientos extraños en los mercados, surgimiento de activos no tradicionales.\n\nConflictos Laborales Paradigmáticos: Huelgas, negociaciones o movimientos sindicales que apuntan a un cambio en la relación capital-trabajo.\n\n3. Tecnología y Ciencia:\n\nAvances Fundamentales: Descubrimientos científicos o tecnológicos (no incrementales) que abren campos completamente nuevos (ej. computación cuántica, biotecnología, nuevos materiales).\n\nAdopción Inesperada de Tecnología: Una tecnología nicho que empieza a ser adoptada masivamente en un sector imprevisto.\n\nVulnerabilidades Sistémicas: Descubrimiento de fallos de seguridad o éticos en tecnologías de uso generalizado.\n\nDemocratización del Acceso: Tecnologías avanzadas (IA, biohacking, etc.) que se vuelven accesibles y de código abierto, permitiendo usos no controlados.\n\n4. Sociedad y Cultura:\n\nCambios en Valores o Comportamientos: Datos que indican un cambio rápido en la opinión pública sobre temas fundamentales (familia, trabajo, privacidad), nuevos patrones de consumo.\n\nSurgimiento de Subculturas Influyentes: Movimientos contraculturales o nichos que empiezan a permear en la cultura mayoritaria.\n\nTensiones Demográficas o Migratorias: Cambios en flujos migratorios, envejecimiento poblacional o tasas de natalidad que generan nuevas presiones sociales.\n\nNarrativas y Debates Emergentes: Ideas o debates marginales que ganan repentinamente visibilidad mediática o académica.\n\n5. Medio Ambiente y Energía:\n\nEventos Climáticos Extremos con Impacto Sistémico: Desastres naturales que revelan fragilidades críticas en la infraestructura o la economía.\n\nInnovación en Energía o Recursos: Avances en fuentes de energía, almacenamiento o reciclaje que podrían alterar el paradigma energético.\n\nEscasez Crítica de Recursos: Agotamiento o conflicto por recursos básicos (agua, minerales raros) que escala a nivel político o económico.\n\nActivismo y Litigios Climáticos: Acciones legales o movimientos de activismo que logran un impacto significativo en la política corporativa o gubernamental.\n\nPROCESO DE RAZONAMIENTO (Paso a Paso)\nAl recibir un bloque de noticias, sigue internamente este proceso:\n\nVisión de Conjunto: Lee rápidamente los titulares del bloque para entender el contexto general ({{contexto_del_bloque}}).\n\nAnálisis Individual: Para cada noticia del bloque, evalúa:\n\nClasificación: ¿Se alinea con alguno de los vectores de disrupción listados?\n\nEvaluación de Señal: ¿Es un evento predecible y esperado (ruido) o es una señal genuina de cambio? Mide su nivel de \"sorpresa\", \"anomalía\" o \"potencial de segundo orden\".\n\nFiltrado: Descarta mentalmente todas las noticias que sean ruido o información incremental.\n\nSíntesis y Agrupación: De las noticias filtradas, agrúpalas si apuntan a una misma macrotendencia. Formula una síntesis global que conecte los puntos.\n\nGeneración de la Salida: Construye el informe final siguiendo el formato estricto.\n\nDATOS DE ENTRADA\nContexto del Bloque: {{contexto_del_bloque}} (Ej: \"Noticias de España\", \"Artículos de opinión de medios europeos\", \"Actualidad tecnológica de China\")\n\nBloque de Noticias: {{bloque_de_noticias}} (Una lista o conjunto de artículos, cada uno con título, descripción y enlace)\n\nFORMATO DE SALIDA Y REGLAS\nExisten dos posibles salidas: un informe de disrupción o una notificación de ausencia de señales.\n\n1. Si identificas al menos una señal relevante, genera un informe con ESTE formato EXACTO:\n\nAnálisis General del Bloque\nSíntesis ejecutiva (máximo 4 frases) que resume las principales corrientes de cambio o tensiones detectadas en el bloque de noticias. Conecta las señales si es posible.\n\nSeñales Débiles y Disrupciones Identificadas\n\nTítulo conciso del primer hallazgo en español\n{{enlace_de_la_noticia}}\n\nSíntesis de Impacto: Una o dos frases que capturan por qué esta noticia es estratégicamente relevante, no un simple resumen.\n\nExplicación de la Señal: Explicación concisa (máximo 5 frases) que justifica la elección, conectando la noticia con uno o más vectores de disrupción y explorando sus posibles implicaciones de segundo o tercer orden.\n\nTítulo conciso del segundo hallazgo en español\n{{enlace_de_la_noticia}}\n\nSíntesis de Impacto: ...\n\nExplicación de la Señal: ...\n\n(Repetir para cada señal identificada)\n\nReglas estrictas para el informe:\n\nJerarquía: El análisis general siempre va primero y debe ofrecer una visión conectada.\n\nEnfoque en la Implicación: Tanto la síntesis como la explicación deben centrarse en el \"y qué\" (so what?), no en el \"qué\" (what).\n\nSin Adornos: No añadas emojis, comillas innecesarias, etiquetas extra, ni texto introductorio o de cierre.\n\n2. Si el bloque de noticias NO contiene ninguna señal de disrupción genuina, responde únicamente con:\n\nNo se han detectado señales de disrupción significativas en este bloque de noticias.";
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
        return "No se pudo generar un análisis. Respuesta de la API: " . $result;
    }

    return $response['candidates'][0]['content']['parts'][0]['text'];
}

function truncate_text($text, $char_limit) {
    if (!is_string($text)) {
        return '';
    }
    $trimmed_text = trim($text);
    if (mb_strlen($trimmed_text, 'UTF-8') <= $char_limit) {
        return $trimmed_text;
    }
    return mb_strimwidth($trimmed_text, 0, $char_limit, '...', 'UTF-8');
}

function normalize_feed_text($text) {
    if ($text === null) return '';
    if ($text instanceof SimpleXMLElement) {
        $text = (string)$text;
    }
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function sanitize_cache_duration($value) {
    $allowed = ['0', '720', (string)DEFAULT_CACHE_DURATION_HOURS, '4320'];
    $value = (string)$value;
    if (!in_array($value, $allowed, true)) {
        return (string)DEFAULT_CACHE_DURATION_HOURS;
    }
    return $value;
}

function load_prompt_template(): string {
    static $cached_prompt = null;
    if ($cached_prompt !== null) return $cached_prompt;

    if (file_exists(PROMPT_FILE)) {
        $contents = trim(file_get_contents(PROMPT_FILE));
        if ($contents !== '') {
            $cached_prompt = $contents;
            return $cached_prompt;
        }
    }

    $fallback = "ROL Y OBJETIVO\nActúa como un analista de prospectiva estratégica y horizon scanning. Tu misión es analizar bloques de noticias y artículos de opinión provenientes de una misma región o ámbito para identificar \"señales débiles\" (weak signals). Estas señales son eventos, ideas o tendencias sutiles, emergentes o inesperadas que podrían anticipar o catalizar disrupciones significativas a nivel político, económico, tecnológico, social, cultural o medioambiental.\n\nTu objetivo principal es sintetizar y destacar únicamente las piezas que apunten a una potencial ruptura de tendencia, un cambio de paradigma naciente o una tensión estratégica emergente, ignorando por completo la información rutinaria, predecible o de seguimiento.\n\nCONTEXTO Y VECTORES DE DISRUPCIÓN\nEvaluarás cada noticia dentro del bloque en función de su potencial para señalar un cambio en los siguientes vectores de disrupción:\n\n1. Geopolítica y Política:\n\nReconfiguración de Alianzas: Acuerdos o tensiones inesperadas entre países, cambios en bloques de poder.\n\nNuevas Regulaciones Estratégicas: Leyes que alteran radicalmente un sector clave (energía, tecnología, finanzas).\n\nInestabilidad o Movimientos Sociales: Protestas con nuevas formas de organización, surgimiento de movimientos políticos disruptivos, crisis institucionales.\n\nCambios en Doctrina Militar o de Seguridad: Nuevas estrategias de defensa, ciberseguridad o control de fronteras con implicaciones amplias.\n\n2. Economía y Mercado:\n\nNuevos Modelos de Negocio: Empresas que ganan tracción con una lógica de mercado radicalmente diferente.\n\nFragilidades en Cadenas de Suministro: Crisis en nodos logísticos, escasez de materiales críticos que fuerzan una reorganización industrial.\n\nAnomalías Financieras: Inversiones de capital riesgo en sectores o geografías \"olvidadas\", comportamientos extraños en los mercados, surgimiento de activos no tradicionales.\n\nConflictos Laborales Paradigmáticos: Huelgas, negociaciones o movimientos sindicales que apuntan a un cambio en la relación capital-trabajo.\n\n3. Tecnología y Ciencia:\n\nAvances Fundamentales: Descubrimientos científicos o tecnológicos (no incrementales) que abren campos completamente nuevos (ej. computación cuántica, biotecnología, nuevos materiales).\n\nAdopción Inesperada de Tecnología: Una tecnología nicho que empieza a ser adoptada masivamente en un sector imprevisto.\n\nVulnerabilidades Sistémicas: Descubrimiento de fallos de seguridad o éticos en tecnologías de uso generalizado.\n\nDemocratización del Acceso: Tecnologías avanzadas (IA, biohacking, etc.) que se vuelven accesibles y de código abierto, permitiendo usos no controlados.\n\n4. Sociedad y Cultura:\n\nCambios en Valores o Comportamientos: Datos que indican un cambio rápido en la opinión pública sobre temas fundamentales (familia, trabajo, privacidad), nuevos patrones de consumo.\n\nSurgimiento de Subculturas Influyentes: Movimientos contraculturales o nichos que empiezan a permear en la cultura mayoritaria.\n\nTensiones Demográficas o Migratorias: Cambios en flujos migratorios, envejecimiento poblacional o tasas de natalidad que generan nuevas presiones sociales.\n\nNarrativas y Debates Emergentes: Ideas o debates marginales que ganan repentinamente visibilidad mediática o académica.\n\n5. Medio Ambiente y Energía:\n\nEventos Climáticos Extremos con Impacto Sistémico: Desastres naturales que revelan fragilidades críticas en la infraestructura o la economía.\n\nInnovación en Energía o Recursos: Avances en fuentes de energía, almacenamiento o reciclaje que podrían alterar el paradigma energético.\n\nEscasez Crítica de Recursos: Agotamiento o conflicto por recursos básicos (agua, minerales raros) que escala a nivel político o económico.\n\nActivismo y Litigios Climáticos: Acciones legales o movimientos de activismo que logran un impacto significativo en la política corporativa o gubernamental.\n\nPROCESO DE RAZONAMIENTO (Paso a Paso)\nAl recibir un bloque de noticias, sigue internamente este proceso:\n\nVisión de Conjunto: Lee rápidamente los titulares del bloque para entender el contexto general ({{contexto_del_bloque}}).\n\nAnálisis Individual: Para cada noticia del bloque, evalúa:\n\nClasificación: ¿Se alinea con alguno de los vectores de disrupción listados?\n\nEvaluación de Señal: ¿Es un evento predecible y esperado (ruido) o es una señal genuina de cambio? Mide su nivel de \"sorpresa\", \"anomalía\" o \"potencial de segundo orden\".\n\nFiltrado: Descarta mentalmente todas las noticias que sean ruido o información incremental.\n\nSíntesis y Agrupación: De las noticias filtradas, agrúpalas si apuntan a una misma macrotendencia. Formula una síntesis global que conecte los puntos.\n\nGeneración de la Salida: Construye el informe final siguiendo el formato estricto.\n\nDATOS DE ENTRADA\nContexto del Bloque: {{contexto_del_bloque}} (Ej: \"Noticias de España\", \"Artículos de opinión de medios europeos\", \"Actualidad tecnológica de China\")\n\nBloque de Noticias: {{bloque_de_noticias}} (Una lista o conjunto de artículos, cada uno con título, descripción y enlace)\n\nFORMATO DE SALIDA Y REGLAS\nExisten dos posibles salidas: un informe de disrupción o una notificación de ausencia de señales.\n\n1. Si identificas al menos una señal relevante, genera un informe con ESTE formato EXACTO:\n\nAnálisis General del Bloque\nSíntesis ejecutiva (máximo 4 frases) que resume las principales corrientes de cambio o tensiones detectadas en el bloque de noticias. Conecta las señales si es posible.\n\nSeñales Débiles y Disrupciones Identificadas\n\nTítulo conciso del primer hallazgo en español\n{{enlace_de_la_noticia}}\n\nSíntesis de Impacto: Una o dos frases que capturan por qué esta noticia es estratégicamente relevante, no un simple resumen.\n\nExplicación de la Señal: Explicación concisa (máximo 5 frases) que justifica la elección, conectando la noticia con uno o más vectores de disrupción y explorando sus posibles implicaciones de segundo o tercer orden.\n\nTítulo conciso del segundo hallazgo en español\n{{enlace_de_la_noticia}}\n\nSíntesis de Impacto: ...\n\nExplicación de la Señal: ...\n\n(Repetir para cada señal identificada)\n\nReglas estrictas para el informe:\n\nJerarquía: El análisis general siempre va primero y debe ofrecer una visión conectada.\n\nEnfoque en la Implicación: Tanto la síntesis como la explicación deben centrarse en el \"y qué\" (so what?), no en el \"qué\" (what).\n\nSin Adornos: No añadas emojis, comillas innecesarias, etiquetas extra, ni texto introductorio o de cierre.\n\n2. Si el bloque de noticias NO contiene ninguna señal de disrupción genuina, responde únicamente con:\n\nNo se han detectado señales de disrupción significativas en este bloque de noticias.";

    $cached_prompt = $fallback;
    return $cached_prompt;
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
    $username = $_SESSION['username'];

    libxml_use_internal_errors(true);
    $userFile = DATA_DIR . '/' . $username . '.xml';
    $xml_data = simplexml_load_file($userFile);
    if ($xml_data === false) {
        $error_messages = [];
        foreach (libxml_get_errors() as $error) {
            $error_messages[] = $error->message;
        }
        libxml_clear_errors();
        die('ERROR CRÍTICO: No se pudo cargar el archivo de datos del usuario. Detalles: ' . implode('; ', $error_messages) . ' Por favor, comprueba el archivo en la ruta: ' . htmlspecialchars($userFile));
    }

    $cacheFile = DATA_DIR . '/' . $username . '_cache.xml';

    $google_api_key = isset($xml_data->settings->google_translate_api_key) ? (string)$xml_data->settings->google_translate_api_key : '';
    $gemini_api_key = isset($xml_data->settings->gemini_api_key) ? (string)$xml_data->settings->gemini_api_key : '';
    $gemini_model = isset($xml_data->settings->gemini_model) ? (string)$xml_data->settings->gemini_model : 'gemini-1.5-pro-latest';
    $gemini_prompt = load_prompt_template();
    $cache_duration = sanitize_cache_duration(isset($xml_data->settings->cache_duration) ? (string)$xml_data->settings->cache_duration : (string)DEFAULT_CACHE_DURATION_HOURS);
    $show_read_articles = isset($xml_data->settings->show_read_articles) ? (string)$xml_data->settings->show_read_articles : 'true';
    $archive_integration_enabled = isset($xml_data->settings->archive_integration) && (string)$xml_data->settings->archive_integration === 'true';


    // --- 3.2. MANEJO DE ACCIONES (GET y POST) ---

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'mark_all_read') {
        // Defensively reload user data to ensure settings are fresh
        $fresh_xml_data = simplexml_load_file($userFile);
        $fresh_cache_duration = sanitize_cache_duration(isset($fresh_xml_data->settings->cache_duration) ? (string)$fresh_xml_data->settings->cache_duration : (string)DEFAULT_CACHE_DURATION_HOURS);

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

    if (isset($_GET['action']) && $_GET['action'] === 'mark_read' && isset($_GET['guid'])) {
        // Defensively reload user data to ensure settings are fresh
        $fresh_xml_data = simplexml_load_file($userFile);
        $fresh_cache_duration = sanitize_cache_duration(isset($fresh_xml_data->settings->cache_duration) ? (string)$fresh_xml_data->settings->cache_duration : (string)DEFAULT_CACHE_DURATION_HOURS);

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
                // --- Custom Error Handling for this action ---
        $nisaba_errors = [];
        function nisaba_error_handler($severity, $message, $file, $line) {
            global $nisaba_errors;
            // Convert severity number to a readable string
            $error_type = match ($severity) {
                E_WARNING => 'Warning',
                E_NOTICE => 'Notice',
                E_USER_ERROR => 'User Error',
                E_USER_WARNING => 'User Warning',
                E_USER_NOTICE => 'User Notice',
                E_STRICT => 'Strict',
                E_RECOVERABLE_ERROR => 'Recoverable Error',
                E_DEPRECATED => 'Deprecated',
                E_USER_DEPRECATED => 'User Deprecated',
                default => 'Unknown Error',
            };
            $nisaba_errors[] = "[$error_type] $message in $file on line $line";
        }
        set_error_handler('nisaba_error_handler');
        // --------------------------------------------

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
        $context = stream_context_create(['http' => ['user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36', 'timeout' => 10]]);
        
        foreach ($xml_data->xpath('//feed') as $feed) {
            $feed_url = (string)$feed['url'];

            $feed_content = fetch_feed_content($feed_url);
            if (!$feed_content) {
                $nisaba_errors[] = 'Fetch failed for feed: ' . $feed_url;
                continue;
            }

            $source_xml = normalize_feed_content($feed_content, $feed_url, $nisaba_errors);
            if (!$source_xml) {
                continue;
            }

            $parsed_items = parse_feed_items($source_xml, $feed_url, $skip_guids);

            foreach ($parsed_items as $parsed_item) {

                $article = $cache_xml->addChild('item');
                $title = html_entity_decode($parsed_item['title'], ENT_QUOTES | ENT_XML1, 'UTF-8');
                $content = html_entity_decode($parsed_item['content'], ENT_QUOTES | ENT_XML1, 'UTF-8');

                $article->addChild('feed_url', $parsed_item['feed_url']);
                addChildWithCDATA($article, 'title_original', $title);
                addChildWithCDATA($article, 'content_original', $content);
                addChildWithCDATA($article, 'title_translated', $title);
                addChildWithCDATA($article, 'content_translated', $content);
                $article->addChild('pubDate', $parsed_item['pubDate']);
                $article->addChild('guid', $parsed_item['guid']);
                $article->addChild('link', $parsed_item['link']);
                $article->addChild('read', '0');
                $article->addChild('image', $parsed_item['image']);
            }
        }
        
        // 3b. Fetch external notes sources
        if (isset($xml_data->received_notes_cache)) {
            $existing_received_dom = dom_import_simplexml($xml_data->received_notes_cache);
            if ($existing_received_dom) {
                $existing_received_dom->parentNode->removeChild($existing_received_dom);
            }
        }
        $received_notes_cache_node = $xml_data->addChild('received_notes_cache');
        if (isset($xml_data->external_notes_sources)) {
            foreach ($xml_data->external_notes_sources->source as $external_source) {
                $source_name = isset($external_source->name) ? (string)$external_source->name : 'Nisaba';
                $source_url = isset($external_source->url) ? rtrim((string)$external_source->url, '/') : '';
                $source_favicon = isset($external_source->favicon) ? (string)$external_source->favicon : '';
                if (empty($source_url)) continue;

                $notes_feed_url = $source_url . '/notas.xml';
                $notes_feed_content = fetch_feed_content($notes_feed_url);
                if (!$notes_feed_content) {
                    $nisaba_errors[] = 'Fetch failed for external notes feed: ' . $notes_feed_url;
                    continue;
                }

                libxml_use_internal_errors(true);
                $notes_xml = simplexml_load_string($notes_feed_content);
                if ($notes_xml === false) {
                    $nisaba_errors[] = 'XML Parsing failed for external notes feed: ' . $notes_feed_url;
                    libxml_clear_errors();
                    continue;
                }
                libxml_clear_errors();

                $note_items = [];
                if (isset($notes_xml->channel->item)) {
                    $note_items = $notes_xml->channel->item;
                } elseif (isset($notes_xml->item)) {
                    $note_items = $notes_xml->item;
                } elseif (isset($notes_xml->entry)) {
                    $note_items = $notes_xml->entry;
                }

                foreach ($note_items as $note_item) {
                    $is_atom_note = stripos($note_item->getName(), 'entry') !== false;
                    $title = trim((string)$note_item->title);
                    $link = '';
                    if ($is_atom_note) {
                        foreach ($note_item->link as $link_candidate) {
                            $rel = isset($link_candidate['rel']) ? (string)$link_candidate['rel'] : '';
                            if ($rel === 'alternate' || $rel === '') {
                                $link = (string)$link_candidate['href'];
                                break;
                            }
                        }
                        if (empty($link) && isset($note_item->link['href'])) {
                            $link = (string)$note_item->link['href'];
                        }
                    } else {
                        $link = (string)$note_item->link;
                    }
                    if ($title === '' && $link !== '') {
                        $title = $link;
                    }
                    if ($title === '') {
                        $title = 'Nota recibida';
                    }

                    $content = '';
                    if ($is_atom_note) {
                        $content = (string)$note_item->content;
                        if ($content === '' && isset($note_item->summary)) {
                            $content = (string)$note_item->summary;
                        }
                    } else {
                        $content_ns = $note_item->children('content', true);
                        if ($content_ns instanceof SimpleXMLElement && trim((string)$content_ns->encoded) !== '') {
                            $content = (string)$content_ns->encoded;
                        } elseif (isset($note_item->content)) {
                            $content = (string)$note_item->content;
                        } else {
                            $content = (string)$note_item->description;
                        }
                    }

                    $note_date = '';
                    if ($is_atom_note) {
                        if (isset($note_item->updated)) {
                            $note_date = (string)$note_item->updated;
                        }
                        if ($note_date === '' && isset($note_item->published)) {
                            $note_date = (string)$note_item->published;
                        }
                    } else {
                        if (isset($note_item->pubDate)) {
                            $note_date = (string)$note_item->pubDate;
                        } elseif (isset($note_item->date)) {
                            $note_date = (string)$note_item->date;
                        }
                    }

                    $received_note = $received_notes_cache_node->addChild('note');
                    $received_note->addChild('source_name', $source_name);
                    $received_note->addChild('source_url', $source_url);
                    if (!empty($source_favicon)) {
                        $received_note->addChild('favicon', $source_favicon);
                    }
                    $received_note->addChild('title', $title);
                    $received_note->addChild('link', $link);
                    addChildWithCDATA($received_note, 'content', $content);
                    $received_note->addChild('date', $note_date);
                }
            }
        }

        // 4. Save the modified cache object once
        $cache_xml->asXML($cacheFile);

        // 5. Set a new update ID to invalidate old summaries
        if (!isset($xml_data->settings)) $xml_data->addChild('settings');
        $xml_data->settings->last_update_id = uniqid('update_');
        $xml_data->asXML($userFile);

        // --- Restore Error Handler ---
        restore_error_handler();
        // -----------------------------
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success']);
        } else {
            $return_url = $_GET['return_url'] ?? 'nisaba.php?view=all_feeds';
            header('Location: ' . $return_url);
        }
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
            $_SESSION['notes_feedback'] = ['type' => 'success', 'message' => 'Nota guardada con éxito. Puedes ver todas en la sección «Notas».'];
            header('Location: ' . ($_POST['return_url'] ?? 'nisaba.php?article_guid=' . urlencode($guid)));
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

            if (isset($_POST['display_name'])) {
                $xml_data->settings->display_name = trim($_POST['display_name']);
            }

            if (isset($_FILES['user_favicon']) && $_FILES['user_favicon']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['user_favicon'];
                $allowed_types = ['image/png', 'image/jpeg', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml', 'image/webp'];
                if (in_array($file['type'], $allowed_types) && $file['size'] < 1048576) { // 1MB limit
                    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    if (empty($extension)) $extension = 'png';
                    $filename = 'userfav_' . hash('md5', $username) . '.' . $extension;
                    $save_path = FAVICON_DIR . '/' . $filename;
                    if (move_uploaded_file($file['tmp_name'], $save_path)) {
                        $xml_data->settings->user_favicon = 'data/favicons/' . $filename;
                    }
                } else {
                    $_SESSION['settings_feedback'] = 'ERROR: El favicon no es válido. Comprueba el tipo (PNG, JPG, etc.) y el tamaño (máx 1MB).';
                    header('Location: nisaba.php?view=settings');
                    exit;
                }
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
            if (isset($_POST['cache_duration'])) {
                $xml_data->settings->cache_duration = $_POST['cache_duration'];
            }
            if (isset($_POST['show_read_articles'])) {
                $xml_data->settings->show_read_articles = $_POST['show_read_articles'];
            }
            $xml_data->settings->archive_integration = isset($_POST['archive_integration']) ? 'true' : 'false';

            if ($xml_data->asXML($userFile)) {
                generate_notes_rss($xml_data, $username);
                $_SESSION['settings_feedback'] = 'Configuración guardada correctamente.';
            } else {
                $_SESSION['settings_feedback'] = 'ERROR: No se pudo guardar la configuración en el archivo.';
            }
            
            header('Location: nisaba.php?view=settings');
            exit;
        }

        if (isset($_POST['edit_external_source'])) {
            $original_url = $_POST['original_url'];
            $new_name = trim($_POST['external_name']);

            if (empty($original_url) || empty($new_name)) {
                $_SESSION['feed_error'] = 'Error al editar. El nombre y la URL original son necesarios.';
                header('Location: nisaba.php?view=sources');
                exit;
            }

            $normalized_url = rtrim($original_url, '/');
            $source_to_edit = null;
            if (isset($xml_data->external_notes_sources)) {
                foreach ($xml_data->external_notes_sources->source as $source_node) {
                    if (rtrim((string)$source_node->url, '/') === $normalized_url) {
                        $source_to_edit = $source_node;
                        break;
                    }
                }
            }

            if ($source_to_edit) {
                $source_to_edit->name = $new_name;

                if (isset($_FILES['new_favicon']) && $_FILES['new_favicon']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['new_favicon'];
                    $allowed_types = ['image/png', 'image/jpeg', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml', 'image/webp'];
                    if (in_array($file['type'], $allowed_types) && $file['size'] < 1048576) { // 1MB limit
                        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        if (empty($extension)) $extension = 'png';
                        $filename = 'extfav_edited_' . hash('md5', $normalized_url) . '.' . $extension;
                        $save_path = FAVICON_DIR . '/' . $filename;
                        if (move_uploaded_file($file['tmp_name'], $save_path)) {
                            $favicon_path = 'data/favicons/' . $filename;
                            if (isset($source_to_edit->favicon)) {
                                $source_to_edit->favicon = $favicon_path;
                            } else {
                                $source_to_edit->addChild('favicon', $favicon_path);
                            }
                        }
                    }
                }
                $xml_data->asXML($userFile);
                $_SESSION['feed_success'] = 'Fuente de notas \'' . htmlspecialchars($new_name) . '\' actualizada.';
            } else {
                $_SESSION['feed_error'] = 'No se encontró la fuente de notas a editar.';
            }

            header('Location: nisaba.php?view=sources');
            exit;
        }

        if (isset($_POST['add_external_source'])) {
            $external_url = trim($_POST['external_url'] ?? '');
            if (empty($external_url)) {
                $_SESSION['feed_error'] = 'La URL del Nisaba a seguir no puede estar vacía.';
                header('Location: nisaba.php?view=sources');
                exit;
            }

            if (!preg_match('#^https?://#i', $external_url)) {
                $external_url = 'https://' . ltrim($external_url, '/');
            }

            if (!filter_var($external_url, FILTER_VALIDATE_URL)) {
                $_SESSION['feed_error'] = 'La URL proporcionada no parece válida.';
                header('Location: nisaba.php?view=sources');
                exit;
            }

            $normalized_url = rtrim($external_url, '/');
            $notes_feed_url = $normalized_url . '/notas.xml';

            $feed_content = fetch_feed_content($notes_feed_url);
            if (!$feed_content) {
                $_SESSION['feed_error'] = 'No se pudo acceder a ' . htmlspecialchars($notes_feed_url) . '. Comprueba la URL y que el Nisaba externo sea accesible.';
                header('Location: nisaba.php?view=sources');
                exit;
            }

            libxml_use_internal_errors(true);
            $notes_xml = simplexml_load_string($feed_content);
            if ($notes_xml === false) {
                $_SESSION['feed_error'] = 'El archivo notas.xml de la URL proporcionada no es un XML válido.';
                libxml_clear_errors();
                header('Location: nisaba.php?view=sources');
                exit;
            }
            libxml_clear_errors();

            $external_name = 'Nisaba Remoto';
            if (isset($notes_xml->channel->owner_name) && trim((string)$notes_xml->channel->owner_name) !== '') {
                $external_name = trim((string)$notes_xml->channel->owner_name);
            } elseif (isset($notes_xml->channel->title) && trim((string)$notes_xml->channel->title) !== '') {
                $external_name = trim((string)$notes_xml->channel->title);
            }

            $external_favicon = '';
            if (isset($notes_xml->channel->owner_favicon) && trim((string)$notes_xml->channel->owner_favicon) !== '') {
                $external_favicon = trim((string)$notes_xml->channel->owner_favicon);
            } elseif (isset($notes_xml->channel->image->url) && trim((string)$notes_xml->channel->image->url) !== '') {
                $external_favicon = trim((string)$notes_xml->channel->image->url);
            }

            if (!isset($xml_data->external_notes_sources)) {
                $xml_data->addChild('external_notes_sources');
            }

            $sources_node = $xml_data->external_notes_sources;
            $existing_source = null;
            foreach ($sources_node->source as $source_node) {
                if (rtrim((string)$source_node->url, '/') === $normalized_url) {
                    $existing_source = $source_node;
                    break;
                }
            }

            if ($existing_source) {
                $existing_source->name = $external_name;
                if (!empty($external_favicon)) {
                    if (isset($existing_source->favicon)) {
                        $existing_source->favicon = $external_favicon;
                    } else {
                        $existing_source->addChild('favicon', $external_favicon);
                    }
                }
                $_SESSION['feed_success'] = 'Fuente de notas externas actualizada: ' . htmlspecialchars($external_name) . '.';
            } else {
                $new_source = $sources_node->addChild('source');
                $new_source->addChild('name', $external_name);
                $new_source->addChild('url', $normalized_url);
                if (!empty($external_favicon)) {
                    $new_source->addChild('favicon', $external_favicon);
                }
                $_SESSION['feed_success'] = 'Ahora sigues las notas de: ' . htmlspecialchars($external_name) . '.';
            }

            $xml_data->asXML($userFile);
            header('Location: nisaba.php?view=sources');
            exit;
        }

        if (isset($_POST['delete_external_source'])) {
            $source_url = trim($_POST['delete_external_source']);
            if (!preg_match('#^https?://#i', $source_url)) {
                $source_url = 'https://' . ltrim($source_url, '/');
            }
            $normalized_url = rtrim($source_url, '/');

            if (isset($xml_data->external_notes_sources)) {
                foreach ($xml_data->external_notes_sources->source as $source_node) {
                    $saved_url = isset($source_node->url) ? rtrim((string)$source_node->url, '/') : '';
                    if ($saved_url === $normalized_url) {
                        unset($source_node[0]);
                        break;
                    }
                }

                if ($xml_data->external_notes_sources->count() === 0) {
                    unset($xml_data->external_notes_sources[0]);
                }

                $xml_data->asXML($userFile);
                $_SESSION['feed_success'] = 'Has dejado de seguir esas notas externas.';
            }

            header('Location: nisaba.php?view=sources');
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

                if (isset($_FILES['new_favicon']) && $_FILES['new_favicon']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['new_favicon'];
                    $allowed_types = ['image/png', 'image/jpeg', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml', 'image/webp'];
                    if (in_array($file['type'], $allowed_types) && $file['size'] < 1000000) { // 1MB limit
                        $filename = hash('md5', $original_url) . '_' . time() . '_' . basename($file['name']);
                        $save_path = FAVICON_DIR . '/' . $filename;
                        if (move_uploaded_file($file['tmp_name'], $save_path)) {
                            $feed_node['favicon'] = 'data/favicons/' . $filename;
                        }
                    }
                }

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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Covered+By+Your+Grace&family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&family=Patrick+Hand&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=VT323&display=swap" rel="stylesheet">
    <style>
        :root { --font-headline: 'Poppins', sans-serif; --font-body: 'Inter', sans-serif; --text-color: #333; --bg-color: #fff; --border-color: #e0e0e0; --accent-color: #007bff; --danger-color: #d9534f; }
        body { font-family: var(--font-body); color: var(--text-color); background-color: var(--bg-color); margin: 0; display: flex; flex-direction: column; min-height: 100vh; }
        .main-wrapper { min-height: 100vh; display: flex; flex-direction: column; }
        .main-container { flex-grow: 1; }
        .sidebar { font-size: 1rem; border-right: 1px solid var(--border-color); background: #f9f9f9; padding: 1.5em; }
        @media (min-width: 992px) {
            .sidebar { width: 720px; min-width: 720px; }
        }
        .content-column { flex: 1 1 auto; min-width: 0; }
        .content { font-size: 1.2em; padding: 1.5em; }
        @media (min-width: 992px) { .content { max-width: 900px; margin: 0 auto; } }
        @media (max-width: 991.98px) {
            .sidebar { border-right: none; border-bottom: 1px solid var(--border-color); }
            .content { padding: 1.25em; }
        }
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
        .error { color: var(--danger-color); margin-bottom: 1em; }
        .sidebar-list { list-style: none; padding: 0; margin: 0; }
        .sidebar-folder { margin-bottom: 1.5em; }
        .sidebar-folder-content { display: flex; gap: 2.5rem; align-items: stretch; }
        .sidebar-folder-list { flex: 1 1 auto; min-width: 0; padding-right: 2.5rem; }
        .sidebar-total-row { display: flex; align-items: center; justify-content: space-between; padding: 0.25em 0 0.75em 0; border-bottom: 1px solid var(--border-color); margin-bottom: 0.75em; }
        .sidebar-total-link { font-weight: 600; color: var(--text-color); transition: color 0.2s ease; }
        .sidebar-count { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; padding: 0.2em 0.6em; font-size: 0.85em; font-weight: 600; border-radius: 999px; background-color: #ececec; color: #555; margin-right: 0.75em; }
        .sidebar-folder-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35em; }
        .sidebar-folder-link { font-family: var(--font-headline); font-size: 1.1em; color: var(--text-color); transition: color 0.2s ease; }
        .sidebar-feeds { list-style: none; padding-left: 0; margin: 0; }
        .sidebar-feeds.feed-list { padding-left: 0; }
        .sidebar-feeds li { margin-bottom: 0.35em; display: flex; align-items: center; gap: 0.75rem; }
        .sidebar-feed-link { flex: 1; display: flex; align-items: center; gap: 0.45rem; color: var(--text-color); transition: color 0.2s ease; }
        .sidebar-feed-link img { width: 18px; height: 18px; border-radius: 50%; }
        .sidebar-total-link:hover, .sidebar-folder-link:hover, .sidebar-feed-link:hover { color: var(--accent-color); text-decoration: none; }
        .sidebar-total-link.active, .sidebar-folder-link.active, .sidebar-feed-link.active { color: var(--accent-color); font-weight: 600; }
        .sidebar-utilities { margin-top: 2em; padding: 1em 1.2em; border: 1px solid var(--border-color); border-radius: 10px; background: #fff; display: flex; flex-direction: column; gap: 0.6em; }
        .sidebar-utilities h5 { margin: 0 0 0.4em; font-size: 0.9em; text-transform: uppercase; letter-spacing: 0.08em; color: #666; }
        .sidebar-utility-link { font-weight: 600; color: var(--accent-color); }
        .sidebar-utility-link::before { content: '\203A\00A0'; }
        .sidebar-utility-link:hover { text-decoration: underline; }
        .notes-stack-wrapper { flex: 0 0 auto; display: flex; flex-direction: column; align-items: flex-start; }
        .notes-stack { position: relative; width: 215px; min-height: 320px; flex: 0 0 auto; }
        .notes-stack-own { min-height: 320px; }
        .notes-stack-received { margin-top: 3rem; min-height: 320px; }
        .notes-postit-main { display: block; position: relative; z-index: 9; background: #fff3a8; color: #433; padding: 1em 1.2em; border-radius: 6px 6px 14px 6px; box-shadow: 0 6px 12px rgba(0,0,0,0.18), inset 0 -6px 12px rgba(255,255,255,0.4); transform: rotate(-2deg); font-family: 'Covered By Your Grace', cursive; font-size: 1.3em; transition: transform 0.2s ease, box-shadow 0.2s ease; text-align: center; }
        .notes-postit-main.received { background: #ffe38f; }
        .notes-postit-main::after { content: ''; position: absolute; top: -12px; left: 50%; transform: translateX(-50%); width: 70px; height: 18px; background: rgba(0,0,0,0.08); border-radius: 3px; pointer-events: none; z-index: -1; }
        .notes-postit-main:hover { text-decoration: none; transform: rotate(0deg) scale(1.02); box-shadow: 0 12px 22px rgba(0,0,0,0.24), inset 0 -6px 12px rgba(255,255,255,0.5); }
        .notes-mini { position: absolute; display: block; width: 190px; padding: 0.9em 1.15em; border-radius: 6px 6px 12px 6px; color: #3a2f2f; box-shadow: 0 7px 14px rgba(0,0,0,0.2); transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .notes-mini-received { padding-top: 1.6em; }
        .notes-mini-favicon { position: absolute; top: 6px; right: 6px; width: 26px; height: 26px; border-radius: 50%; overflow: hidden; background: rgba(255,255,255,0.9); box-shadow: 0 2px 5px rgba(0,0,0,0.25); padding: 3px; }
        .notes-mini-favicon img { width: 100%; height: 100%; object-fit: contain; display: block; border-radius: 50%; }
        .notes-mini strong { display: block; font-size: 1.12em; margin-bottom: 0.4em; font-family: 'Covered By Your Grace', cursive; }
        .notes-mini span { display: block; font-size: 1em; line-height: 1.35; font-family: 'Patrick Hand', cursive; }
        .notes-mini:hover { text-decoration: none; transform: scale(1.03); box-shadow: 0 10px 18px rgba(0,0,0,0.24); }
        .notes-stack.no-mini { min-height: 160px; }
        @media (max-width: 991.98px) {
            .sidebar-folder-content { gap: 1rem; flex-direction: column; }
            .sidebar-folder-list { padding-right: 0; }
            .notes-stack-wrapper { width: 100%; }
            .notes-stack { width: 100%; min-height: 260px; }
            .notes-stack-received { margin-top: 1.8rem; }
            .notes-stack .notes-mini { position: relative; width: 100%; left: 0; top: 0; transform: rotate(0deg); margin-bottom: 0.75rem; box-shadow: 0 4px 10px rgba(0,0,0,0.18); }
            .notes-stack .notes-postit-main { transform: rotate(0deg); }
        }
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
        .note { position: relative; display: inline-block; width: 100%; padding: 1em; margin-bottom: 1em; box-shadow: 2px 2px 5px rgba(0,0,0,0.2); transition: transform 0.2s; }
        .note:hover { transform: scale(1.05); }
        .note .note-source-favicon { position: absolute; top: 0.6em; right: 0.6em; width: 28px; height: 28px; border-radius: 50%; object-fit: cover; box-shadow: 0 3px 6px rgba(0,0,0,0.25); }

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
        .note-display h4 {
            font-family: 'Covered By Your Grace';
        }
        .note-display p {
	    font-family: 'Patrick Hand';
	    font-size: 1em;
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

        .modal {
            display: none; 
            position: fixed; 
            z-index: 1001; 
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            border-radius: 8px;
        }
        .close-btn {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-btn:hover,
        .close-btn:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['username'])): ?>
        <div class="main-wrapper">
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
        <div class="container-fluid main-container px-0">
            <div class="row g-0 flex-lg-nowrap">
                <div class="col-12 col-lg-auto">
                    <aside class="sidebar h-100">
                        <div class="logo text-center mb-4"><img src="nisaba.png" alt="Logo Nisaba"></div>
                        <div class="d-grid gap-2 mb-4">
                            <a href="?update_cache=1" class="btn btn-primary" style="background-color: darkblue; border-color: darkblue;">Actualizar Feeds</a>
                            <a href="?view=nisaba_summary" class="btn btn-secondary" style="background-color: #1b8eed; border-color: #1b8eed;">Análisis</a>
                            <a href="#" id="translate-help-button" class="btn btn-info" style="background-color: skyblue; border-color: skyblue;">Traducir</a>
                        </div>
<?php
$own_notes_sidebar = [];
if (isset($xml_data->notes)) {
    $notes_nodes = $xml_data->notes->note;
    $notes_array = [];
    foreach ($notes_nodes as $note_node) {
        $notes_array[] = $note_node;
    }
    usort($notes_array, function($a, $b) {
        return strtotime((string)$b->date) - strtotime((string)$a->date);
    });
    $own_notes_sidebar = array_slice($notes_array, 0, 2);
}
$received_notes_all = [];
if (isset($xml_data->received_notes_cache)) {
    foreach ($xml_data->received_notes_cache->note as $note_node) {
        $received_notes_all[] = $note_node;
    }
    usort($received_notes_all, function($a, $b) {
        return strtotime((string)$b->date) - strtotime((string)$a->date);
    });
}
$received_notes_count = count($received_notes_all);
$received_notes_sidebar = array_slice($received_notes_all, 0, 2);
$sidebar_own_entries = [];
foreach ($own_notes_sidebar as $note_item) {
    $title = normalize_feed_text($note_item->article_title ?? '');
    if ($title === '') $title = 'Nota sin título';
    $excerpt = normalize_feed_text($note_item->content ?? '');
    $excerpt = $excerpt !== '' ? truncate_text($excerpt, 60) : 'Sin contenido';
    $sidebar_own_entries[] = [
        'type' => 'own',
        'title' => $title,
        'excerpt' => $excerpt,
        'link' => '?view=notes'
    ];
}

$sidebar_received_entries = [];
$sidebar_received_entries[] = [
    'type' => 'count',
    'title' => $received_notes_count . ' Notas Recibidas',
    'excerpt' => $received_notes_count ? 'Haz clic para verlas todas' : 'Todavía no recibes notas',
    'link' => '?view=received_notes',
    'favicon' => ''
];
foreach ($received_notes_sidebar as $note_item) {
    $title = normalize_feed_text($note_item->title ?? '');
    if ($title === '') $title = 'Nota recibida';
    $excerpt = normalize_feed_text($note_item->content ?? '');
    $excerpt = $excerpt !== '' ? truncate_text($excerpt, 60) : 'Sin contenido';
    $source_name = normalize_feed_text($note_item->source_name ?? '');
    if ($source_name !== '') {
        $excerpt = ($excerpt !== '' ? $excerpt . ' · ' : '') . 'Por ' . $source_name;
    }
    $favicon_path = '';
    if (isset($note_item->favicon) && trim((string)$note_item->favicon) !== '') {
        $favicon_path = trim((string)$note_item->favicon);
    }
    $sidebar_received_entries[] = [
        'type' => 'received',
        'title' => $title,
        'excerpt' => $excerpt,
        'link' => '?view=received_notes',
        'favicon' => $favicon_path
    ];
}

$note_colors = ['#ffd6a5', '#c9f2ff', '#baffc9', '#ffadad', '#f9f871', '#d0a9f5', '#ffcfdf', '#c4fcef'];
$note_offsets = [-32, 30, -22, 38, -14, 34, -40, 26];
$note_rotations = ['-4deg', '3deg', '-6deg', '4deg', '-2deg', '5deg', '-3deg', '2deg'];
$note_base_top = 92;
$note_step = 40;
$compute_stack_height = function ($count) use ($note_base_top, $note_step) {
    $count = max(0, (int)$count);
    if ($count === 0) {
        return $note_base_top + 160;
    }
    return $note_base_top + (($count - 1) * $note_step) + 180;
};
$own_entries_count = count($sidebar_own_entries);
$received_entries_count = count($sidebar_received_entries);
$notes_stack_own_classes = 'notes-stack notes-stack-own mt-4 mt-lg-0';
if ($own_entries_count === 0) {
    $notes_stack_own_classes .= ' no-mini';
}
$notes_stack_received_classes = 'notes-stack notes-stack-received';
if ($received_entries_count === 0) {
    $notes_stack_received_classes .= ' no-mini';
}
$notes_stack_own_style = 'height:' . $compute_stack_height($own_entries_count) . 'px;';
$notes_stack_received_style = 'height:' . $compute_stack_height($received_entries_count) . 'px;';

$sidebar_unread_by_feed = [];
if (file_exists($cacheFile)) {
    $sidebar_cache_snapshot = @simplexml_load_file($cacheFile);
    if ($sidebar_cache_snapshot) {
        foreach ($sidebar_cache_snapshot->item as $cached_item) {
            if ((string)$cached_item->read === '0') {
                $feed_key = (string)$cached_item->feed_url;
                if (!isset($sidebar_unread_by_feed[$feed_key])) {
                    $sidebar_unread_by_feed[$feed_key] = 0;
                }
                $sidebar_unread_by_feed[$feed_key]++;
            }
        }
    }
}

$sidebar_folders = [];
$total_unread_all = 0;
if (isset($xml_data->feeds)) {
    foreach ($xml_data->feeds->folder as $folder_node) {
        $folder_name = (string)$folder_node['name'];
        $folder_unread = 0;
        $folder_feeds = [];
        foreach ($folder_node->feed as $feed_node) {
            $feed_url = (string)$feed_node['url'];
            $feed_unread = $sidebar_unread_by_feed[$feed_url] ?? 0;
            $folder_unread += $feed_unread;
            $folder_feeds[] = [
                'name' => (string)$feed_node['name'],
                'url' => $feed_url,
                'favicon' => (string)$feed_node['favicon'] ?: 'nisaba.png',
                'folder' => $folder_name,
                'unread' => $feed_unread
            ];
        }
        $total_unread_all += $folder_unread;
        $sidebar_folders[] = [
            'name' => $folder_name,
            'unread' => $folder_unread,
            'feeds' => $folder_feeds
        ];
    }
}

$current_folder = $_GET['name'] ?? '';
$current_feed = $_GET['feed'] ?? '';
?>
                        <div class="sidebar-folder-content">
                            <div class="sidebar-folder-list">
                                <div class="sidebar-total-row">
                                    <span class="sidebar-count"><?php echo $total_unread_all; ?></span>
                                    <a href="?view=all_feeds" class="sidebar-total-link<?php echo ($current_view === 'all_feeds') ? ' active' : ''; ?>">Todas las fuentes</a>
                                </div>
<?php if (empty($sidebar_folders)): ?>
                                <p class="text-muted" style="margin-top: 1em;">Todavía no tienes fuentes suscritas.</p>
<?php else: ?>
<?php foreach ($sidebar_folders as $folder_entry): ?>
                                <div class="sidebar-folder">
                                    <div class="sidebar-folder-header">
                                        <a href="?view=folder&amp;name=<?php echo urlencode($folder_entry['name']); ?>" class="sidebar-folder-link<?php echo ($current_view === 'folder' && $current_folder === $folder_entry['name']) ? ' active' : ''; ?>"><?php echo htmlspecialchars($folder_entry['name']); ?></a>
                                        <span class="sidebar-count"><?php echo $folder_entry['unread']; ?></span>
                                    </div>
<?php if (!empty($folder_entry['feeds'])): ?>
                                    <ul class="sidebar-feeds feed-list">
<?php foreach ($folder_entry['feeds'] as $feed_entry): ?>
                                        <li>
                                            <span class="sidebar-count"><?php echo $feed_entry['unread']; ?></span>
                                            <a href="?feed=<?php echo urlencode($feed_entry['url']); ?>&amp;folder=<?php echo urlencode($feed_entry['folder']); ?>" class="sidebar-feed-link<?php echo ($current_view === 'feed_articles' && $current_feed === $feed_entry['url']) ? ' active' : ''; ?>">
                                                <img src="<?php echo htmlspecialchars($feed_entry['favicon']); ?>" alt="">
                                                <?php echo htmlspecialchars($feed_entry['name']); ?>
                                            </a>
                                        </li>
<?php endforeach; ?>
                                    </ul>
<?php endif; ?>
                                </div>
<?php endforeach; ?>
<?php endif; ?>
                                <div class="sidebar-utilities">
                                    <h5>Accesos</h5>
                                    <a href="?view=sources" class="sidebar-utility-link">Gestión de fuentes</a>
                                    <a href="?view=settings" class="sidebar-utility-link">Configuración</a>
                                </div>
                            </div>
                            <div class="notes-stack-wrapper">
                                <div class="<?php echo $notes_stack_own_classes; ?>" style="<?php echo htmlspecialchars($notes_stack_own_style, ENT_QUOTES); ?>">
                                    <a href="?view=notes" class="notes-postit-main">Notas</a>
<?php foreach ($sidebar_own_entries as $idx => $entry):
    $color = $note_colors[$idx % count($note_colors)];
    $left = $note_offsets[$idx % count($note_offsets)];
    $rotate = $note_rotations[$idx % count($note_rotations)];
    $top = $note_base_top + ($idx * $note_step);
    $zIndex = 20 - $idx;
    $style = sprintf('background:%s; top:%dpx; left:%dpx; transform: rotate(%s); z-index:%d;', $color, $top, $left, $rotate, $zIndex);
?>
                                <a href="<?php echo htmlspecialchars($entry['link']); ?>" class="notes-mini" style="<?php echo htmlspecialchars($style, ENT_QUOTES); ?>">
                                    <strong><?php echo htmlspecialchars($entry['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                                    <?php if (!empty($entry['excerpt'])): ?><span><?php echo htmlspecialchars($entry['excerpt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span><?php endif; ?>
                                </a>
<?php endforeach; ?>
                                </div>
<?php if (!empty($sidebar_received_entries)): ?>
                                <div class="<?php echo $notes_stack_received_classes; ?>" style="<?php echo htmlspecialchars($notes_stack_received_style, ENT_QUOTES); ?>">
                                    <a href="?view=received_notes" class="notes-postit-main received">Recibidas</a>
<?php foreach ($sidebar_received_entries as $idx => $entry):
    $color = $entry['type'] === 'count' ? '#ffe38f' : $note_colors[$idx % count($note_colors)];
    $left = $entry['type'] === 'count' ? 12 : $note_offsets[$idx % count($note_offsets)];
    $rotate = $entry['type'] === 'count' ? '2deg' : $note_rotations[$idx % count($note_rotations)];
    $top = $note_base_top + ($idx * $note_step);
    $zIndex = 20 - $idx;
    $style = sprintf('background:%s; top:%dpx; left:%dpx; transform: rotate(%s); z-index:%d;', $color, $top, $left, $rotate, $zIndex);
    $note_classes = 'notes-mini';
    if ($entry['type'] === 'received') {
        $note_classes .= ' notes-mini-received';
    }
?>
                                <a href="<?php echo htmlspecialchars($entry['link']); ?>" class="<?php echo $note_classes; ?>" style="<?php echo htmlspecialchars($style, ENT_QUOTES); ?>">
<?php if ($entry['type'] === 'received' && !empty($entry['favicon'])): ?>
                                    <span class="notes-mini-favicon"><img src="<?php echo htmlspecialchars($entry['favicon']); ?>" alt=""></span>
<?php endif; ?>
                                    <strong><?php echo htmlspecialchars($entry['title']); ?></strong>
                                    <?php if (!empty($entry['excerpt'])): ?><span><?php echo htmlspecialchars($entry['excerpt']); ?></span><?php endif; ?>
                                </a>
<?php endforeach; ?>
                                </div>
<?php endif; ?>
                            </div>
                        </div>
                    </aside>
                </div>
                <div class="col-12 col-lg content-column">
                    <main class="content py-4 px-3 px-lg-4">
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
                                    echo '    <input type="hidden" name="return_url" value="' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'nisaba.php?view=nisaba_summary') . '">';
                                    echo '    <div class="form-group">';
                                    echo '        <textarea name="note_content" class="postit-textarea" placeholder="Escribe una nota sobre este análisis...">' . htmlspecialchars($note_text) . '</textarea>';
                                    echo '    </div>';
                                    echo '    <button type="submit" name="save_note" class="btn btn-primary btn-sm">Guardar Nota</button>';
                                    echo '</form>';

                                    echo '<hr>';
                                }
                            }
                        }
                    }
                    if (!$has_any_unread) {
                        echo "<p>¡Estás al día! No hay artículos nuevos que analizar.</p>";
                    }
                    ?>
                <?php elseif ($current_view === 'single_article'): ?>
                    <?php
                        $article_guid = $_GET['article_guid'];
                        $article = null;
                        $favicon_url = 'nisaba.png';

                        // Defensively reload user data to ensure settings are fresh
                        $fresh_xml_data = simplexml_load_file($userFile);
        $fresh_cache_duration = sanitize_cache_duration(isset($fresh_xml_data->settings->cache_duration) ? (string)$fresh_xml_data->settings->cache_duration : (string)DEFAULT_CACHE_DURATION_HOURS);

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
                            $article_link = (string)$article->link;
                            $archive_today_url = ($archive_integration_enabled && $article_link !== '') ? 'https://archive.today/?run=1&url=' . rawurlencode($article_link) : '';
                        ?>
                        <h2><?php echo htmlspecialchars($article_title); ?> <img src="<?php echo htmlspecialchars($favicon_url); ?>" alt="" style="width: 32px; height: 32px; vertical-align: middle; margin-left: 10px; border-radius: 4px;"></h2>
                        <?php if (!empty($article_image)): ?>
                            <img src="<?php echo htmlspecialchars($article_image); ?>" alt="" style="max-width: 100%; height: auto; border-radius: 8px; margin-bottom: 1em;">
                        <?php endif; ?>
                        <div class="article-full-content">
                            <?php 
                                // If content seems to be HTML, render it directly.
                                // Otherwise, assume it's plain text and convert newlines to paragraphs.
                                if (preg_match('/<\/?[a-z][\s\S]*>/i', $article_content)) {
                                    echo $article_content;
                                } else {
                                    $paragraphs = preg_split('/\r\n|\r|\n/', $article_content);
                                    foreach ($paragraphs as $paragraph) {
                                        if (trim($paragraph) !== '') {
                                            echo '<p>' . htmlspecialchars(trim($paragraph)) . '</p>';
                                        }
                                    }
                                }
                            ?>
                        </div>
                        <hr>
                        <div class="article-actions" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center;">
                            <a href="<?php echo htmlspecialchars($article_link); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary">Ver original</a>
<?php if ($archive_today_url !== ''): ?>
                            <a href="<?php echo htmlspecialchars($archive_today_url); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary">Versión en Archive.today</a>
<?php endif; ?>
                        </div>
                        <hr>
                        <h3>Notas</h3>
                        <form method="POST" action="nisaba.php" class="note-form">
                            <input type="hidden" name="article_guid" value="<?php echo htmlspecialchars($article->guid); ?>">
                            <input type="hidden" name="article_title" value="<?php echo htmlspecialchars($article_title); ?>">
                            <input type="hidden" name="article_link" value="<?php echo htmlspecialchars($article->link); ?>">
                            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'nisaba.php?article_guid=' . urlencode($article->guid)); ?>">
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
                            <button type="submit" name="save_note" class="btn btn-primary btn-sm">Guardar Nota</button>
                        </form>
                    <?php else: ?>
                        <p>Artículo no encontrado.</p>
                    <?php endif; ?>
                <?php elseif ($current_view === 'all_feeds'): ?>
                    <div style="margin-bottom: 1em;"><button onclick="markAllRead()" class="btn btn-outline-success btn-sm">Todos leídos</button></div>
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
                        if (isset($cacheFile) && file_exists($cacheFile)) {
                            $cache_xml = simplexml_load_file($cacheFile);
                            if($cache_xml) {
                                $articles = $cache_xml->xpath('//item');
                                if (empty($articles)) { echo "<li>No hay artículos por leer. Prueba a actualizar las feeds.</li>"; } 
                                else { 
                                    $sorted_articles = [];
                                    foreach ($articles as $item) {
                                        $sorted_articles[] = $item;
                                    }
                                    usort($sorted_articles, function($a, $b) {
                                        return strtotime((string)$b->pubDate) - strtotime((string)$a->pubDate);
                                    });

                                    $favicon_map = [];
                                    if (isset($xml_data->feeds)) {
                                        foreach ($xml_data->xpath('//feed') as $feed) {
                                            $favicon_map[(string)$feed['url']] = (string)$feed['favicon'];
                                        }
                                    }

                                    foreach ($sorted_articles as $item) {
                                        $is_read = (string)$item->read === '1';
                                        if ($is_read && $show_read_articles === 'false') continue;

                                        $feed_url = (string)$item->feed_url;
                                        $favicon_url = $favicon_map[$feed_url] ?? 'nisaba.png';

                                        $raw_title = !empty($item->title_translated) ? $item->title_translated : $item->title_original;
                                        $raw_desc = !empty($item->content_translated) ? $item->content_translated : $item->content_original;
                                        $display_title = normalize_feed_text($raw_title);
                                        $display_desc = normalize_feed_text($raw_desc);
                                        echo '<li class="article-item' . ($is_read ? ' read' : '') . '" data-guid="' . htmlspecialchars($item->guid) . '">';
                                        if (!empty($item->image)) echo '<img src="' . htmlspecialchars($item->image) . '" alt="" class="article-image">';
                                        echo '<h3><a href="?article_guid=' . urlencode($item->guid) . '">' . htmlspecialchars($display_title) . '</a></h3>';
                                        echo '<p>' . htmlspecialchars(truncate_text($display_desc, 500)) . ' <img src="' . htmlspecialchars($favicon_url) . '" style="width: 16px; height: 16px; vertical-align: middle;"></p>';
                                        if (!$is_read) {
                                            echo '<div style="clear: both; padding-top: 10px;"><a href="?action=mark_read&guid=' . urlencode($item->guid) . '&return_url=' . urlencode($_SERVER['REQUEST_URI']) . '" onclick="markAsRead(this, \'' . urlencode($item->guid) . '\'); return false;" class="btn mark-as-read-btn">Marcar leído</a></div>';
                                        }
                                        echo '</li>';
                                    }
                                }
                            } else { echo "<li>No hay artículos por leer. Prueba a actualizar las feeds.</li>"; }
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
                    <div style="margin-bottom: 1em;"><button onclick="markAllRead()" class="btn btn-outline-success btn-sm">Todos leídos</button></div>
                    <h2>Carpeta: <?php echo htmlspecialchars($folder_name); ?> (<?php echo $unread_count; ?>)</h2>
                    <ul class="article-list">
                        <?php
                        if (isset($cacheFile) && file_exists($cacheFile) && !empty($folder_name)) {
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

                                $favicon_map = [];
                                if (isset($xml_data->feeds)) {
                                    foreach ($xml_data->xpath('//feed') as $feed) {
                                        $favicon_map[(string)$feed['url']] = (string)$feed['favicon'];
                                    }
                                }

                                foreach ($sorted_articles as $item) {
                                    $is_read = (string)$item->read === '1';
                                    if ($is_read && $show_read_articles === 'false') continue;

                                    $feed_url = (string)$item->feed_url;
                                    $favicon_url = $favicon_map[$feed_url] ?? 'nisaba.png';

                                    $raw_title = !empty($item->title_translated) ? $item->title_translated : $item->title_original;
                                    $raw_desc = !empty($item->content_translated) ? $item->content_translated : $item->content_original;
                                    $display_title = normalize_feed_text($raw_title);
                                    $display_desc = normalize_feed_text($raw_desc);
                                    echo '<li class="article-item' . ($is_read ? ' read' : '') . '" data-guid="' . htmlspecialchars($item->guid) . '">';
                                    if (!empty($item->image)) echo '<img src="' . htmlspecialchars($item->image) . '" alt="" class="article-image">';
                                    echo '<h3><a href="?article_guid=' . urlencode($item->guid) . '&folder=' . urlencode($folder_name) . '">' . htmlspecialchars($display_title) . '</a></h3>';
                                    echo '<p>' . htmlspecialchars(truncate_text($display_desc, 1250)) . ' <img src="' . htmlspecialchars($favicon_url) . '" style="width: 16px; height: 16px; vertical-align: middle;"></p>';
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
                    <div style="margin-bottom: 1em;"><button onclick="markAllRead()" class="btn btn-outline-success btn-sm">Todos leídos</button></div>
                    <h2><img src="<?php echo htmlspecialchars($favicon_url); ?>" alt="" style="width: 32px; height: 32px; vertical-align: middle; margin-right: 10px; border-radius: 4px;"> <?php echo htmlspecialchars($feed_name); ?> (<?php echo $unread_count; ?>)</h2>
                    <ul class="article-list">
                        <?php
                        if (file_exists($cacheFile)) {
                            $cache_xml = simplexml_load_file($cacheFile);
                            if($cache_xml){
                                $articles = $cache_xml->xpath('//item[feed_url="' . $selected_feed_url . '"]');
                                if (empty($articles)) { echo "<li>No hay artículos por leer. Actualiza las feeds.</li>"; } 
                                else { foreach ($articles as $item) {
                                    $is_read = (string)$item->read === '1';
                                    if ($is_read && $show_read_articles === 'false') continue;

                                    $raw_title = !empty($item->title_translated) ? $item->title_translated : $item->title_original;
                                    $raw_desc = !empty($item->content_translated) ? $item->content_translated : $item->content_original;
                                    $display_title = normalize_feed_text($raw_title);
                                    $display_desc = normalize_feed_text($raw_desc);
                                    echo '<li class="article-item' . ($is_read ? ' read' : '') . '" data-guid="' . htmlspecialchars($item->guid) . '">';
                                    if (!empty($item->image)) echo '<img src="' . htmlspecialchars($item->image) . '" alt="" class="article-image">';
                                    echo '<h3><a href="?article_guid=' . urlencode($item->guid) . '&folder=' . urlencode($_GET['folder'] ?? '') . '">' . htmlspecialchars($display_title) . '</a></h3>';
                                    echo '<p>' . htmlspecialchars(truncate_text($display_desc, 1250)) . ' <img src="' . htmlspecialchars($favicon_url) . '" style="width: 16px; height: 16px; vertical-align: middle;"></p>';
                                    if (!$is_read) {
                                        echo '<div style="clear: both; padding-top: 10px;"><a href="?action=mark_read&guid=' . urlencode($item->guid) . '&return_url=' . urlencode($_SERVER['REQUEST_URI']) . '" onclick="markAsRead(this, \'' . urlencode($item->guid) . '\'); return false;" class="btn mark-as-read-btn">Marcar leído</a></div>';
                                    }
                                    echo '</li>';
                                }}
                            } else { echo "<li>No hay artículos por leer. Actualiza las feeds.</li>"; }
                        } else { echo "<li>No se ha generado la cache.</li>"; }
                        ?>
                    </ul>
                <?php elseif ($current_view === 'received_notes'): ?>
                    <h2>Notas Recibidas</h2>
                    <div class="notes-container">
                    <?php
                        $received_notes_view = [];
                        if (isset($xml_data->received_notes_cache)) {
                            foreach ($xml_data->received_notes_cache->note as $note_node) {
                                $received_notes_view[] = $note_node;
                            }
                            usort($received_notes_view, function($a, $b) {
                                return strtotime((string)$b->date) - strtotime((string)$a->date);
                            });
                        }
                        if (empty($received_notes_view)) {
                            echo '<p>No has recibido notas todavía. Añade Nisabas externos desde Gestionar Fuentes.</p>';
                        } else {
                            $received_colors = ['#fff3a8', '#c9f2ff', '#ffcfdf', '#baffc9', '#f9f871', '#d0a9f5'];
                            $i = 0;
                            foreach ($received_notes_view as $note_entry) {
                                $color = $received_colors[$i % count($received_colors)];
                                $title = normalize_feed_text($note_entry->title ?? '');
                                if ($title === '') $title = 'Nota recibida';
                                $content_text = normalize_feed_text($note_entry->content ?? '');
                                $content_text = $content_text !== '' ? truncate_text($content_text, 400) : 'Sin contenido';
                                $source_name = normalize_feed_text($note_entry->source_name ?? '');
                                $source_url = (string)($note_entry->source_url ?? '');
                                $favicon = (string)($note_entry->favicon ?? '');
                                $link = (string)($note_entry->link ?? '');
                                echo '<div class="note" style="background-color:' . $color . '">';
                                if ($favicon !== '') {
                                    echo '<img src="' . htmlspecialchars($favicon) . '" alt="" class="note-source-favicon">';
                                }
                                echo '<div class="note-display">';
                                if ($link !== '') {
                                    echo '<h4><a href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($title) . '</a></h4>';
                                } else {
                                    echo '<h4>' . htmlspecialchars($title) . '</h4>';
                                }
                                if ($source_name !== '') {
                                    echo '<p><small>Compartida por ';
                                    echo htmlspecialchars($source_name);
                                    echo '</small></p>';
                                }
                                echo '<p>' . nl2br(htmlspecialchars($content_text)) . '</p>';
                                echo '</div>';
                                echo '</div>';
                                $i++;
                            }
                        }
                    ?>
                    </div>
                <?php elseif ($current_view === 'notes'): ?>
                    <h2>Notas</h2>
                    <div class="notes-container">
                        <?php
                        if (isset($xml_data->notes)) {
                            $notes_nodes = $xml_data->notes->note;
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
                                echo '<button class="btn btn-outline-secondary btn-sm" onclick="toggleNoteEdit(this)">Editar</button>';
                                echo '<form method="POST" action="nisaba.php?view=notes" onsubmit="return confirm(\'¿Seguro que quieres eliminar esta nota?\');">';
                                echo '<input type="hidden" name="delete_note" value="' . htmlspecialchars($note->article_guid) . '">';
                                echo '<button type="submit" class="btn btn-danger">Eliminar</button>';
                                echo '</form></div></div>';

                                echo '<form method="POST" action="nisaba.php?view=notes" class="note-edit-form" style="display:none;">';
                                echo '<input type="hidden" name="article_guid" value="' . htmlspecialchars($note->article_guid) . '">';
                                echo '<div class="form-group"><label>Título</label><input type="text" name="article_title" value="' . htmlspecialchars($note->article_title) . '" class="form-group input"></div>';
                                echo '<div class="form-group"><label>Contenido</label><textarea name="note_content" class="postit-textarea">' . htmlspecialchars($note->content) . '</textarea></div>';
                                echo '<div style="display: flex; gap: 10px;"><button type="submit" name="edit_note" class="btn btn-primary">Guardar</button>';
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
                    <form method="POST" action="?view=settings" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="display_name">Tu nombre o nick</label>
                            <input type="text" id="display_name" name="display_name" value="<?php echo isset($xml_data->settings->display_name) ? htmlspecialchars((string)$xml_data->settings->display_name) : ''; ?>" class="form-group input">
                            <p style="font-size: 0.8em; color: #555;">Este nombre se mostrará en tu feed de notas públicas (notas.xml).</p>
                        </div>
                        <div class="form-group">
                            <label for="user_favicon">Tu favicon (opcional)</label>
                            <input type="file" id="user_favicon" name="user_favicon" class="form-group input" accept="image/png,image/jpeg,image/gif,image/x-icon,image/svg+xml,image/webp">
                            <?php if (isset($xml_data->settings->user_favicon) && !empty((string)$xml_data->settings->user_favicon)): ?>
                                <p style="font-size: 0.8em; color: #555;">Favicon actual: <img src="<?php echo htmlspecialchars((string)$xml_data->settings->user_favicon); ?>" style="width: 24px; height: 24px; vertical-align: middle; border-radius: 4px;"></p>
                            <?php endif; ?>
                            <p style="font-size: 0.8em; color: #555;">Sube una imagen cuadrada (máx 512x512, 1MB). Se mostrará en tu feed de notas y cuando otros sigan tus notas.</p>
                        </div>
                        <hr>
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
                            <label>Prompt para el análisis de Gemini</label>
                            <div class="summary-container">
                                <button class="copy-btn" onclick="copySummary(this)">Copiar</button>
                                <div class="summary-box"><pre class="summary-content"><?php echo htmlspecialchars($gemini_prompt); ?></pre></div>
                            </div>
                            <p class="text-muted" style="font-size: 0.8em;">Para modificar este prompt edita el archivo <code>prompt.txt</code> en el directorio principal de Nisaba.</p>
                        </div>
                        <hr>
                        <div class="form-group">
                            <label for="google_translate_api_key">API Key de Google Translate</label>
                            <input type="password" id="google_translate_api_key" name="google_translate_api_key" placeholder="************" value="<?php echo htmlspecialchars($google_api_key); ?>">
                            <p style="font-size: 0.8em; color: #555;">Clave guardada: <code><?php echo mask_api_key($google_api_key); ?></code></p>
                        </div>
                        <div class="form-group">
                            <label for="cache_duration">Permanencia de artículos leídos en la caché</label>
                            <select id="cache_duration" name="cache_duration" class="form-group input">
                                <?php
                                    $durations = [
                                        '720' => '1 mes (≈30 días)',
                                        (string)DEFAULT_CACHE_DURATION_HOURS => '2 meses (≈60 días)',
                                        '4320' => '6 meses (≈180 días)'
                                    ];
                                    $selected_duration = sanitize_cache_duration(isset($xml_data->settings->cache_duration) ? (string)$xml_data->settings->cache_duration : (string)DEFAULT_CACHE_DURATION_HOURS);
                                    if (!array_key_exists($selected_duration, $durations)) {
                                        $selected_duration = (string)DEFAULT_CACHE_DURATION_HOURS;
                                    }
                                    foreach ($durations as $value => $label) {
                                        echo '<option value="' . $value . '"' . ($selected_duration === $value ? ' selected' : '') . '>' . $label . '</option>';
                                    }
                                ?>
                            </select>
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
                        <div class="form-group">
                            <label for="archive_integration" style="display: flex; align-items: center; gap: 0.5em;">
                                <input type="checkbox" id="archive_integration" name="archive_integration" value="1" <?php echo $archive_integration_enabled ? 'checked' : ''; ?>>
                                Integración con Archive.today
                            </label>
                            <p style="font-size: 0.8em; color: #555;">Muestra un enlace directo a la copia almacenada en Archive.today cuando abras un artículo.</p>
                        </div>
                        <button type="submit" name="save_settings" class="btn btn-primary">Guardar Configuración</button>
                    </form>
                <?php else: ?>
                    <h2>Gestionar Fuentes</h2>
                    <?php if ($feed_error): ?><p class="error"><?php echo $feed_error; ?></p><?php endif; ?>
                    <?php if ($feed_success): ?><p style="color: green; margin-bottom: 1em; font-weight: 600;"><?php echo $feed_success; ?></p><?php endif; ?>
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
                                        <div style="display:none;">
                                            <input type="hidden" name="original_url" value="<?php echo htmlspecialchars($feed['url']); ?>">
                                            <input type="text" name="feed_name" value="<?php echo htmlspecialchars($feed['name']); ?>">
                                            <input type="text" name="feed_favicon" value="<?php echo htmlspecialchars($feed['favicon']); ?>">
                                            <input type="text" name="folder_name" value="<?php echo htmlspecialchars($folder['name']); ?>">
                                            <input type="text" name="feed_lang" value="<?php echo htmlspecialchars($feed['lang']); ?>">
                                        </div>
                                        <button onclick="openEditModal(this)" class="btn btn-outline-secondary btn-sm">Editar</button>
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
                                <button type="submit" name="add_feed" class="btn btn-primary">Añadir Fuente</button>
                            </form>
                        </div>
                        <div style="flex: 1; border-left: 1px solid var(--border-color); padding-left: 2em;">
                            <form method="POST" action="nisaba.php" enctype="multipart/form-data">
                                <h4>Importar desde OPML</h4>
                                <div class="form-group">
                                    <label for="opml_file">Archivo OPML</label>
                                    <input type="file" id="opml_file" name="opml_file" accept=".opml, .xml" required>
                                </div>
                                <button type="submit" name="import_opml" class="btn btn-outline-secondary">Importar</button>
                            </form>
                        </div>
                    </div>
                    <hr class="my-4">
                    <h3>Seguir las notas de otros usuarios</h3>
                    <p class="text-muted">Introduce la URL base del Nisaba que deseas seguir (por ejemplo, <code>https://ejemplo.org/nisaba</code>). Nisaba buscará su feed de notas, obtendrá el nombre y favicon del autor automáticamente y lo añadirá a tus fuentes.</p>
                    <form method="POST" action="nisaba.php?view=sources" class="row g-3 align-items-end mb-4">
                        <div class="col-12 col-lg-8">
                            <label for="external_url" class="form-label">URL del Nisaba a seguir</label>
                            <input type="url" id="external_url" name="external_url" class="form-control" placeholder="https://ejemplo.org/nisaba" required>
                        </div>
                        <div class="col-12 col-lg-4 d-grid">
                            <button type="submit" name="add_external_source" class="btn btn-primary">Seguir notas</button>
                        </div>
                    </form>
                    <?php
                        $external_sources = [];
                        if (isset($xml_data->external_notes_sources)) {
                            foreach ($xml_data->external_notes_sources->source as $source_node) {
                                $external_sources[] = $source_node;
                            }
                        }
                        if (!empty($external_sources)) {
                            echo '<ul class="list-group mb-4">';
                            foreach ($external_sources as $source_node) {
                                $ext_name = htmlspecialchars((string)($source_node->name ?? 'Nisaba'));
                                $ext_url = htmlspecialchars((string)($source_node->url ?? ''));
                                $ext_favicon = htmlspecialchars((string)($source_node->favicon ?? ''));
                                echo '<li class="list-group-item d-flex justify-content-between align-items-start">';
                                echo '<div class="me-auto">';
                                echo '<div class="fw-semibold">' . $ext_name . '</div>';
                                if ($ext_url !== '') {
                                    echo '<small>' . $ext_url . '</small>';
                                }
                                if ($ext_favicon !== '') {
                                    echo '<div style="margin-top: 0.35rem;"><img src="' . $ext_favicon . '" alt="Favicon" style="width:24px; height:24px; border-radius:6px;"></div>';
                                }
                                echo '</div>';
                                echo '<div style="display:none;">';
                                echo '<input type="hidden" name="original_url" value="' . $ext_url . '">';
                                echo '<input type="hidden" name="external_name" value="' . $ext_name . '">';
                                echo '<input type="hidden" name="external_favicon" value="' . $ext_favicon . '">';
                                echo '</div>';
                                echo '<div class="d-flex gap-2 ms-3">';
                                echo '<button onclick="openEditExternalSourceModal(this)" class="btn btn-outline-secondary btn-sm">Editar</button>';
                                echo '<form method="POST" action="nisaba.php?view=sources" onsubmit="return confirm(\'¿Seguro que quieres dejar de seguir estas notas?\');" class="m-0">';
                                echo '<input type="hidden" name="delete_external_source" value="' . $ext_url . '">';
                                echo '<button type="submit" class="btn btn-danger btn-sm">Eliminar</button>';
                                echo '</form>';
                                echo '</div>';
                                echo '</li>';
                            }
                            echo '</ul>';
                        } else {
                            echo '<p class="text-muted">Todavía no sigues notas de otros usuarios.</p>';
                        }
                    ?>
                <?php endif; ?>

                <div id="edit-feed-modal" class="modal">
                    <div class="modal-content">
                        <span class="close-btn" onclick="closeModal('edit-feed-modal')">&times;</span>
                        <h3>Editar Fuente</h3>
                        <form method="POST" action="nisaba.php" enctype="multipart/form-data">
                            <input type="hidden" name="original_url" id="edit-original-url">
                            <div class="form-group">
                                <label for="edit-feed-name">Nombre</label>
                                <input type="text" name="feed_name" id="edit-feed-name" class="form-group input" required>
                            </div>
                            <div class="form-group">
                                <label>Favicon Actual</label>
                                <img id="edit-current-favicon" src="" style="width: 24px; height: 24px; vertical-align: middle;">
                            </div>
                            <div class="form-group">
                                <label for="edit-feed-favicon-upload">Subir nuevo favicon (opcional)</label>
                                <input type="file" name="new_favicon" id="edit-feed-favicon-upload" class="form-group input" accept="image/*">
                            </div>
                            <div class="form-group">
                                <label for="edit-folder-name">Carpeta</label>
                                <input type="text" name="folder_name" id="edit-folder-name" class="form-group input" required>
                            </div>
                            <div class="form-group">
                                <label for="edit-feed-lang">Idioma</label>
                                <input type="text" name="feed_lang" id="edit-feed-lang" class="form-group input" placeholder="ej: es, en, fr">
                            </div>
                            <button type="submit" name="edit_feed" class="btn btn-primary">Guardar</button>
                        </form>
                    </div>
                </div>
                <div id="edit-external-source-modal" class="modal">
                    <div class="modal-content">
                        <span class="close-btn" onclick="closeModal('edit-external-source-modal')">&times;</span>
                        <h3>Editar Fuente de Notas Externa</h3>
                        <form method="POST" action="nisaba.php?view=sources" enctype="multipart/form-data">
                            <input type="hidden" name="original_url" id="edit-external-original-url">
                            <div class="form-group">
                                <label for="edit-external-name">Nombre</label>
                                <input type="text" name="external_name" id="edit-external-name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Favicon Actual</label>
                                <img id="edit-external-current-favicon" src="" style="width: 24px; height: 24px; vertical-align: middle; border-radius: 4px;">
                            </div>
                            <div class="form-group">
                                <label for="edit-external-favicon-upload">Subir nuevo favicon (opcional)</label>
                                <input type="file" name="new_favicon" id="edit-external-favicon-upload" class="form-control" accept="image/*">
                            </div>
                            <button type="submit" name="edit_external_source" class="btn btn-primary">Guardar</button>
                        </form>
                    </div>
                </div>

            </main>
        </div>
        </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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

        function openEditModal(button) {
            const li = button.closest('li');
            const original_url = li.querySelector('input[name="original_url"]').value;
            const feed_name = li.querySelector('input[name="feed_name"]').value;
            const feed_favicon = li.querySelector('input[name="feed_favicon"]').value;
            const folder_name = li.querySelector('input[name="folder_name"]').value;
            const feed_lang = li.querySelector('input[name="feed_lang"]').value;

            document.getElementById('edit-original-url').value = original_url;
            document.getElementById('edit-feed-name').value = feed_name;
            document.getElementById('edit-current-favicon').src = feed_favicon;
            document.getElementById('edit-folder-name').value = folder_name;
            document.getElementById('edit-feed-lang').value = feed_lang;

            document.getElementById('edit-feed-modal').style.display = 'block';
        }

        function openEditExternalSourceModal(button) {
            const li = button.closest('li');
            const original_url = li.querySelector('input[name="original_url"]').value;
            const external_name = li.querySelector('input[name="external_name"]').value;
            const external_favicon = li.querySelector('input[name="external_favicon"]').value;

            document.getElementById('edit-external-original-url').value = original_url;
            document.getElementById('edit-external-name').value = external_name;
            document.getElementById('edit-external-current-favicon').src = external_favicon;

            document.getElementById('edit-external-source-modal').style.display = 'block';
        }

        function closeModal(modalId) {
            if (modalId) {
                document.getElementById(modalId).style.display = 'none';
            } else {
                // Fallback for old calls
                document.getElementById('edit-feed-modal').style.display = 'none';
            }
        }

        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let i = 0; i < modals.length; i++) {
                if (event.target == modals[i]) {
                    modals[i].style.display = "none";
                }
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            // Font size controls
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

            // Translation help modal
            const modal = document.getElementById("translation-help-modal");
            const btn = document.getElementById("translate-help-button");
            const span = document.getElementsByClassName("translate-close-btn")[0];

            if (btn && modal) {
                btn.addEventListener("click", function(event) {
                    event.preventDefault();
                    modal.style.display = "block";
                });
            }

            if (span && modal) {
                span.addEventListener("click", function() {
                    modal.style.display = "none";
                });
            }
        });
        </script>
        </div>
        <div id="translation-help-modal" class="modal">
            <div class="modal-content">
                <span class="close-btn translate-close-btn">&times;</span>
                <h2>Ayuda de Traducción</h2>
                <div class="translate-modal-body">
                  <p>Esta página está preparada para que tu navegador la traduzca automáticamente. Si la ventana de traducción no apareció, ¡no te preocupes! Puedes hacerlo manualmente y configurarlo para el futuro.</p>
                  
                  <div class="browser-section">
                    <h4><img src="https://www.google.com/chrome/static/images/favicons/favicon-32x32.png" alt="Chrome" class="browser-icon"> Google Chrome / Edge</h4>
                    <p>Sigue estos pasos para asegurar que la traducción siempre funcione correctamente:</p>
                    <ol>
                      <li>Pulsa en "Todos" para ver el listado de todas las noticias (es donde es más fácil que tengas contenidos en varias lenguas)</li>
                      <li>Haz <strong>clic derecho</strong> en cualquier lugar de la página y selecciona <strong>"Traducir al español"</strong>.</li>
                      <li>Si en la pequeña ventana emergente del navegador, como idioma de origen sale un idioma distinto a "Idioma detectado", pulsa los tres puntos (Opciones) y selecciona "La página no está en...".</li>
                      <li>En el menú desplegable que te saldrá elige <strong>"Idioma detectado"</strong>.</li>
                      <li>Activa la casilla <strong>"Traducir siempre"</strong> y acepta. Todo te aparecerá traducido ahora: titulares y entradillas.</li>
                      <li>En la vista de noticias individuales no te preocupes si no te ofrece "Idioma detectado", marca "Traducir siempre" y acepta.</li>
                    </ol>
                  </div>

                  <div class="browser-section">
                    <h4><img src="https://www.mozilla.org/media/img/favicons/firefox/browser/favicon-32x32.png" alt="Firefox" class="browser-icon"> Mozilla Firefox</h4>
                    <p>Las versiones modernas de Firefox incluyen una función de traducción privada y automática.</p>
                    <ol>
                      <li>Al visitar la página, debería aparecer un panel de traducción. Si no es así, busca el icono de traducción en la barra de herramientas.</li>
                      <li>En el panel, asegúrate de que el idioma de origen esté bien detectado y elige "Español" como destino.</li>
                      <li>Haz clic en el botón <strong>"Traducir"</strong>.</li>
                      <li>Puedes usar el icono de engranaje (Opciones) en el panel para marcar la opción <strong>"Traducir siempre"</strong>.</li>
                    </ol>
                  </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="auth-container">
            <div class="logo"><img src="nisaba.png" alt="Logo Nisaba"></div>
            <?php if ($error): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>
            <?php
                $user_files = glob(DATA_DIR . '/*.xml');
                if (empty($user_files)): 
            ?>
                <form method="POST" action="nisaba.php"><h3>Crear cuenta de administrador</h3><div class="form-group"><label for="reg-username">Usuario</label><input type="text" id="reg-username" name="username" required></div><div class="form-group"><label for="reg-password">Contraseña</label><input type="password" id="reg-password" name="password" required></div><button type="submit" name="register" class="btn btn-primary w-100">Registrar</button></form>
            <?php else: ?>
                <form method="POST" action="nisaba.php"><h3>Iniciar Sesión</h3><div class="form-group"><label for="login-username">Usuario</label><input type="text" id="login-username" name="username" required></div><div class="form-group"><label for="login-password">Contraseña</label><input type="password" id="login-password" name="password" required></div><button type="submit" name="login" class="btn btn-primary w-100">Entrar</button></form>
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
