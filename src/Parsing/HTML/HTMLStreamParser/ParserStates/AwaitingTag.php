<?php


namespace Kibo\Phast\Parsing\HTML\HTMLStreamParser\ParserStates;


use Kibo\Phast\Parsing\HTML\HTMLStreamElements\ClosingTag;
use Kibo\Phast\Parsing\HTML\HTMLStreamElements\OpeningTag;
use Kibo\Phast\Parsing\HTML\HTMLStreamParser\ParserState;
use Masterminds\HTML5\Elements;

class AwaitingTag extends ParserState {

    public function startTag($name, $attributes, $startOffset, $endOffset, $selfClosing = false) {
        $tag = new OpeningTag($startOffset, $endOffset, $name, $attributes);
        if ($name == 'script' || $name == 'style') {
            $newState = new ConstructingTextContainingTag($this->parser, $tag);
            $this->parser->setState($newState);
        } else {
            $this->parser->getStream()->addElement($tag);
        }
        return Elements::element($name);
    }

    public function endTag($name, $startOffset, $endOffset) {
        $tag = new ClosingTag($startOffset, $endOffset, $name);
        $this->parser->getStream()->addElement($tag);
    }

}
