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

/** Valeur du select de contact pour « Devis sur-mesure » (hors catalogue). */
const OFFERS_CUSTOM_QUOTE_VALUE = 'sur-mesure';
const OFFERS_CUSTOM_QUOTE_LABEL = 'Devis sur-mesure';

/** Prix de départ (1er nombre du prix, ex. « 999 € – 1 899 € » => 999), 0 si aucun. Le prix promo prime s'il est actif. */
function offers_start_price(array $offer): int
{
    $price = (string) ($offer['price'] ?? '');
    if (cs_promo_active($offer) && trim((string) ($offer['promo']['price'] ?? '')) !== '') {
        $price = (string) $offer['promo']['price'];
    }
    if (preg_match('/\d[\d \x{00A0}\x{202F}]*/u', $price, $m)) {
        return (int) preg_replace('/\D+/', '', $m[0]);
    }
    return 0;
}

/** Nombre de mois payés pour une année d'abonnement (2 mois offerts). */
const OFFERS_ANNUAL_MONTHS_PAID = 10;

/**
 * Formule annuelle d'une offre mensuelle (onglets Hébergement et Maintenance), dérivée du prix mensuel
 * (data-mo, ou prix promo s'il est actif) : null si l'offre n'est pas un abonnement mensuel.
 */
function offers_annual_info(array $o): ?array
{
    $tab = (string) ($o['tab'] ?? '');
    if ($tab !== 'heb' && $tab !== 'maint') {
        return null;
    }
    $mo = $regular = (int) ($o['cta_data']['data-mo'] ?? 0);
    $promo = false;
    if ($mo > 0 && cs_promo_active($o) && ($promoMo = offers_start_price($o)) > 0) {
        $mo = $promoMo;
        $promo = true;
    }
    if ($mo <= 0 || stripos((string) ($o['price_unit'] ?? ''), 'mois') === false) {
        return null;
    }
    $nb   = "\u{00A0}";
    $fmt  = static fn(float $n): string => str_replace(',00', '', number_format($n, 2, ',', $nb));
    $year = $mo * OFFERS_ANNUAL_MONTHS_PAID;
    $labelYr = '';
    if ($promo) {
        // Promo annuelle dédiée (optionnelle) : prix et étiquette propres à la formule annuelle.
        $yrPrice = (int) preg_replace('/\D+/', '', (string) preg_replace('/[^\d \x{00A0}\x{202F}].*$/u', '', (string) ($o['promo']['price_yr'] ?? '')));
        if ($yrPrice > 0) {
            $year = $yrPrice;
        }
        $labelYr = trim((string) ($o['promo']['label_yr'] ?? '')) ?: trim((string) ($o['promo']['label'] ?? ''));
    }
    $title = trim((string) ($o['title'] ?? ''));
    return [
        'mo'    => $mo,
        'year'  => $year,
        // Prix barrés : 12 mois au tarif mensuel normal, puis (pendant une promo) l'ancien prix annuel.
        'full'  => $fmt($regular * 12) . $nb . '€',
        'tag'   => $labelYr,
        'was'   => $promo ? $fmt($regular * OFFERS_ANNUAL_MONTHS_PAID) . $nb . '€' : '',
        'price' => $fmt($year) . $nb . '€',
        'permo' => $fmt($year / 12) . $nb . '€',
        'saved' => $fmt($regular * (12 - OFFERS_ANNUAL_MONTHS_PAID)) . $nb . '€',
        'label' => $title . ' (annuel)',
        'form'  => $title . ' · formule annuelle (' . $year . ' €/an)',
    ];
}

/** Sélecteur Mensuel / Annuel affiché en tête des onglets qui ont au moins une formule annuelle. */
function offers_billing_toggle_html(array $offers, string $indent = '      '): string
{
    foreach ($offers as $o) {
        if (offers_annual_info($o) !== null) {
            return $indent . '<div class="bill-toggle" role="group" aria-label="Rythme de facturation">'
                . '<label for="bill-m">Mensuel</label><label for="bill-y">Annuel</label></div>' . "\n";
        }
    }
    return '';
}

/**
 * Attributs data-* d'un CTA. Onglet Création : auto-dérivés de l'offre
 * (data-form = id, valeur du select de contact ; data-oneoff = prix de départ).
 * Autres onglets : rendus tels que stockés.
 */
