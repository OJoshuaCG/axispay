<?php

declare(strict_types=1);

namespace App\Modules\Legal\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use Stringable;

/**
 * Renders a legal text written in the panels (ADR-0056) to safe HTML: simple
 * Markdown only (paragraphs, headings, emphasis, lists, links, quotes).
 *
 *  - raw HTML is stripped, never passed through;
 *  - unsafe links (javascript:, vbscript:, file:, data: other than images)
 *    are dropped by CommonMark itself;
 *  - every web link opens in a new tab with rel="noopener noreferrer";
 *  - images are shown as their text alternative (the pay host loads images
 *    from itself only, and a legal text needs none);
 *  - headings start at `$topLevel`, below the heading of the page or dialog
 *    that shows the text (a Markdown "#" becomes that level).
 *
 * The HTML is cached by the text's hash for a day, so a new or changed text
 * needs no invalidation.
 */
final readonly class LegalMarkdown
{
    private const string CACHE_PREFIX = 'legal:markdown:v1:';

    private const int CACHE_SECONDS = 86_400;

    public function __construct(private Repository $cache) {}

    public function render(string $markdown, int $topLevel = 3): HtmlString
    {
        $topLevel = max(1, min(6, $topLevel));
        $key = self::CACHE_PREFIX.$topLevel.':'.hash('sha256', $markdown);

        /** @var mixed $html */
        $html = $this->cache->remember($key, self::CACHE_SECONDS, static fn (): string => self::convert($markdown, $topLevel));

        return new HtmlString(is_string($html) ? $html : self::convert($markdown, $topLevel));
    }

    private static function convert(string $markdown, int $topLevel): string
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'external_link' => [
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new AutolinkExtension);
        $environment->addExtension(new ExternalLinkExtension);
        $environment->addRenderer(Heading::class, self::headingRenderer($topLevel), 10);
        $environment->addRenderer(Image::class, self::imageRenderer(), 10);

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }

    private static function headingRenderer(int $topLevel): NodeRendererInterface
    {
        return new class($topLevel) implements NodeRendererInterface
        {
            public function __construct(private readonly int $topLevel) {}

            public function render(Node $node, ChildNodeRendererInterface $childRenderer): Stringable
            {
                $level = $node instanceof Heading ? $node->getLevel() : 1;

                return new HtmlElement('h'.min(6, $level + $this->topLevel - 1), [], $childRenderer->renderNodes($node->children()));
            }
        };
    }

    private static function imageRenderer(): NodeRendererInterface
    {
        return new class implements NodeRendererInterface
        {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                return $childRenderer->renderNodes($node->children());
            }
        };
    }
}
