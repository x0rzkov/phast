<?php

namespace Kibo\Phast\Filters\HTML;

use Kibo\Phast\Filters\HTML\Helpers\BodyFinderTrait;

class IFrameDelayedLoadingHTMLFilter implements HTMLFilter {
    use BodyFinderTrait;

    private $script = <<<EOS
window.addEventListener('load', function() {
    window.setTimeout(function() {
        Array.prototype.forEach.call(
            window.document.querySelectorAll('iframe[data-phast-src]'),
            function(el) {
                el.setAttribute('src', el.getAttribute('data-phast-src'));
                el.removeAttribute('data-phast-src');
            }
        );
    }, 30);
});
EOS;

    public function transformHTMLDOM(\DOMDocument $document) {
        $addScript = false;
        foreach ($document->getElementsByTagName('iframe') as $iframe) {
            /** @var \DOMElement $iframe */
            if (!$iframe->hasAttribute('src')) {
                continue;
            }
            $iframe->setAttribute('data-phast-src', $iframe->getAttribute('src'));
            $iframe->setAttribute('src', 'about:blank');
            $addScript = true;
        }
        if ($addScript) {
            $script = $document->createElement('script');
            $script->textContent = preg_replace('/\s+/', '', $this->script);
            $this->getBodyElement($document)->appendChild($script);
        }
    }

}