function offers_cta_attrs(array $offer): string
{
    if (($offer['tab'] ?? '') === 'seo') {
        $attrs = [
            'data-step'   => 'seo',
            'data-next'   => 'tab-maint',
            'data-label'  => (string) ($offer['title'] ?? ''),
            'data-oneoff' => (string) offers_start_price($offer),
            'data-form'   => (string) ($offer['id'] ?? ''),
        ];
    } elseif (($offer['tab'] ?? '') === 'crea') {
        $stored = is_array($offer['cta_data'] ?? null) ? $offer['cta_data'] : [];
        $attrs = [
            'data-step'   => 'offre',
            'data-label'  => (string) ($offer['title'] ?? ''),
            'data-oneoff' => (string) offers_start_price($offer),
            'data-form'   => (string) ($offer['id'] ?? ''),
            'data-next'   => (string) ($stored['data-next'] ?? 'tab-heb'),
        ];
    } else {
        $attrs = is_array($offer['cta_data'] ?? null) ? $offer['cta_data'] : [];
        // Parcours guidé : Création > Hébergement > Référencement > Maintenance > contact.
        if (($offer['tab'] ?? '') === 'heb') {
            $attrs['data-next'] = 'tab-seo';
        }
        if ((int) ($attrs['data-mo'] ?? 0) > 0 && cs_promo_active($offer) && offers_start_price($offer) > 0) {
            $attrs['data-mo'] = (string) offers_start_price($offer);
        }
        if (($yr = offers_annual_info($offer)) !== null) {
            $attrs['data-yr']       = (string) $yr['year'];
            $attrs['data-label-yr'] = $yr['label'];
            $attrs['data-form-yr']  = $yr['form'];
        }
    }
    $out = '';
    foreach ($attrs as $k => $v) {
        // Les clés sont contrôlées côté serveur (data-*) ; on échappe quand même.
        $out .= ' ' . oe((string) $k) . '="' . oe((string) $v) . '"';
    }
    return $out;
}

/** Offres Création sélectionnables dans le formulaire de contact (actives, triées). */
function offers_contact_choices(array $content): array
{
    return cs_offers_by_tab($content)['crea'];
}

/** <option> du select « offre » du formulaire de contact. */
function offers_contact_options_html(array $content, string $indent = '            '): string
{
    $h = $indent . '<option value="">— Je ne sais pas encore —</option>' . "\n";
    foreach (offers_contact_choices($content) as $o) {
        if (!offers_is_available($o)) {
            continue;
        }
        $h .= $indent . '<option value="' . oe((string) $o['id']) . '">' . oe((string) ($o['title'] ?? $o['id'])) . '</option>' . "\n";
    }
    $h .= $indent . '<option value="' . OFFERS_CUSTOM_QUOTE_VALUE . '">' . OFFERS_CUSTOM_QUOTE_LABEL . '</option>' . "\n";
    return $h;
}

/** Valeur « plus tard » du select Maintenance du formulaire de contact. */
const OFFERS_MAINT_LATER_VALUE = "On verra plus tard / besoin d'infos";

/** Valeurs de formulaire (data-form) des offres actives et disponibles d'un onglet Hébergement / Maintenance. */
function offers_plan_choices(array $content, string $tab): array
{
    $vals = [];
    foreach (cs_offers_by_tab($content)[$tab] ?? [] as $o) {
        if (!offers_is_available($o)) {
            continue;
        }
        $v = trim((string) (($o['cta_data']['data-form'] ?? '') ?: ($o['title'] ?? '')));
        if ($v !== '') {
            $vals[] = $v;
        }
        if (($yr = offers_annual_info($o)) !== null) {
            $vals[] = $yr['form'];
        }
    }
    return array_values(array_unique($vals));
}

/** <option> d'un select Hébergement / Maintenance du formulaire de contact (offres actives de l'onglet). */
function offers_plan_options_html(array $content, string $tab, bool $withLater = false, string $indent = '            '): string
{
    $h = $indent . '<option value="">— À définir —</option>' . "\n";
    foreach (offers_plan_choices($content, $tab) as $v) {
        $h .= $indent . '<option value="' . oe($v) . '">' . oe($v) . '</option>' . "\n";
    }
    if ($withLater) {
        $h .= $indent . '<option value="' . oe(OFFERS_MAINT_LATER_VALUE) . '">' . oe(OFFERS_MAINT_LATER_VALUE) . '</option>' . "\n";
    }
    return $h;
}

/** <option> du select « référencement » du formulaire de contact (offres de l'onglet Référencement actives). */
function offers_seo_options_html(array $content, string $indent = '            '): string
{
    $h = $indent . '<option value="">— Aucun —</option>' . "\n";
    foreach (cs_offers_by_tab($content)['seo'] as $o) {
        if (offers_is_available($o)) {
            $h .= $indent . '<option value="' . oe((string) $o['id']) . '">' . oe((string) ($o['title'] ?? $o['id'])) . '</option>' . "\n";
        }
    }
    return $h;
}

