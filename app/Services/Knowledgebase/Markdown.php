<?php

namespace App\Services\Knowledgebase;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;

/**
 * Article Markdown to safe HTML. GitHub-flavoured Markdown (tables, task lists, strikethrough, autolinks), heading
 * anchors, `> [!NOTE]` style callouts (note, tip, important, warning, caution) and plain HTML for the odd thing
 * Markdown cannot say. The HTML always goes through {@see HtmlSanitizer}.
 */
class Markdown
{
    public const CALLOUTS = ['note' => 'Note', 'tip' => 'Tip', 'important' => 'Important', 'warning' => 'Warning', 'caution' => 'Caution'];

    private ?MarkdownConverter $converter = null;

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    public function html(string $markdown): string
    {
        $html = $this->converter()->convert($markdown)->getContent();

        return $this->sanitizer->clean($html);
    }

    /** The h2 and h3 headings of rendered HTML, for the "On this page" list. @return array<int, array{level: int, id: string, text: string}> */
    public function toc(string $html): array
    {
        $out = [];
        if (preg_match_all('#<h([23])\s[^>]*id="([^"]+)"[^>]*>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $h) {
                $text = trim(html_entity_decode(strip_tags(preg_replace('#<a\b[^>]*class="heading-anchor"[^>]*>.*?</a>#is', '', $h[3]) ?? $h[3]), ENT_QUOTES | ENT_HTML5));
                if ($text !== '') {
                    $out[] = ['level' => (int) $h[1], 'id' => $h[2], 'text' => $text];
                }
            }
        }

        return $out;
    }

    /** Plain text of an article, for search results and a fallback summary. */
    public function text(string $markdown): string
    {
        $text = html_entity_decode(strip_tags($this->html($markdown)), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter) {
            return $this->converter;
        }
        $env = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 40,
            'heading_permalink' => ['html_class' => 'heading-anchor', 'id_prefix' => '', 'fragment_prefix' => '', 'apply_id_to_heading' => true, 'heading_class' => '', 'insert' => 'after', 'title' => 'Link to this section', 'symbol' => '#', 'aria_hidden' => true, 'min_heading_level' => 2, 'max_heading_level' => 4],
        ]);
        $env->addExtension(new CommonMarkCoreExtension);
        $env->addExtension(new GithubFlavoredMarkdownExtension);
        $env->addExtension(new AttributesExtension);
        $env->addExtension(new FrontMatterExtension);
        $env->addExtension(new HeadingPermalinkExtension);
        $env->addEventListener(DocumentParsedEvent::class, $this->markCallouts(...));

        return $this->converter = new MarkdownConverter($env);
    }

    /**
     * Turns a blockquote that starts with [!NOTE] (and friends) into a callout box. The marker may share its paragraph with
     * the text (`> [!NOTE]` then `> text`) or stand alone as the first paragraph, which is how the editor writes it.
     */
    private function markCallouts(DocumentParsedEvent $event): void
    {
        foreach ($event->getDocument()->iterator() as $node) {
            if (! $node instanceof BlockQuote) {
                continue;
            }
            $para = $node->firstChild();
            if (! $para instanceof Paragraph) {
                continue;
            }
            // the text of the first line (an editor may escape the brackets, which splits the text into several nodes)
            $line = '';
            $parts = [];
            for ($n = $para->firstChild(); $n !== null && ! $n instanceof Newline; $n = $n->next()) {
                if (! $n instanceof Text) {
                    $parts = [];

                    break;
                }
                $line .= $n->getLiteral();
                $parts[] = $n;
            }
            if ($parts === [] || ! preg_match('/^\s*\[!(note|tip|important|warning|caution)\]\s*$/i', $line, $m)) {
                continue;
            }
            $kind = strtolower($m[1]);
            $newline = end($parts)->next();
            foreach ($parts as $part) {
                $part->detach();
            }
            if ($newline instanceof Newline) {
                $newline->detach();
            }
            if ($para->firstChild() === null) {
                $para->detach();
            }
            $node->data->set('attributes', ['class' => 'callout callout-'.$kind, 'data-label' => self::CALLOUTS[$kind]]);
        }
    }
}
