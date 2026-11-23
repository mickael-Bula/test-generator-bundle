<?php

declare(strict_types=1);

namespace Mika\TestGeneratorBundle\Functional\Probe;

use Mika\TestGeneratorBundle\Functional\Dto\ScanResult;
use Mika\TestGeneratorBundle\Functional\Exception\ProbeException;

/**
 * Balayage du DOM : renvoie les éléments interactifs visibles avec de quoi les identifier
 * (libellé associé, texte voisin, placeholder, aria-label, titre du panneau qu'un bouton ouvre…)
 * et un sélecteur CSS unique pour chacun.
 *
 * Le script s'exécute dans la page (Page::evaluate) et ne modifie rien.
 */
final class DomScanner
{
    public const SCRIPT = <<<'JS'
() => {
  const PRIVATE = /[-]|[\u{F0000}-\u{FFFFD}]|[\u{100000}-\u{10FFFD}]/gu;
  const CONTROLS = 'input, select, textarea, button';
  const INTERACTIVE = 'a[href], button, input:not([type="hidden"]), select, textarea, summary, '
    + '[role="button"], [role="tab"], [role="menuitem"], [role="link"], [role="checkbox"], [role="switch"], [role="radio"], [onclick]';
  const PANELS = '[role="dialog"], [aria-modal="true"], dialog, .modal, .offcanvas';
  const MAX_ELEMENTS = 300;

  const clean = (s) => (s || '').replace(PRIVATE, ' ').replace(/\s+/g, ' ').trim();
  const attr = (el, name) => {
    const v = el.getAttribute(name);
    return v === null || v === '' ? null : v;
  };
  const q = (v) => '"' + String(v).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
  const isVisible = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width <= 0 || r.height <= 0) { return false; }
    const s = getComputedStyle(el);
    return s.visibility !== 'hidden' && s.display !== 'none';
  };
  const unique = (scope, selector, el) => {
    try {
      const found = scope.querySelectorAll(selector);
      return found.length === 1 && found[0] === el;
    } catch (e) {
      return false;
    }
  };

  // Texte voisin : on remonte jusqu'à 3 niveaux, on s'arrête au premier groupe qui contient des contrôles.
  const textOf = (node) => {
    if (node.nodeType === 3) { return { text: clean(node.textContent), stop: false }; }
    if (node.nodeType !== 1) { return { text: '', stop: false }; }
    if (node.matches(CONTROLS) || node.querySelector(CONTROLS)) { return { text: '', stop: true }; }
    if (!isVisible(node)) { return { text: '', stop: false }; }
    return { text: clean(node.textContent), stop: false };
  };
  const neighbour = (el, root, direction) => {
    let cur = el;
    for (let depth = 0; depth < 3 && cur && cur !== root; depth++) {
      for (let n = direction === 'before' ? cur.previousSibling : cur.nextSibling; n;
           n = direction === 'before' ? n.previousSibling : n.nextSibling) {
        const r = textOf(n);
        if (r.stop) { break; }
        if (r.text) { return r.text.slice(0, 120); }
      }
      cur = cur.parentElement;
    }
    return null;
  };

  const panelTitle = (panel) => {
    const labelled = attr(panel, 'aria-labelledby');
    if (labelled) {
      const t = clean(labelled.split(/\s+/).map((id) => (document.getElementById(id) || {}).textContent || '').join(' '));
      if (t) { return t; }
    }
    const heading = panel.querySelector('.offcanvas-title, .modal-title, [role="heading"], h1, h2, h3, h4, h5, h6, legend');
    const t = heading ? clean(heading.textContent) : '';
    return t || clean(attr(panel, 'aria-label') || '') || null;
  };

  const IGNORED_ID = /:|\d{5,}/;
  const candidates = (el) => {
    const tag = el.tagName.toLowerCase();
    const list = [];
    const testId = attr(el, 'data-testid');
    const test = attr(el, 'data-test');
    if (testId) { list.push('[data-testid=' + q(testId) + ']'); }
    if (test) { list.push('[data-test=' + q(test) + ']'); }
    if (el.id && !IGNORED_ID.test(el.id)) { list.push('#' + CSS.escape(el.id)); }
    const name = attr(el, 'name');
    if (name) {
      list.push(tag + '[name=' + q(name) + ']');
      const value = attr(el, 'value');
      if (value) { list.push(tag + '[name=' + q(name) + '][value=' + q(value) + ']'); }
    }
    const aria = attr(el, 'aria-label');
    if (aria) { list.push(tag + '[aria-label=' + q(aria) + ']'); }
    const href = attr(el, 'href');
    if (href) { list.push(tag + '[href=' + q(href) + ']'); }
    const bsTarget = attr(el, 'data-bs-target');
    if (bsTarget) { list.push(tag + '[data-bs-target=' + q(bsTarget) + ']'); }
    if (attr(el, 'type') === 'submit') { list.push(tag + '[type="submit"]'); }
    const title = attr(el, 'title');
    if (title) { list.push(tag + '[title=' + q(title) + ']'); }
    const placeholder = attr(el, 'placeholder');
    if (placeholder) { list.push(tag + '[placeholder=' + q(placeholder) + ']'); }
    return list;
  };
  const pathSelector = (el, scope) => {
    const parts = [];
    let cur = el;
    while (cur && cur !== scope && cur.nodeType === 1 && cur !== document.documentElement) {
      let part = cur.tagName.toLowerCase();
      const parent = cur.parentElement;
      if (parent) {
        const same = Array.from(parent.children).filter((c) => c.tagName === cur.tagName);
        if (same.length > 1) { part += ':nth-of-type(' + (same.indexOf(cur) + 1) + ')'; }
      }
      if (cur.id && !IGNORED_ID.test(cur.id)) {
        parts.unshift('#' + CSS.escape(cur.id));
        break;
      }
      parts.unshift(part);
      cur = parent;
    }
    return parts.join(' > ');
  };
  const selectorIn = (el, scope) => {
    for (const candidate of candidates(el)) {
      if (unique(scope, candidate, el)) { return { selector: candidate, fragile: false }; }
    }
    const path = pathSelector(el, scope === document ? null : scope);
    return { selector: path, fragile: true };
  };

  const panels = Array.from(document.querySelectorAll(PANELS));
  const panelInfos = panels.map((panel) => ({
    el: panel,
    selector: selectorIn(panel, document).selector,
    title: panelTitle(panel),
    visible: isVisible(panel),
  }));
  const openPanels = panelInfos.filter((p) => p.visible);
  const container = openPanels.length > 0 ? openPanels[openPanels.length - 1] : null;
  const scope = container ? container.el : document;

  const elements = [];
  for (const el of Array.from(scope.querySelectorAll(INTERACTIVE))) {
    if (elements.length >= MAX_ELEMENTS) { break; }
    if (el === scope || !isVisible(el)) { continue; }

    const tag = el.tagName.toLowerCase();
    const type = attr(el, 'type');
    const isField = tag === 'input' || tag === 'select' || tag === 'textarea';
    const isButtonInput = tag === 'input' && ['submit', 'button', 'reset', 'image'].includes((type || '').toLowerCase());

    let label = null;
    if (el.labels && el.labels.length) {
      label = clean(el.labels[0].textContent);
    } else if (isField && tag !== 'select') {
      const wrap = el.closest('label');
      label = wrap ? clean(wrap.textContent) : null;
    }
    let ariaLabel = attr(el, 'aria-label');
    const labelledBy = attr(el, 'aria-labelledby');
    if (!ariaLabel && labelledBy) {
      ariaLabel = clean(labelledBy.split(/\s+/).map((id) => (document.getElementById(id) || {}).textContent || '').join(' ')) || null;
    }

    let text = null;
    if (isButtonInput) { text = clean(attr(el, 'value') || ''); }
    else if (!isField) { text = clean(el.innerText || el.textContent); }

    let bsTarget = attr(el, 'data-bs-target');
    if (!bsTarget && attr(el, 'data-bs-toggle') && (attr(el, 'href') || '').startsWith('#')) { bsTarget = attr(el, 'href'); }
    let targetTitle = null;
    if (bsTarget) {
      try {
        const targetEl = document.querySelector(bsTarget);
        targetTitle = targetEl ? panelTitle(targetEl) : null;
      } catch (e) { targetTitle = null; }
    }

    const relative = selectorIn(el, scope);
    let full = relative.selector;
    if (container && !unique(document, relative.selector, el)) {
      full = container.selector + ' ' + relative.selector;
    }

    const kindIsChoice = type === 'checkbox' || type === 'radio' || ['checkbox', 'switch', 'radio'].includes(attr(el, 'role') || '');
    elements.push({
      tag,
      type,
      role: attr(el, 'role'),
      id: el.id || null,
      name: attr(el, 'name'),
      placeholder: attr(el, 'placeholder'),
      ariaLabel: ariaLabel ? clean(ariaLabel) : null,
      title: attr(el, 'title'),
      testId: attr(el, 'data-testid') || attr(el, 'data-test'),
      href: attr(el, 'href'),
      text: text || null,
      label: label || null,
      nearby: isField || kindIsChoice ? neighbour(el, scope === document ? document.body : scope, 'before') : null,
      following: kindIsChoice ? neighbour(el, scope === document ? document.body : scope, 'after') : null,
      bsTarget,
      targetTitle,
      disabled: el.disabled === true || attr(el, 'aria-disabled') === 'true',
      checked: el.checked === true,
      options: tag === 'select' ? Array.from(el.options).slice(0, 50).map((o) => ({ value: o.value, text: clean(o.textContent) })) : [],
      selector: full,
      relative: relative.selector,
      container: container ? container.selector : null,
      fragile: relative.fragile,
    });
  }

  return {
    url: location.pathname + location.search,
    container: container ? { selector: container.selector, title: container.title, visible: true } : null,
    panels: panelInfos.map((p) => ({ selector: p.selector, title: p.title, visible: p.visible })),
    elements,
    bodyText: clean((container ? container.el : document.body).innerText).slice(0, 3000),
  };
}
JS;

    /**
     * Convertit la réponse brute de Page::evaluate(self::SCRIPT).
     */
    public static function parse(mixed $raw): ScanResult
    {
        if (is_string($raw)) {
            try {
                $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new ProbeException('Réponse du scanner illisible : ' . $e->getMessage(), 0, $e);
            }
        }

        if (!is_array($raw) || !isset($raw['elements']) || !is_array($raw['elements'])) {
            throw new ProbeException('Le scanner de DOM n\'a pas renvoyé la structure attendue (clé « elements » absente).');
        }

        return ScanResult::fromArray($raw);
    }
}