/** Libellé d'un id d'offre Référencement reçu du formulaire, ou '' si inconnu. */
function offers_seo_label(array $content, string $id): string
{
    foreach (cs_offers_by_tab($content)['seo'] as $o) {
        if (($o['id'] ?? null) === $id && offers_is_available($o)) {
            return (string) ($o['title'] ?? '');
        }
    }
    return '';
}

/** Libellé lisible d'un id d'offre reçu du formulaire, ou '' si inconnu (liste blanche = offres réelles actives). */
function offers_contact_label(array $content, string $id): string
{
    if ($id === OFFERS_CUSTOM_QUOTE_VALUE) {
        return OFFERS_CUSTOM_QUOTE_LABEL;
    }
    foreach (offers_contact_choices($content) as $o) {
        if (($o['id'] ?? null) === $id) {
            return (string) ($o['title'] ?? '');
        }
    }
    return '';
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

/** Offre disponible ? (champ `available` absent = disponible, rétro-compatible). */
function offers_is_available(array $o): bool
{
    return !array_key_exists('available', $o) || !empty($o['available']);
}

/** Badge « Indisponible » + CTA désactivé (HTML), selon l'état de l'offre. */
function offers_soldout_badge(): string
{
    return '<span class="stock-badge">Indisponible</span>';
}

/** Bouton « Voir le détail » (mobile uniquement, actif seulement avec JS : voir js/offers-fold.js). */
function offers_fold_toggle(string $indent): string
{
    return $indent . '<button type="button" class="fold-toggle" aria-expanded="false"><span>Voir le détail</span></button>' . "\n";
}

function offers_cta_html(array $o, string $indent): string
{
    if (!offers_is_available($o)) {
        return $indent . '<span class="btn btn-ghost fold is-disabled" aria-disabled="true" role="link">Indisponible</span>' . "\n";
    }
    return $indent . '<a href="#contact" class="btn btn-ghost fold"' . offers_cta_attrs($o) . '>'
        . oe((string) ($o['cta_label'] ?? '')) . '</a>' . "\n";
}

/** Carte "offer" (onglet Création). */
function offers_render_offer(array $o): string
{
    $cls = 'offer' . (!empty($o['feat']) ? ' offer--feat' : '') . (offers_is_available($o) ? '' : ' is-unavailable');
    $h = '      <article class="' . $cls . '">' . "\n";
    if (!offers_is_available($o)) {
        $h .= '        ' . offers_soldout_badge() . "\n";
    }
    if (!empty($o['badge'])) {
        $h .= '        <span class="offer__badge">' . oe((string) $o['badge']) . '</span>' . "\n";
    }
    $h .= '        <div class="offer__top"><span class="offer__n">' . oe((string) ($o['number'] ?? ''))
        . '</span><span class="tag">' . oe((string) ($o['tag'] ?? '')) . '</span></div>' . "\n";
    $h .= '        <h3>' . oe((string) ($o['title'] ?? '')) . '</h3>' . "\n";
    $h .= '        <p class="offer__promise">' . oe((string) ($o['description'] ?? '')) . '</p>' . "\n";
    $h .= '        <div class="offer__inc fold"><b>Inclus</b>' . oe((string) ($o['inc'] ?? '')) . '</div>' . "\n";
    $h .= '        <div class="offer__price"><div class="lbl">' . oe((string) ($o['price_label'] ?? ''))
        . '</div><div class="amt' . (str_contains((string) ($o['price'] ?? ''), '–') ? ' amt--range' : '') . '">' . offers_price_html($o, '')
        . '</div><div class="note">' . oe((string) ($o['price_note'] ?? '')) . '</div>'
        . '<div class="indic">prix indicatif · devis gratuit</div></div>' . "\n";
    $h .= offers_fold_toggle('        ');
    $h .= offers_cta_html($o, '        ');
    $h .= '      </article>';
    return $h;
}

/** Carte "plan" (onglets Hébergement / Maintenance). */
function offers_render_plan(array $o): string
{
    $cls = 'plan' . (!empty($o['feat']) ? ' plan--feat' : '') . (offers_is_available($o) ? '' : ' is-unavailable');
    $unit = '';
    $u = (string) ($o['price_unit'] ?? '');
    if ($u !== '') {
        $unit = '<span>' . oe($u) . '</span>';
    }
    $h = '        <article class="' . $cls . '">' . "\n";
    if (!offers_is_available($o)) {
        $h .= '          ' . offers_soldout_badge() . "\n";
    }
    if (!empty($o['badge'])) {
        $h .= '          <span class="plan__badge">' . oe((string) $o['badge']) . '</span>' . "\n";
    }
    $h .= '          <div class="plan__name">' . oe((string) ($o['title'] ?? '')) . '</div>' . "\n";
    $yr = offers_annual_info($o);
    if ($yr !== null) {
        $h .= '          <div class="plan__price"><i class="bill bill--m">' . offers_price_html($o, $unit) . '</i>'
            . '<i class="bill bill--y"><s class="price-was">' . oe($yr['full']) . '</s> '
            . ($yr['was'] !== '' ? '<s class="price-was">' . oe($yr['was']) . '</s> ' : '') . oe($yr['price']) . '<span> / an</span>'
            . ($yr['tag'] !== '' ? ' <span class="promo-tag">' . oe($yr['tag']) . '</span>' : '')
            . '</i></div>' . "\n";
        $h .= '          <div class="plan__annual"><i class="bill bill--m">Ou ' . oe($yr['price']) . ' / an : 2 mois offerts</i>'
            . '<i class="bill bill--y">Soit ' . oe($yr['permo']) . ' / mois, vous économisez ' . oe($yr['saved']) . '</i></div>' . "\n";
    } else {
        $h .= '          <div class="plan__price">' . offers_price_html($o, $unit) . '</div>' . "\n";
    }
    $h .= '          <div class="plan__for">' . oe((string) ($o['description'] ?? '')) . '</div>' . "\n";
    $h .= offers_fold_toggle('          ');
    $h .= '          <ul class="plan__list fold">';
    foreach (($o['features'] ?? []) as $f) {
        $h .= '<li>' . oe((string) $f) . '</li>';
    }
    $h .= '</ul>' . "\n";
    if (!empty($o['packs']) && is_array($o['packs'])) {
        $h .= '          <div class="plan__packs fold">' . "\n";
        $h .= '            <span class="pk-lbl">Packs dégressifs</span>' . "\n";
        foreach ($o['packs'] as $p) {
            $h .= '            <div class="plan__pack"><span class="a">' . oe((string) ($p['a'] ?? ''))
                . '</span><span class="b">' . oe((string) ($p['b_main'] ?? ''))
                . '<small>' . oe((string) ($p['b_small'] ?? '')) . '</small></span></div>' . "\n";
        }
        $h .= '          </div>' . "\n";
    }
    $h .= offers_cta_html($o, '          ');
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

/**
 * JSON-LD : liste d'Offer (schema.org) pour les offres ACTIVES du store.
 * Prix pris tels quels dans le store (fourchette, "à partir de", mensuel/horaire, gratuit).
 */
function offers_jsonld(array $content): array
{
    $nums = static function (string $s): array {
        $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' '], '', $s);
        preg_match_all('/\d+(?:[.,]\d+)?/', $s, $m);
        return array_map(static fn($n) => (float) str_replace(',', '.', $n) + 0, $m[0]);
    };
    $out = [];
    foreach (cs_offers($content) as $o) {
        if (empty($o['active'])) {
            continue;
        }
        $title = trim((string) ($o['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $service = ['@type' => 'Service', 'name' => $title];
        $desc = trim((string) ($o['description'] ?? ''));
        if ($desc !== '') {
            $service['description'] = $desc;
        }
        $offer = ['@type' => 'Offer', 'itemOffered' => $service, 'priceCurrency' => 'EUR'];

        $price = trim((string) ($o['price'] ?? ''));
        $unit = strtolower(trim((string) ($o['price_unit'] ?? '')));
        $n = $nums($price);
        if ($n) {
            if ($unit !== '') {
                $code = strpos($unit, 'mois') !== false ? 'MON' : (strpos($unit, 'heure') !== false ? 'HUR' : '');
                $spec = ['@type' => 'UnitPriceSpecification', 'price' => $n[0], 'priceCurrency' => 'EUR'];
                if ($code !== '') {
                    $spec['unitCode'] = $code;
                    $spec['unitText'] = $code === 'MON' ? 'mois' : 'heure';
                }
                $offer['priceSpecification'] = $spec;
            } elseif (count($n) >= 2) {
                $offer['priceSpecification'] = [
                    '@type' => 'PriceSpecification',
                    'minPrice' => min($n[0], $n[1]),
                    'maxPrice' => max($n[0], $n[1]),
                    'priceCurrency' => 'EUR',
                ];
            } else {
                $offer['priceSpecification'] = ['@type' => 'PriceSpecification', 'minPrice' => $n[0], 'priceCurrency' => 'EUR'];
            }
        } elseif (stripos($price, 'gratuit') !== false) {
            $offer['price'] = 0;
        }
        $out[] = $offer;
    }
    return $out;
}
