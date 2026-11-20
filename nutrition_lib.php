<?php
/**
 * Moteur Nutrition TRQ : cibles alimentaires calculées à partir des séances réalisées.
 *
 * Source des recommandations : Burke LM, Dolan E, Gonzalez JT, Mujika I, Jeukendrup AE, et al.,
 * "UCI Sports Nutrition Project: Position Statement on Nutrition for Cycling",
 * Int J Sport Nutr Exerc Metab 36(6):700-726, 2026. doi:10.1123/ijsnem.2026-0135 (libre accès).
 * Les cibles visent l'élite : elles s'affichent comme des fourchettes de référence, pas des prescriptions.
 */

const NUTRITION_REF_SHORT = 'Statement UCI 2026 · Burke et al., IJSNEM 36(6):700-726';
const NUTRITION_REF_DOI = 'https://doi.org/10.1123/ijsnem.2026-0135';

/** Énergie métabolique (kcal) à partir du travail mécanique (kJ) : kcal ≈ kJ / (efficience brute ~19-23 %) / 4.184. */
function kcal_from_kj(?float $kj): float {
    return $kj > 0 ? $kj * 1.05 : 0.0;
}

function nutrition_weight(): ?float {
    $st = db()->query("SELECT weight FROM wellness WHERE weight IS NOT NULL AND date >= date('now','-14 days') ORDER BY date DESC LIMIT 1");
    $w = $st->fetchColumn();
    if ($w !== false && (float)$w > 0) return (float)$w;
    $c = cfg('weight');
    return $c !== null && (float)$c > 0 ? (float)$c : null;
}

function nutrition_ffm(?float $weight, ?float $bodyFatPct): ?float {
    if ($weight === null || $bodyFatPct === null || $bodyFatPct <= 0 || $bodyFatPct >= 60) return null;
    return $weight * (1 - $bodyFatPct / 100);
}

/** Glucides "sur le vélo" (g/h) selon la durée et l'intensité — Table 3 du statement.
 *  < 40 min : rien de nécessaire · 40-75 min : rincage buccal · 1-2,5 h : 30-60 g/h · > 2,5 h : 90 g/h. */
function nutrition_onbike_carbs(float $hours, ?float $ifVal): array {
    $ifv = $ifVal ?? 0.75;
    if ($hours < 40 / 60) {
        return ['mode' => 'aucun', 'range' => [0.0, 0.0], 'target' => 0.0, 'total_g' => 0,
                'note' => 'Effort court : l\'eau suffit ; glucides inutiles sauf enchaînement avec une autre séance.'];
    }
    if ($hours <= 75 / 60) {
        return ['mode' => 'rincage', 'range' => [0.0, 0.0], 'target' => 0.0, 'total_g' => 0,
                'note' => 'Rincage buccal : 5-10 s de contact d\'une boisson sucrée en bouche, utile pour l\'allure ressentie sur les efforts intenses.'];
    }
    if ($hours <= 2.5) {
        $lo = $ifv >= 0.85 ? 45.0 : 30.0;
        $hi = $ifv >= 0.85 ? 60.0 : 45.0;
        return ['mode' => 'plage', 'range' => [$lo, $hi], 'target' => ($lo + $hi) / 2.0, 'total_g' => (int)round(($lo + $hi) / 2.0 * $hours),
                'note' => 'Aliments courants ou produits sport suffisants ; mélange glucose+fructose au-delà de 60 g/h.'];
    }
    return ['mode' => 'cible', 'range' => [80.0, 90.0], 'target' => 90.0, 'total_g' => (int)round(90.0 * $hours),
            'note' => 'Mélange glucose+fructose obligatoire au-dessus de 60 g/h ; 120 g/h et plus : bénéfice non validé, montée progressive uniquement.'];
}

/** Hydratation : viser des pertes nettes < 2 % de la masse corporelle (statement UCI 2026). */
function nutrition_hydration(float $hours, ?float $weight, bool $hot = false): array {
    $per = $weight === null ? 0.0 : $weight * ($hot ? 0.012 : 0.008);
    $per = min($per, $hot ? 1.0 : 0.75);
    return ['ml_h' => (int)round($per * 1000), 'ml_total' => (int)round($per * $hours * 1000),
            'note' => 'Objectif : pertes < 2 % du poids. Pesez-vous avant/après des sorties similaires pour calibrer votre taux de sudation.'];
}

