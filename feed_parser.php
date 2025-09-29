<?php

/**
 * Utility helpers for fetching and parsing RSS/Atom feeds without SimplePie.
 */

function feed_parser_debug(string $message): void
{
    if (function_exists('nisaba_debug')) {
        nisaba_debug('FEED_PARSER: ' . $message);
    }
}

function fetch_feed_content(string $url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'Accept-Encoding: gzip, deflate, br',
        'Accept-Language: en-US,en;q=0.9',
        'Cache-Control: no-cache',
    ]);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $content = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        return $content;
    }

    return false;
}

function normalize_feed_content(string $raw_content, string $feed_url, array &$errors = [])
{
    $normalized = preg_replace('/<(\\/?)([a-zA-Z0-9]+):/i', '<$1$2_', $raw_content);

    libxml_use_internal_errors(true);
    $source_xml = simplexml_load_string($normalized);
    if ($source_xml === false) {
        $errors[] = 'XML Parsing Failed for feed: ' . $feed_url;
        foreach (libxml_get_errors() as $error) {
            $errors[] = 'LibXML Error: ' . trim($error->message);
        }
        libxml_clear_errors();
        return null;
    }
    libxml_clear_errors();

    return $source_xml;
}

function parse_feed_items(SimpleXMLElement $source_xml, string $feed_url, array &$skip_guids): array
{
    $source_xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
    $items = $source_xml->xpath('//item | //entry');
    if (!$items || empty($items)) {
        feed_parser_debug('No items via XPath for feed ' . $feed_url . '. Falling back to direct children.');
        if (isset($source_xml->channel) && isset($source_xml->channel->item)) {
            $items = $source_xml->channel->item;
        } elseif (isset($source_xml->item)) {
            $items = $source_xml->item;
        } elseif (isset($source_xml->entry)) {
            $items = $source_xml->entry;
        } else {
            $items = [];
        }
    }

    if (empty($items)) {
        feed_parser_debug('No items detected for feed ' . $feed_url . ' after fallbacks.');
    }

    $parsed_items = [];

    foreach ($items as $item) {
        $is_atom = stripos($item->getName(), 'entry') !== false;
        $link = resolve_item_link($item, $is_atom);
        $guid = build_item_guid($item, $is_atom, $link);

        if ($guid === '' || isset($skip_guids[$guid])) {
            continue;
        }

        $title = trim((string)$item->title);
        if ($title === '') {
            feed_parser_debug('Skipping item without title for feed ' . $feed_url . ' GUID ' . $guid);
            continue;
        }

        $pub_date = resolve_item_pub_date($item, $is_atom);
        $content = extract_item_content($item, $is_atom);
        $image = extract_item_image($item, $content);

        if ($image === '' && !$is_atom) {
            $media_ns = $item->children('media', true);
            if ($media_ns && isset($media_ns->content)) {
                foreach ($media_ns->content as $media_content) {
                    $attrs = $media_content->attributes();
                    if ($attrs && isset($attrs->url)) {
                        $image = (string)$attrs->url;
                        break;
                    }
                }
            }
        }

        $parsed_items[] = [
            'feed_url' => $feed_url,
            'guid' => $guid,
            'title' => $title,
            'content' => $content,
            'pubDate' => $pub_date,
            'link' => $link,
            'image' => $image,
        ];

        $skip_guids[$guid] = true;

        if ($image === '') {
            feed_parser_debug('No image candidate for feed ' . $feed_url . ' GUID ' . $guid);
        }
    }

    feed_parser_debug('Parsed ' . count($parsed_items) . ' items for feed ' . $feed_url . '.');

    return $parsed_items;
}

function resolve_item_link(SimpleXMLElement $item, bool $is_atom): string
{
    if ($is_atom) {
        foreach ($item->link as $link) {
            $rel = isset($link['rel']) ? (string)$link['rel'] : '';
            if ($rel === 'alternate' || $rel === '') {
                return (string)$link['href'];
            }
        }
        if (isset($item->link['href'])) {
            return (string)$item->link['href'];
        }
    }

    return (string)$item->link;
}

function build_item_guid(SimpleXMLElement $item, bool $is_atom, string $link): string
{
    $guid = '';
    if ($link !== '') {
        $guid = $link;
    } elseif ($is_atom && isset($item->id)) {
        $guid = (string)$item->id;
    } elseif (isset($item->guid) && trim((string)$item->guid) !== '') {
        $guid = (string)$item->guid;
    }

    $guid = trim($guid);
    if ($guid !== '') {
        return rtrim($guid, '/');
    }

    $title = (string)$item->title;
    $fallback_date = resolve_item_pub_date($item, $is_atom);
    return 'hash-' . md5($title . $fallback_date);
}

