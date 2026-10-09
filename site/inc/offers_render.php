<?php

declare(strict_types=1);

/*
 * Rendu HTML public des cartes d'offres, piloté par le ContentStore.
 * Reproduit EXACTEMENT le markup/classes d'origine de la section #offres
 * de index.php (articles .offer pour l'onglet Création, .plan pour les
 * onglets Hébergement / Maintenance), en ajoutant le rendu des promos.
 */

require_once __DIR__ . '/content_store.php';

/** Échappement HTML court. */
function oe(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Attributs data-* d'un CTA, rendus dans l'ordre stocké. */
function offers_cta_attrs(array $offer): string
{
    $out = '';
    foreach (($offer['cta_data'] ?? []) as $k => $v) {
        // Les clés sont contrôlées côté serveur (data-*) ; on échappe quand même.
        $out .= ' ' . oe((string) $k) . '="' . oe((string) $v) . '"';
    }
    return $out;
}

/**
 * Rendu du montant de prix avec gestion de promo.
 * $unit est déjà du HTML prêt (ex. '<span> / mois</span>') ou ''.
 */
function offers_price_html(array $offer, string $unitHtml): string
{
    $price = (string) ($offer['price'] ?? '');
    if (!cs_promo_active($offer)) {
        return oe($price) . $unitHtml;
    }
    $promo = $offer['promo'];
    $html  = '<s class="price-was">' . oe($price) . '</s> ';
    $html .= '<span class="price-now">' . oe((string) ($promo['price'] ?? '')) . '</span>' . $unitHtml;
    $label = trim((string) ($promo['label'] ?? ''));
    if ($label !== '') {
        $html .= ' <span class="promo-tag">' . oe($label) . '</span>';
    }
    return $html;
}

/** Carte "offer" (onglet Création). */
function offers_render_offer(array $o): string
{
    $cls = 'offer' . (!empty($o['feat']) ? ' offer--feat' : '');
    $h = '      <article class="' . $cls . '">' . "\n";
    if (!empty($o['badge'])) {
        $h .= '        <span class="offer__badge">' . oe((string) $o['badge']) . '</span>' . "\n";
    }
    $h .= '        <div class="offer__top"><span class="offer__n">' . oe((string) ($o['number'] ?? ''))
        . '</span><span class="tag">' . oe((string) ($o['tag'] ?? '')) . '</span></div>' . "\n";
    $h .= '        <h3>' . oe((string) ($o['title'] ?? '')) . '</h3>' . "\n";
    $h .= '        <p class="offer__promise">' . oe((string) ($o['description'] ?? '')) . '</p>' . "\n";
    $h .= '        <div class="offer__inc"><b>Inclus</b>' . oe((string) ($o['inc'] ?? '')) . '</div>' . "\n";
    $h .= '        <div class="offer__price"><div class="lbl">' . oe((string) ($o['price_label'] ?? ''))
        . '</div><div class="amt">' . offers_price_html($o, '')
        . '</div><div class="note">' . oe((string) ($o['price_note'] ?? '')) . '</div></div>' . "\n";
    $h .= '        <a href="#contact" class="btn btn-ghost"' . offers_cta_attrs($o) . '>'
        . oe((string) ($o['cta_label'] ?? '')) . '</a>' . "\n";
    $h .= '      </article>';
    return $h;
}

/** Carte "plan" (onglets Hébergement / Maintenance). */
function offers_render_plan(array $o): string
{
    $cls = 'plan' . (!empty($o['feat']) ? ' plan--feat' : '');
    $unit = '';
    $u = (string) ($o['price_unit'] ?? '');
    if ($u !== '') {
        $unit = '<span>' . oe($u) . '</span>';
    }
    $h = '        <article class="' . $cls . '">' . "\n";
    if (!empty($o['badge'])) {
        $h .= '          <span class="plan__badge">' . oe((string) $o['badge']) . '</span>' . "\n";
    }
    $h .= '          <div class="plan__name">' . oe((string) ($o['title'] ?? '')) . '</div>' . "\n";
    $h .= '          <div class="plan__price">' . offers_price_html($o, $unit) . '</div>' . "\n";
    $h .= '          <div class="plan__for">' . oe((string) ($o['description'] ?? '')) . '</div>' . "\n";
    $h .= '          <ul class="plan__list">';
    foreach (($o['features'] ?? []) as $f) {
        $h .= '<li>' . oe((string) $f) . '</li>';
    }
    $h .= '</ul>' . "\n";
    if (!empty($o['packs']) && is_array($o['packs'])) {
        $h .= '          <div class="plan__packs">' . "\n";
        $h .= '            <span class="pk-lbl">Packs dégressifs</span>' . "\n";
        foreach ($o['packs'] as $p) {
            $h .= '            <div class="plan__pack"><span class="a">' . oe((string) ($p['a'] ?? ''))
                . '</span><span class="b">' . oe((string) ($p['b_main'] ?? ''))
                . '<small>' . oe((string) ($p['b_small'] ?? '')) . '</small></span></div>' . "\n";
        }
        $h .= '          </div>' . "\n";
    }
    $h .= '          <a href="#contact" class="btn btn-ghost"' . offers_cta_attrs($o) . '>'
        . oe((string) ($o['cta_label'] ?? '')) . '</a>' . "\n";
    $h .= '        </article>';
    return $h;
}

/** Rendu de toutes les cartes d'un onglet. */
function offers_render_tab(array $offers, string $tab): string
{
    $parts = [];
    foreach ($offers as $o) {
        $parts[] = ($tab === 'crea') ? offers_render_offer($o) : offers_render_plan($o);
    }
    return implode("\n\n", $parts);
}