/** Repas d'avant effort : 1-4 g/kg de glucides dans les 1 à 4 h précédant le départ (statement UCI 2026). */
function nutrition_prep_meal(?float $weight, float $hoursToStart): array {
    if ($weight === null) return ['g' => null, 'g_kg' => null, 'note' => 'Renseignez un poids (Wellness ou Paramètres) pour quantifier.'];
    $gPerKg = $hoursToStart >= 3 ? 3.0 : max(1.0, 4.0 - $hoursToStart);
    return ['g' => (int)round($gPerKg * $weight), 'g_kg' => $gPerKg,
            'note' => '1-4 g/kg de glucides 1-4 h avant le départ ; moins on est proche du départ, moins on en met.'];
}

/** Bande glucidique journalière selon le volume du jour — Table 2 du statement (g/kg de masse corporelle). */
function nutrition_day_band(float $hTotal): array {
    if ($hTotal >= 6.0) return ['band' => 'extreme', 'label' => 'Course extrême (> 6 h, étapes)', 'range' => [12.0, 15.0], 'raw' => '10-25 g/kg'];
    if ($hTotal >= 4.0) return ['band' => 'intensified', 'label' => 'Entraînement intensifié (> 4 h/j)', 'range' => [8.0, 11.0], 'raw' => '> 8 g/kg'];
    if ($hTotal >= 1.0) return ['band' => 'moderate', 'label' => 'Endurance modérée (< 3 h/j)', 'range' => [6.0, 10.0], 'raw' => '6-10 g/kg'];
    if ($hTotal > 0.0) return ['band' => 'light', 'label' => 'Endurance légère', 'range' => [5.0, 7.0], 'raw' => '5-7 g/kg'];
    return ['band' => 'rest', 'label' => 'Repos / récupération', 'range' => [3.0, 5.0], 'raw' => 'générique (hors Table 2)'];
}

/** Énergie estimée des séances d'un jour : kJ quand la puissance existe, sinon estimation générique (hors statement). */
function nutrition_energy(array $rows, ?float $weight): float {
    $kcal = 0.0;
    foreach ($rows as $r) {
        $kj = (float)($r['kj'] ?? 0);
        if ($kj > 0) { $kcal += kcal_from_kj($kj); continue; }
        if ($weight === null || $weight <= 0) continue;
        $sport = $r['sport'] ?? 'ride';
        $km = (float)($r['distance'] ?? 0) / 1000.0;
        if ($sport === 'run' && $km > 0) {
            $kcal += $weight * $km * 1.036;
            continue;
        }
        $met = match ($sport) {
            'run' => 10.5,
            'swim' => 9.0,
            'ride' => 7.5,
            default => 7.0,
        };
        $kcal += $met * $weight * (float)$r['duration'] / 3600.0;
    }
    return $kcal;
}

function nutrition_day_rows(PDO $pdo, string $date): array {
    $st = $pdo->prepare('SELECT sport, duration, kj, distance FROM activities WHERE date = ?');
    $st->execute([$date]);
    return $st->fetchAll();
}

/** Plan de ravitaillement d'une séance réalisée : pré / pendant / post, depuis les données du fichier d'activité. */
function nutrition_session_plan(array $a, ?float $weight, float $ftp): array {
    $durH = (float)$a['duration'] / 3600.0;
    $ifv = !empty($a['if_']) && (float)$a['if_'] > 0 ? (float)$a['if_'] : null;
    $sport = $a['sport'] ?? 'ride';

    $ob = nutrition_onbike_carbs($durH, $ifv);
    if ($sport !== 'ride') {
        $ob = $durH > 2.0
            ? ['mode' => 'plage', 'range' => [30.0, 45.0], 'target' => 37.5, 'total_g' => (int)round(37.5 * $durH),
               'note' => 'Sortie longue non-vélo : 30-45 g/h est une pratique générique, le statement UCI cible le cyclisme.']
            : ['mode' => 'aucun', 'range' => [0.0, 0.0], 'target' => 0.0, 'total_g' => 0,
               'note' => 'Durée courte : hydratation suffisante en pratique générale.'];
    }

    $kcal = nutrition_energy([['sport' => $sport, 'duration' => $a['duration'], 'kj' => $a['kj'], 'distance' => $a['distance']]], $weight);
    return [
        'ref' => NUTRITION_REF_SHORT,
        'duration_h' => $durH,
        'sport' => $sport,
        'onbike' => $ob,
        'hydration' => nutrition_hydration($durH, $weight, false),
        'prep' => nutrition_prep_meal($weight, 2.0),
        'kcal' => $kcal,
        'post' => nutrition_post_session($weight, $a),
    ];
}