function resolve_item_pub_date(SimpleXMLElement $item, bool $is_atom): string
{
    if ($is_atom) {
        $updated = trim((string)$item->updated);
        if ($updated !== '') {
            return $updated;
        }
        $published = trim((string)$item->published);
        if ($published !== '') {
            return $published;
        }
    }

    return trim((string)$item->pubDate);
}

function extract_item_content(SimpleXMLElement $item, bool $is_atom): string
{
    if ($is_atom) {
        $content = (string)$item->content;
        if (trim($content) === '' && isset($item->summary)) {
            $content = (string)$item->summary;
        }
        return $content;
    }

    $namespaced_content = $item->children('content', true);
    if ($namespaced_content instanceof SimpleXMLElement && trim((string)$namespaced_content->encoded) !== '') {
        return (string)$namespaced_content->encoded;
    }

    if (isset($item->content_encoded) && trim((string)$item->content_encoded) !== '') {
        return (string)$item->content_encoded;
    }

    if (isset($item->content) && trim((string)$item->content) !== '') {
        return (string)$item->content;
    }

    return (string)$item->description;
}

function extract_item_image(SimpleXMLElement $item, string $content_html): string
{
    $candidates = [];

    if (isset($item->media_content)) {
        foreach ($item->media_content as $media_content) {
            $attrs = $media_content->attributes();
            if ($attrs && isset($attrs->url)) {
                $width = isset($attrs->width) ? (int)$attrs->width : 0;
                $height = isset($attrs->height) ? (int)$attrs->height : 0;
                $candidates[] = ['url' => (string)$attrs->url, 'score' => max($width, $height)];
            }
        }
    }

    if (empty($candidates) && isset($item->media_group)) {
        foreach ($item->media_group as $group) {
            if (isset($group->media_content)) {
                foreach ($group->media_content as $media_content) {
                    $attrs = $media_content->attributes();
                    if ($attrs && isset($attrs->url)) {
                        $width = isset($attrs->width) ? (int)$attrs->width : 0;
                        $height = isset($attrs->height) ? (int)$attrs->height : 0;
                        $candidates[] = ['url' => (string)$attrs->url, 'score' => max($width, $height)];
                    }
                }
            }
            if (isset($group->media_thumbnail)) {
                foreach ($group->media_thumbnail as $thumbnail) {
                    $attrs = $thumbnail->attributes();
                    if ($attrs && isset($attrs->url)) {
                        $width = isset($attrs->width) ? (int)$attrs->width : 0;
                        $height = isset($attrs->height) ? (int)$attrs->height : 0;
                        $candidates[] = ['url' => (string)$attrs->url, 'score' => max($width, $height)];
                    }
                }
            }
        }
    }

    if (empty($candidates) && isset($item->media_thumbnail)) {
        foreach ($item->media_thumbnail as $thumbnail) {
            $attrs = $thumbnail->attributes();
            if ($attrs && isset($attrs->url)) {
                $width = isset($attrs->width) ? (int)$attrs->width : 0;
                $height = isset($attrs->height) ? (int)$attrs->height : 0;
                $candidates[] = ['url' => (string)$attrs->url, 'score' => max($width, $height)];
            }
        }
    }

    if (empty($candidates) && isset($item->enclosure)) {
        foreach ($item->enclosure as $enclosure) {
            $attrs = $enclosure->attributes();
            if ($attrs && isset($attrs->type) && strpos((string)$attrs->type, 'image') !== false && isset($attrs->url)) {
                $candidates[] = ['url' => (string)$attrs->url, 'score' => 0];
            }
        }
    }

    if (empty($candidates) && isset($item->image) && trim((string)$item->image) !== '') {
        $candidates[] = ['url' => (string)$item->image, 'score' => 0];
    }

    if (empty($candidates)) {
        $html = trim($content_html);
        if ($html === '' && isset($item->description)) {
            $html = (string)$item->description;
        }
        if ($html !== '' && preg_match('/<img[^>]+src="([^"]+)"/i', $html, $matches)) {
            $candidates[] = ['url' => $matches[1], 'score' => 0];
        }
    }

    if (empty($candidates)) {
        return '';
    }

    usort($candidates, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return $candidates[0]['url'];
}
