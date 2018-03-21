<?php

namespace Kibo\Phast\Filters\HTML\CSSInlining;

use Kibo\Phast\Filters\HTML\BaseHTMLStreamFilter;
use Kibo\Phast\Logging\LoggingTrait;
use Kibo\Phast\Parsing\HTML\HTMLStreamElements\Tag;
use Kibo\Phast\Retrievers\Retriever;
use Kibo\Phast\Security\ServiceSignature;
use Kibo\Phast\Services\Bundler\ServiceParams;
use Kibo\Phast\Services\ServiceFilter;
use Kibo\Phast\ValueObjects\PhastJavaScript;
use Kibo\Phast\ValueObjects\Resource;
use Kibo\Phast\ValueObjects\URL;

class Filter extends BaseHTMLStreamFilter {
    use LoggingTrait;

    const CSS_IMPORTS_REGEXP = '~
        @import \s++
        ( url \( )?+                # url() is optional
        ( (?(1) ["\']?+ | ["\'] ) ) # without url() a quote is necessary
        \s*+ (?<url>[A-Za-z0-9_/.:?&=+%,-]++) \s*+
        \2                          # match ending quote
        (?(1)\))                    # match closing paren if url( was used
        \s*+ ;
    ~xi';

    /**
     * @var ServiceSignature
     */
    private $signature;

    /**
     * @var bool
     */
    private $withIEFallback = false;

    /**
     * @var bool
     */
    private $hasDoneInlining = false;

    /**
     * @var int
     */
    private $maxInlineDepth = 2;

    /**
     * @var URL
     */
    private $baseURL;

    /**
     * @var string[]
     */
    private $whitelist = [];

    /**
     * @var string
     */
    private $serviceUrl;

    /**
     * @var int
     */
    private $optimizerSizeDiffThreshold;

    /**
     * @var Retriever
     */
    private $retriever;

    /**
     * @var OptimizerFactory
     */
    private $optimizerFactory;

    /**
     * @var ServiceFilter
     */
    private $cssFilter;

    /**
     * @var Optimizer
     */
    private $optimizer;

    public function __construct(
        ServiceSignature $signature,
        URL $baseURL,
        array $config,
        Retriever $retriever,
        OptimizerFactory $optimizerFactory,
        ServiceFilter $cssFilter
    ) {
        $this->signature = $signature;
        $this->baseURL = $baseURL;
        $this->serviceUrl = URL::fromString((string)$config['serviceUrl']);
        $this->optimizerSizeDiffThreshold = (int)$config['optimizerSizeDiffThreshold'];
        $this->retriever = $retriever;
        $this->optimizerFactory = $optimizerFactory;
        $this->cssFilter = $cssFilter;

        foreach ($config['whitelist'] as $key => $value) {
            if (!is_array($value)) {
                $this->whitelist[$value] = ['ieCompatible' => true];
                $key = $value;
            } else {
                $this->whitelist[$key] = $value;
            }
            if (!isset ($this->whitelist[$key]['ieCompatible'])) {
                $this->whitelist[$key] = true;
            }
        }
    }

    protected function beforeLoop() {
        $this->elements = iterator_to_array($this->elements);
        $this->optimizer = $this->optimizerFactory->makeForElements(new \ArrayIterator($this->elements));
    }

    protected function isTagOfInterest(Tag $tag) {
        return $tag->getTagName() == 'style'
               || (
                  $tag->getTagName() == 'link'
                  && $tag->getAttribute('rel') == 'stylesheet'
                  && $tag->hasAttribute('href')
               );
    }

    protected function handleTag(Tag $tag) {
        if ($tag->getTagName() == 'link') {
            return $this->inlineLink($tag, $this->context->getBaseUrl());
        }
        return $this->inlineStyle($tag);
    }

    protected function afterLoop() {
        if ($this->withIEFallback) {
            $this->addIEFallbackScript();
        }
        if ($this->hasDoneInlining) {
            $this->addInlinedRetrieverScript();
        }
    }

    private function inlineLink(Tag $link, URL $baseUrl) {
        $location = URL::fromString(trim($link->getAttribute('href')))->withBase($baseUrl);

        if (!$this->findInWhitelist($location)) {
            return [$link];
        }

        $elements = $this->inlineURL($location, $link->getAttribute('media'));
        return is_null($elements) ? [$link] : $elements;
    }

    private function inlineStyle(Tag $style) {
        $processed = $this->cssFilter
            ->apply(Resource::makeWithContent($this->baseURL, $style->textContent), [])
            ->getContent();
        $elements = $this->inlineCSS(
            $this->baseURL,
            $processed,
            $style->getAttribute('media'),
            false
        );
        return $elements;
    }

    private function findInWhitelist(URL $url) {
        $stringUrl = (string)$url;
        foreach ($this->whitelist as $pattern => $settings) {
            if (preg_match($pattern, $stringUrl)) {
                return $settings;
            }
        }
        return false;
    }

    /**
     * @param URL $url
     * @param string $media
     * @param boolean $ieCompatible
     * @param int $currentLevel
     * @param string[] $seen
     * @return Tag[]
     */
    private function inlineURL(URL $url, $media, $ieCompatible = true, $currentLevel = 0, $seen = []) {
        $whitelistEntry = $this->findInWhitelist($url);

        if (!$whitelistEntry) {
            $this->logger()->info('Not inlining {url}. Not in whitelist', ['url' => ($url)]);
            return [$this->makeLink($url, $media)];
        }

        if (!$whitelistEntry['ieCompatible']) {
            $ieFallbackUrl = $ieCompatible ? $url : null;
            $ieCompatible = false;
        } else {
            $ieFallbackUrl = null;
        }

        if (in_array($url, $seen)) {
            return [];
        }

        if ($currentLevel > $this->maxInlineDepth) {
            return $this->addIEFallback($ieFallbackUrl, [$this->makeLink($url, $media)]);
        }

        $seen[] = $url;

        $this->logger()->info('Inlining {url}.', ['url' => (string)$url]);
        $content = $this->retriever->retrieve($url);
        if ($content === false) {
            $this->logger()->error('Could not get contents for {url}', ['url' => (string)$url]);
            return $this->addIEFallback(
                $ieFallbackUrl,
                [$this->makeStyle($url, '', $media, true, false)]
            );
        }


        $content = $this->cssFilter->apply(Resource::makeWithContent($url, $content), [])
            ->getContent();
        $optimized = $this->optimizer->optimizeCSS($content);
        if (is_null($optimized)) {
            return null;
        }
        $isOptimized = false;
        if (strlen($content) - strlen($optimized) > $this->optimizerSizeDiffThreshold) {
            $content = $optimized;
            $isOptimized = true;
        }
        $this->hasDoneInlining = true;
        $elements = $this->inlineCSS(
            $url,
            $content,
            $media,
            $isOptimized,
            $ieCompatible,
            $currentLevel,
            $seen
        );
        $this->addIEFallback($ieFallbackUrl, $elements);
        return $elements;
    }

    private function inlineCSS(
        URL $url,
        $content,
        $media,
        $optimized,
        $ieCompatible = true,
        $currentLevel = 0,
        $seen = []
    ) {

        $urlMatches = $this->getImportedURLs($content);
        $elements = [];
        foreach ($urlMatches as $match) {
            $content = str_replace($match[0], '', $content);
            $matchedUrl = URL::fromString($match['url'])->withBase($url);
            $replacement = $this->inlineURL($matchedUrl, $media, $ieCompatible, $currentLevel + 1, $seen);
            $elements = array_merge($elements, $replacement);
        }

        $elements[] = $this->makeStyle($url, $content, $media, $optimized);

        return $elements;
    }

    private function addIEFallback(URL $fallbackUrl = null, array $elements = null) {
        if ($fallbackUrl === null || !$elements) {
            return $elements;
        }

        foreach ($elements as $element) {
            $element->setAttribute('data-phast-nested-inlined', '');
        }

        $element->setAttribute('data-phast-ie-fallback-url', (string)$fallbackUrl);
        $element->removeAttribute('data-phast-nested-inlined');

        $this->logger()->info('Set {url} as IE fallback URL', ['url' => (string)$fallbackUrl]);

        $this->withIEFallback = true;

        return $elements;
    }

    private function addIEFallbackScript() {
        $this->logger()->info('Adding IE fallback script');
        $this->withIEFallback = false;
        $this->context->addPhastJavaScript(new PhastJavaScript(__DIR__ . '/ie-fallback.js'));
    }

    private function addInlinedRetrieverScript() {
        $this->logger()->info('Adding inlined retriever script');
        $this->hasDoneInlining = false;
        $this->context->addPhastJavascript(new PhastJavaScript(__DIR__ . '/resources-loader.js'));
        $script = new PhastJavaScript(__DIR__ . '/inlined-css-retriever.js');
        $script->setConfig('serviceUrl', (string) $this->serviceUrl);
        $this->context->addPhastJavaScript($script);
    }

    private function getImportedURLs($cssContent) {
        preg_match_all(self::CSS_IMPORTS_REGEXP,
            $cssContent,
            $matches,
            PREG_SET_ORDER
        );
        return $matches;
    }

    private function makeStyle(URL $url, $content, $media, $optimized, $stripImports = true) {
        $style = new Tag('style');
        if ($media !== '') {
            $style->setAttribute('media', $media);
        }
        if ($optimized) {
            $style->setAttribute('data-phast-params', $this->makeServiceParams($url, $stripImports));
        }
        $style->setTextContent($content);
        return $style;
    }

    private function makeLink(URL $url, $media) {
        $link = new Tag('link', ['rel' => 'stylesheet', 'href' => (string) $url]);
        if ($media !== '') {
            $link->setAttribute('media', $media);
        }
        return $link;
    }

    protected function makeServiceParams(URL $originalLocation, $stripImports = false) {
        $params = [
            'src' => (string) $originalLocation,
            'cacheMarker' => $this->retriever->getCacheSalt($originalLocation)
        ];
        if ($stripImports) {
            $params['strip-imports'] = 1;
        }
        return ServiceParams::fromArray($params)
                ->sign($this->signature)
                ->serialize();
    }
}
