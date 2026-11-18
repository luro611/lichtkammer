<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

/**
 * Prüfung und Bereinigung von SVG (Logos und Medien, eine gemeinsame Klasse). Positivliste, keine Sperrliste:
 * Nur bekannte Grafik-Elemente und -Attribute kommen durch, geprüft nach Namensraum und Name (nicht nach Präfix).
 * Aktive Inhalte (Skripte, Ereignisse, fremde Verweise, eingebettete Bilder und Seiten) führen zur Ablehnung.
 * Editor-Metadaten (Inkscape, Illustrator …) werden entfernt. Gespeichert wird nur die bereinigte Fassung, die noch einmal
 * durch dieselbe Prüfung läuft. SVG wird nie von Imagick oder GD gelesen.
 */
final class SvgCheck
{
    public const NS_SVG = 'http://www.w3.org/2000/svg';
    private const NS_XLINK = 'http://www.w3.org/1999/xlink';
    private const NS_XML = 'http://www.w3.org/XML/1998/namespace';
    private const NS_XMLNS = 'http://www.w3.org/2000/xmlns/';
    /** Namensräume bekannter Editoren: Elemente und Attribute werden still entfernt. */
    private const EDITOR_NS = [
        'http://www.inkscape.org/namespaces/inkscape', 'http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd',
        'http://ns.adobe.com/AdobeIllustrator/10.0/', 'http://ns.adobe.com/Extensibility/1.0/', 'http://ns.adobe.com/AdobeSVGViewerExtensions/3.0/',
        'http://ns.adobe.com/Variables/1.0/', 'http://ns.adobe.com/Flows/1.0/', 'http://ns.adobe.com/GenericCustomNamespace/1.0/',
        'http://ns.adobe.com/XPath/1.0/', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'http://purl.org/dc/elements/1.1/',
        'http://creativecommons.org/ns#', 'http://www.bohemiancoding.com/sketch/ns', 'http://ns.adobe.com/xap/1.0/',
    ];
    public const MAX_ELEMENTS = 20000;
    public const MAX_DEPTH = 50;

    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textPath',
        'title', 'desc', 'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask', 'style', 'marker',
        'filter', 'feBlend', 'feColorMatrix', 'feComposite', 'feFlood', 'feGaussianBlur', 'feMerge', 'feMergeNode', 'feOffset', 'feDropShadow', 'feMorphology',
    ];
    private const ATTRS = [
        'id', 'class', 'style', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr', 'width', 'height', 'd', 'points', 'transform',
        'viewBox', 'preserveAspectRatio', 'version', 'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap',
        'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'color', 'display', 'visibility', 'clip-path', 'clip-rule',
        'mask', 'filter', 'font-family', 'font-size', 'font-style', 'font-weight', 'font-variant', 'font-stretch', 'text-anchor', 'text-decoration',
        'letter-spacing', 'word-spacing', 'dominant-baseline', 'alignment-baseline', 'baseline-shift', 'dx', 'dy', 'rotate', 'textLength', 'lengthAdjust',
        'startOffset', 'method', 'spacing', 'gradientUnits', 'gradientTransform', 'spreadMethod', 'offset', 'stop-color', 'stop-opacity', 'patternUnits',
        'patternContentUnits', 'patternTransform', 'clipPathUnits', 'maskUnits', 'maskContentUnits', 'markerUnits', 'markerWidth', 'markerHeight', 'refX', 'refY',
        'orient', 'marker-start', 'marker-mid', 'marker-end', 'vector-effect', 'paint-order', 'mix-blend-mode', 'isolation', 'shape-rendering', 'text-rendering',
        'image-rendering', 'color-interpolation', 'color-interpolation-filters', 'filterUnits', 'primitiveUnits', 'in', 'in2', 'result', 'stdDeviation', 'mode',
        'type', 'values', 'operator', 'k1', 'k2', 'k3', 'k4', 'flood-color', 'flood-opacity', 'radius', 'xmlns', 'space', 'lang', 'role', 'aria-label', 'aria-hidden',
        'aria-labelledby', 'aria-describedby', 'focusable', 'xml:space', 'dx', 'dy', 'writing-mode', 'direction', 'unicode-bidi', 'white-space',
    ];
    private const CSS_PROPS = [
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray',
        'stroke-dashoffset', 'opacity', 'color', 'display', 'visibility', 'clip-path', 'clip-rule', 'mask', 'filter', 'font-family', 'font-size', 'font-style',
        'font-weight', 'font-variant', 'font-stretch', 'font', 'text-anchor', 'text-decoration', 'letter-spacing', 'word-spacing', 'line-height', 'dominant-baseline',
        'baseline-shift', 'stop-color', 'stop-opacity', 'transform', 'transform-origin', 'marker', 'marker-start', 'marker-mid', 'marker-end', 'vector-effect', 'paint-order',
        'mix-blend-mode', 'isolation', 'shape-rendering', 'text-rendering', 'image-rendering', 'flood-color', 'flood-opacity', 'writing-mode', 'direction', 'white-space',
    ];

    /**
     * Prüft und bereinigt. Gibt die bereinigte SVG-Datei zurück.
     *
     * @return array{svg:string,notes:list<string>} notes: z. B. „Editor-Metadaten wurden entfernt“
     * @throws MediaException
     */
    public static function sanitize(string $svg): array
    {
        $first = self::run($svg);
        $second = self::run($first['svg']); // Rundlauf: das Ergebnis muss die Prüfung unverändert bestehen
        if ($second['svg'] !== $first['svg']) {
            throw self::bad();
        }
        return $first;
    }

    private static function bad(): MediaException
    {
        return new MediaException('Diese SVG-Datei enthält Skripte, Verweise nach außen oder eingebettete Inhalte und wird nicht angenommen. Bitte eine einfache SVG-Datei verwenden (nur Formen und Text).');
    }

    /** @return array{svg:string,notes:list<string>} */
    private static function run(string $svg): array
    {
        if (str_contains($svg, "\0") || str_starts_with($svg, "\xFF\xFE") || str_starts_with($svg, "\xFE\xFF")) { // UTF-16 und Verwandte
            throw self::bad();
        }
        if (str_starts_with($svg, "\xEF\xBB\xBF")) {
            $svg = substr($svg, 3);
        }
        if (!mb_check_encoding($svg, 'UTF-8')) {
            throw self::bad();
        }
        if (preg_match('/^\s*<\?xml[^>]*encoding\s*=\s*["\']?\s*([A-Za-z0-9_.:-]+)/i', $svg, $m) === 1 && strtolower($m[1]) !== 'utf-8') {
            throw self::bad();
        }
        if (stripos($svg, '<!ENTITY') !== false) { // zusätzlich zur Prüfung des DOCTYPE nach dem Lesen
            throw self::bad();
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); // kein NOENT, kein DTDLOAD, kein PARSEHUGE
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->documentElement;
        if (!$ok || $root === null) {
            throw new MediaException('Das ist keine gültige SVG-Datei.');
        }
        if ($root->localName !== 'svg' || $root->namespaceURI !== self::NS_SVG) {
            throw new MediaException('Das ist keine gültige SVG-Datei.');
        }
        $dt = $doc->doctype;
        if ($dt !== null) {
            $okDt = in_array((string) $dt->publicId, ['-//W3C//DTD SVG 1.1//EN', '-//W3C//DTD SVG 1.0//EN'], true)
                && str_starts_with((string) $dt->systemId, 'http://www.w3.org/') && (string) $dt->internalSubset === '' && $dt->name === 'svg';
            if (!$okDt) {
                throw self::bad();
            }
        }
        $state = ['count' => 0, 'notes' => []];
        self::walk($root, 1, $state);
        $xml = (string) $doc->saveXML($root);
        foreach (self::EDITOR_NS as $ns) { // übrig gebliebene Namensraum-Erklärungen von Editoren entfernen
            $xml = preg_replace('/\sxmlns(?::[A-Za-z0-9_.-]+)?="' . preg_quote($ns, '/') . '"/', '', $xml) ?? $xml;
        }
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml;
        return ['svg' => $out, 'notes' => array_values(array_unique($state['notes']))];
    }

    /** @param array{count:int,notes:list<string>} $st */
    private static function walk(\DOMElement $el, int $depth, array &$st): void
    {
        if ($depth > self::MAX_DEPTH || ++$st['count'] > self::MAX_ELEMENTS) {
            throw self::bad();
        }
        // Attribute zuerst (Kopie der Liste, da gelöscht wird)
        foreach (iterator_to_array($el->attributes) as $a) {
            $ns = (string) $a->namespaceURI;
            $local = $a->localName;
            if ($ns === self::NS_XMLNS) { // Namensraum-Erklärungen: nur SVG und xlink bleiben, Editor-Erklärungen entfallen
                if (!in_array($a->nodeValue, [self::NS_SVG, self::NS_XLINK], true)) {
                    $el->removeAttributeNode($a);
                    if (in_array($a->nodeValue, self::EDITOR_NS, true)) {
                        $st['notes'][] = 'Editor-Metadaten wurden entfernt.';
                    }
                }
                continue;
            }
            if ($ns !== '' && $ns !== self::NS_XLINK && $ns !== self::NS_XML) {
                if (in_array($ns, self::EDITOR_NS, true)) {
                    $el->removeAttributeNode($a);
                    $st['notes'][] = 'Editor-Metadaten wurden entfernt.';
                    continue;
                }
                throw self::bad(); // Attribut aus fremdem Namensraum
            }
            $lname = strtolower($local);
            if (str_starts_with($lname, 'on')) {
                throw self::bad();
            }
            $value = $a->nodeValue;
            if ($lname === 'href') { // href und xlink:href: nur #id
                if ($ns !== '' && $ns !== self::NS_XLINK || !str_starts_with(trim($value), '#') || preg_match('/^#[A-Za-z0-9_.:-]+$/', trim($value)) !== 1) {
                    throw self::bad();
                }
                continue;
            }
            if ($ns === self::NS_XML) {
                if (!in_array($local, ['space', 'lang'], true)) {
                    throw self::bad();
                }
                continue;
            }
            if ($ns === self::NS_XLINK) { // xlink:type, xlink:show usw. ohne Nutzen
                $el->removeAttributeNode($a);
                continue;
            }
            if ($lname === 'src' || $lname === 'data' || $lname === 'action' || $lname === 'formaction') {
                throw self::bad();
            }
            if (!in_array($local, self::ATTRS, true)) { // Unbekanntes wird nicht gespeichert (Positivliste)
                $el->removeAttributeNode($a);
                continue;
            }
            if ($local === 'style') {
                $css = self::cleanDeclarations($value);
                $css === '' ? $el->removeAttribute('style') : $el->setAttribute('style', $css);
                continue;
            }
            self::checkValue($value);
        }
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $el->removeChild($child);
                continue;
            }
            if ($child instanceof \DOMEntityReference) {
                throw self::bad();
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $cns = (string) $child->namespaceURI;
            if (in_array($cns, self::EDITOR_NS, true) || ($cns === self::NS_SVG && $child->localName === 'metadata') || $cns === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#') {
                $el->removeChild($child);
                $st['notes'][] = 'Editor-Metadaten wurden entfernt.';
                continue;
            }
            if (in_array($cns, ['http://ns.adobe.com/AdobeIllustrator/10.0/', 'http://ns.adobe.com/Extensibility/1.0/'], true)) {
                $el->removeChild($child);
                continue;
            }
            if ($cns !== self::NS_SVG || !in_array($child->localName, self::ELEMENTS, true)) {
                throw self::bad();
            }
            if ($child->localName === 'style') {
                self::cleanStyleElement($child);
            }
            self::walk($child, $depth + 1, $st);
        }
    }

    /** Werte dürfen keine aktiven Muster tragen. url(#id) ist erlaubt, alles andere nicht. */
    private static function checkValue(string $v): void
    {
        $s = preg_replace('/\s+/', '', strtolower($v)) ?? '';
        if (preg_match('~(javascript:|vbscript:|data:|https?:|//|@import|expression\(|behavior:|-moz-binding|\\\\)~', $s) === 1) {
            throw self::bad();
        }
        if (preg_match_all('/url\(([^)]*)\)/i', $v, $m) > 0) {
            foreach ($m[1] as $u) {
                if (preg_match('/^\s*[\'"]?#[A-Za-z0-9_.:-]+[\'"]?\s*$/', $u) !== 1) {
                    throw self::bad();
                }
            }
        }
    }

    /** Deklarationen „eigenschaft:wert;…“: nur Eigenschaften der Liste, Werte ohne aktive Muster. */
    private static function cleanDeclarations(string $css): string
    {
        $out = [];
        foreach (explode(';', $css) as $decl) {
            if (trim($decl) === '') {
                continue;
            }
            $pos = strpos($decl, ':');
            if ($pos === false) {
                throw self::bad();
            }
            $prop = strtolower(trim(substr($decl, 0, $pos)));
            $val = trim(substr($decl, $pos + 1));
            self::checkValue($val);
            if (str_contains($val, '/*') || str_contains($val, '{') || str_contains($val, '}')) {
                throw self::bad();
            }
            if (in_array($prop, self::CSS_PROPS, true)) {
                $out[] = $prop . ':' . $val;
            } elseif (!preg_match('/^-?[a-z][a-z-]*$/', $prop) || str_contains($prop, 'binding') || $prop === 'behavior') {
                throw self::bad();
            } // sonstige harmlose Eigenschaften werden nicht gespeichert
        }
        return implode(';', $out);
    }

    /** <style>: nur Regeln „Auswahl{Deklarationen}“, kein @-Konstrukt. */
    private static function cleanStyleElement(\DOMElement $style): void
    {
        $css = $style->textContent;
        if (str_contains($css, '@') || str_contains($css, '\\') || str_contains($css, '<')) {
            throw self::bad();
        }
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? '';
        $rules = [];
        $rest = trim($css);
        while ($rest !== '') {
            if (preg_match('/^([A-Za-z0-9_.#:,\s>*+~\[\]=-]+)\{([^{}]*)\}\s*/s', $rest, $m) !== 1) {
                throw self::bad();
            }
            $decl = self::cleanDeclarations($m[2]);
            if ($decl !== '') {
                $rules[] = trim(preg_replace('/\s+/', ' ', $m[1]) ?? '') . '{' . $decl . '}';
            }
            $rest = substr($rest, strlen($m[0]));
        }
        while ($style->firstChild !== null) {
            $style->removeChild($style->firstChild);
        }
        if ($rules !== []) {
            $style->appendChild($style->ownerDocument->createTextNode(implode("\n", $rules)));
        }
    }
}