/** Récupération : recommandations génériques (le statement traite la récupération par protocoles, sans dose unique). */
function nutrition_post_session(?float $weight, array $a): array {
    $hard = (float)($a['tss'] ?? 0) >= 90 || (float)($a['if_'] ?? 0) >= 0.85;
    $choPerH = $hard ? 1.2 : 1.0;
    return [
        'cho_g_kg_h' => $choPerH,
        'cho_g' => $weight !== null ? (int)round($choPerH * $weight) : null,
        'pro_g' => $weight !== null ? (int)round(0.35 * $weight) : null,
        'duration_h' => 4,
        'note' => $hard
            ? 'Séance dure : haut de la fourchette glucidique (1,2 g/kg/h) pendant 4 h et protéines tôt ; protéines en haut de la plage les jours suivants.'
            : 'Recharge glucidique régulière sur les 4 h suivant la sortie + une portion de protéines.',
    ];
}

/** Bilan glissant des N derniers jours : dépense, bande CHO du jour, tendance de poids, proxys de risque EA. */
function nutrition_weekly_review(PDO $pdo, ?float $weight, int $nDays = 7): array {
    $days = [];
    for ($i = $nDays - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $rows = nutrition_day_rows($pdo, $d);
        $h = array_sum(array_map(fn($r) => (float)$r['duration'] / 3600.0, $rows));
        $kcal = nutrition_energy($rows, $weight);
        $band = nutrition_day_band($h);
        $days[] = [
            'date' => $d,
            'hours' => $h,
            'kcal' => $kcal,
            'band' => $band,
            'cho_g' => $weight !== null ? (int)round(($band['range'][0] + $band['range'][1]) / 2.0 * $weight) : null,
        ];
    }
    $trend = null;
    $pts = $pdo->query("SELECT date, weight FROM wellness WHERE weight IS NOT NULL ORDER BY date ASC LIMIT 60")->fetchAll();
    if (count($pts) >= 4) {
        $recent = array_slice($pts, -8);
        $first = (float)$recent[0]['weight'];
        $last = (float)$recent[count($recent) - 1]['weight'];
        $span = max(1, (int)((strtotime($recent[count($recent) - 1]['date']) - strtotime($recent[0]['date'])) / 86400));
        if ($first > 0) $trend = ['from' => $first, 'to' => $last, 'pct' => ($last - $first) / $first * 100, 'span_days' => $span];
    }
    return ['days' => $days, 'weight_trend' => $trend, 'ea_risk' => nutrition_low_ea_risk($days, $trend)];
}

/** Détection d'un risque d'apport énergétique insuffisant (proxys mesurables : poids, charge, jours sans repos). */
function nutrition_low_ea_risk(array $days, ?array $trend): array {
    $reasons = [];
    $weekKcal = array_sum(array_column($days, 'kcal'));
    $activeDays = 0;
    foreach ($days as $d) if ($d['hours'] >= 1.0) $activeDays++;
    if ($activeDays >= 7) $reasons[] = '7 jours consécutifs avec ≥ 1 h de charge : aucun jour de repos pour rééquilibrer l\'apport.';
    if ($weekKcal > 7000) $reasons[] = 'Dépense hebdomadaire estimée élevée (' . (int)$weekKcal . ' kcal) : vérifiez que l\'apport suit.';
    if ($trend !== null && $trend['span_days'] <= 14 && $trend['pct'] <= -1.5) {
        $reasons[] = sprintf('Perte de poids de %.1f %% en %.0f jour(s) : chute rapide avec charge élevée, marqueur classique d\'un apport insuffisant.', (float)$trend['pct'], (float)$trend['span_days']);
    }
    return ['reasons' => $reasons, 'week_kcal' => $weekKcal, 'active_days' => $activeDays];
}

