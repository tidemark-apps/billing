<?php

namespace App\Services\Knowledgebase;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans the HTML produced from article Markdown. Authors may use plain HTML inside a Markdown article (keyboard keys,
 * collapsible sections, tables with alignment ...), so this keeps a fixed list of harmless tags and attributes and
 * removes everything else: scripts, frames, forms, event handlers and links that run code.
 */
class HtmlSanitizer
{
    private const TAGS = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'caption', 'code', 'dd', 'del', 'details', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'input', 'ins', 'kbd', 'li', 'mark', 'ol', 'p', 'pre', 'q', 's', 'samp',
        'small', 'span', 'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    /** Removed along with everything inside them (others are unwrapped and their text kept). */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'button', 'select', 'textarea', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'title'];

    private const GLOBAL_ATTRS = ['id', 'class', 'title', 'lang', 'dir', 'data-label'];

    private const ATTRS = [
        'a' => ['href', 'rel', 'target'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'td' => ['align', 'colspan', 'rowspan'],
        'th' => ['align', 'colspan', 'rowspan', 'scope'],
        'ol' => ['start', 'type'],
        'details' => ['open'],
        'input' => ['type', 'checked', 'disabled'],
        'abbr' => [],
    ];

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div id="kb-root">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('kb-root') ?? $doc->documentElement;
        $this->walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $name = strtolower($child->tagName);
                if (in_array($name, self::DROP, true)) {
                    $node->removeChild($child);

                    continue;
                }
                if (! in_array($name, self::TAGS, true)) {
                    $this->walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }
                $this->cleanAttributes($child, $name);
                $this->walk($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private function cleanAttributes(DOMElement $el, string $name): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRS, self::ATTRS[$name] ?? []);
        foreach (iterator_to_array($el->attributes) as $attr) {
            $attrName = strtolower($attr->name);
            if (! in_array($attrName, $allowed, true)) {
                $el->removeAttribute($attr->name);

                continue;
            }
            $value = $attr->value;
            if (in_array($attrName, ['href', 'src'], true) && ! $this->safeUrl($value, $attrName === 'src')) {
                $el->removeAttribute($attr->name);
            } elseif (in_array($attrName, ['class', 'data-label'], true) && ! preg_match('/^[\w\- :]*$/', $value)) {
                $el->removeAttribute($attr->name);
            } elseif ($attrName === 'id' && ! preg_match('/^[\w\-:.]+$/', $value)) {
                $el->removeAttribute($attr->name);
            }
        }
        if ($name === 'input') {
            if (strtolower($el->getAttribute('type')) !== 'checkbox') {
                $el->parentNode?->removeChild($el);

                return;
            }
            $el->setAttribute('disabled', 'disabled');
        }
        if ($name === 'a' && $el->hasAttribute('href') && preg_match('#^https?://#i', $el->getAttribute('href'))) {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
        if ($name === 'img' && ! $el->hasAttribute('loading')) {
            $el->setAttribute('loading', 'lazy');
        }
    }

    private function safeUrl(string $url, bool $image): bool
    {
        $url = trim(preg_replace('/[\x00-\x20]+/', '', $url) ?? '');
        if ($url === '') {
            return false;
        }
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $m)) {
            $scheme = strtolower($m[1]);

            return $image ? in_array($scheme, ['http', 'https'], true) : in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
        }

        return true; // relative links and #anchors
    }
}
