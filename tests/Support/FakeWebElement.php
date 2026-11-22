<?php

declare(strict_types=1);

namespace Tests\Support;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\Exception\NoSuchElementException;

/**
 * In-memory stand-in for a page element, so article extractors can be tested without a browser.
 *
 * `$children` maps a locator value (the CSS selector or XPath string the extractor passes to `WebDriverBy`) to the
 * element or list of elements it finds. A locator that is not listed finds nothing.
 */
final class FakeWebElement extends RemoteWebElement
{
    /**
     * @param array<string, string> $attributes
     * @param array<string, self|list<self>> $children
     */
    public function __construct(
        private readonly string $tag = 'div',
        private readonly array $attributes = [],
        private readonly string $text = '',
        private readonly string $outerHtml = '',
        private readonly array $children = [],
    ) {
        // No browser session behind this element.
    }

    public function findElement(WebDriverBy $by)
    {
        return $this->findElements($by)[0] ?? throw new NoSuchElementException('No element for ' . $by->getValue());
    }

    public function findElements(WebDriverBy $by)
    {
        $found = $this->children[$by->getValue()] ?? [];

        return is_array($found) ? $found : [$found];
    }

    public function getAttribute($attribute_name)
    {
        return $this->attributes[$attribute_name] ?? null;
    }

    public function getDomProperty($propertyName)
    {
        return $propertyName === 'outerHTML' ? $this->outerHtml : ($this->attributes[$propertyName] ?? null);
    }

    public function getTagName()
    {
        return $this->tag;
    }

    public function getText()
    {
        return $this->text;
    }
}