/** Simulation de ravitaillement : plan horodaté à partir d'une durée, d'une intensité, d'un poids et de la chaleur. */
function nutrition_race_simulation(int $durMin, float $intensity, ?float $weight, bool $hot = false, bool $gutTraining = false, ?float $ftp = null): array {
    $hours = max(0.25, $durMin / 60.0);
    $ob = nutrition_onbike_carbs($hours, $intensity >= 85 ? 0.9 : ($intensity >= 70 ? 0.78 : 0.65));
    if ($gutTraining && $ob['target'] > 60.0) {
        $ob = ['mode' => 'cible', 'range' => [45.0, 60.0], 'target' => 60.0, 'total_g' => (int)round(60.0 * $hours),
               'note' => 'Montée en charge digestive : 60 g/h max pour l\'instant, +10-20 g/h toutes les 2-3 semaines d\'entraînement.'];
    }
    $hyd = nutrition_hydration($hours, $weight, $hot);
    $prep = nutrition_prep_meal($weight, 2.5);

    $steps = [];
    $steps[] = ['t' => 'J-2h30 à J-2h', 'label' => 'Repas d\'avant départ', 'cho_g' => $prep['g'], 'fluid_ml' => 400, 'note' => $prep['g'] !== null ? sprintf('≈ %.1f g/kg de glucides, pauvre en fibres', $prep['g_kg']) : ''];
    $steps[] = ['t' => 'J-15 min', 'label' => 'Dernière gorgée + rincage', 'cho_g' => $ob['target'] > 0 ? 10 : 0, 'fluid_ml' => 150, 'note' => $ob['mode'] === 'rincage' ? 'Rincage buccal 5-10 s possible dès l\'échauffement' : ''];

    $block = 0;
    while (($block + 1) * 45 <= $durMin) {
        $block++;
        $tMin = $block * 45;
        $g = $ob['target'] > 0 ? (int)round($ob['target'] * 0.75) : 0;
        $ml = $hyd['ml_h'] > 0 ? (int)round($hyd['ml_h'] * 0.75) : 400;
        $steps[] = ['t' => sprintf('H+%d:%02d', intdiv($tMin, 60), $tMin % 60), 'label' => 'Ravitaillement', 'cho_g' => $g, 'fluid_ml' => $ml,
                    'note' => $ob['target'] >= 80 ? 'Alternez boisson glucose+fructose et gel pour tenir la cible' : ''];
    }

    $disciplineNote = match (true) {
        $durMin <= 60 && $intensity >= 85 => 'Course courte et intense (type esports / crit) : chaleur et efforts répétés gênent la boisson ; privilégiez les gorgées sur les portions calmes.',
        $durMin >= 240 => 'Longue durée : l\'autonomie (emport / points de recharge) fait partie du plan de ravitaillement.',
        default => 'Répétez ce plan à l\'entraînement (entraînement digestif) plutôt que de le improviser le jour J.',
    };

    $totalCho = $ob['target'] > 0 ? $ob['target'] * $hours : 0.0;
    return [
        'duration_min' => $durMin,
        'intensity' => $intensity,
        'onbike' => $ob,
        'hydration' => $hyd,
        'prep' => $prep,
        'steps' => $steps,
        'totals' => ['cho_g' => (int)round($totalCho), 'fluid_ml' => array_sum(array_map(fn($s) => (int)$s['fluid_ml'], $steps)), 'kcal_cho' => (int)round($totalCho * 4), 'effort_kcal' => nutrition_energy([['sport' => 'ride', 'duration' => $durMin * 60, 'kj' => $ftp !== null ? $ftp * $intensity / 100.0 * ($durMin / 60.0) * 3.6 : 0.0, 'distance' => 0]], $weight)],
        'discipline_note' => $disciplineNote,
        'ref' => NUTRITION_REF_SHORT,
    ];
}
