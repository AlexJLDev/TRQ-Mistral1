<?php
/**
 * TRQ – diagnostic automatique d'une activité.
 * Règles transparentes (pas d'IA) : chaque règle produit une phrase avec un niveau (good | info | warn) et une priorité.
 * La phrase la plus prioritaire devient le diagnostic principal ; les suivantes sont des observations secondaires.
 */

function dg_n(float $v, int $d = 0): string { return number_format($v, $d, ',', ' '); }
function dg_dur(float $s): string {
    $s = (int)round($s);
    if ($s < 90) return $s . ' s';
    if ($s < 3600) return (int)round($s / 60) . ' min';
    $h = intdiv($s, 3600); $m = (int)round(($s % 3600) / 60);
    if ($m === 60) { $h++; $m = 0; }
    return $h . ' h' . ($m ? ' ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) : '');
}
function dg_pace(float $kmh): string {
    if ($kmh <= 0) return '–';
    $s = (int)round(3600 / $kmh);
    return intdiv($s, 60) . ':' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT) . '/km';
}
function dg_avg(array $x): float { return $x ? array_sum($x) / count($x) : 0.0; }
function dg_nz(array $x): array { return array_values(array_filter($x, fn($y) => $y > 0)); }

/**
 * @return array{main: array{level:string,text:string}, extra: array<int, array{level:string,text:string}>}
 */
function activity_diagnostic(array $a, array $S, array $laps, string $lapSource, float $ftp, float $lthr): array {
    $ins = [];
    $add = function (string $topic, int $p, string $level, string $text) use (&$ins): void {
        if (!isset($ins[$topic]) || $ins[$topic]['p'] < $p) $ins[$topic] = ['p' => $p, 'level' => $level, 'text' => $text];
    };
    $f = fn(string $k) => (isset($a[$k]) && $a[$k] !== '') ? (float)$a[$k] : null;
    $dur = (int)($a['duration'] ?? 0);
    if ($dur < 600) return ['narrative' => '', 'simple' => 'En clair : séance trop courte pour dire grand chose — garde-la au chaud et remets-toi en selle ou en chaussures plus longtemps la prochaine fois.', 'main' => ['level' => 'info', 'text' => 'Sortie très courte (' . dg_dur($dur) . ') : trop brève pour une analyse fiable.'], 'extra' => []];

    $sport = (string)($a['sport'] ?? ''); $isRun = $sport === 'run';
    $np = $f('np'); $if = $f('if_'); $vi = $f('vi'); $dec = $f('decoupling'); $tss = $f('tss'); $avgHr = $f('avg_hr'); $cad = $f('cadence');
    $km = (float)($a['distance'] ?? 0) / 1000;
    $hasPower = $sport === 'ride' && $np !== null && $np > 0 && $ftp > 0;     // règles de puissance : vélo uniquement
    $pctFtp = $hasPower ? (int)round($np / $ftp * 100) : null;
    $w = clean($S['watts'] ?? []); $hr = clean($S['heartrate'] ?? []); $v = clean($S['velocity_smooth'] ?? []);
    $zones = !empty($a['zones']) ? (json_decode((string)$a['zones'], true) ?: null) : null;
    $wko = !empty($a['wko_metrics']) ? (json_decode((string)$a['wko_metrics'], true) ?: []) : [];

    /* ---------- 1. Records personnels sur 90 jours ---------- */
    $mmp = !empty($a['mmp']) ? (json_decode((string)$a['mmp'], true) ?: []) : [];
    if ($mmp && $sport !== '' && !empty($a['date'])) {
        $st = db()->prepare("SELECT mmp FROM activities WHERE sport=? AND id<>? AND mmp IS NOT NULL AND date < ? AND date >= date(?, '-90 days')");
        $st->execute([$sport, $a['id'], $a['date'], $a['date']]);
        $best = []; $prev = 0;
        foreach ($st->fetchAll() as $r) { $prev++; foreach (json_decode((string)$r['mmp'], true) ?: [] as $d => $val) $best[(int)$d] = max($best[(int)$d] ?? 0, (float)$val); }
        if ($prev >= 3) {
            $recs = [];
            foreach ($isRun ? [300, 1200, 3600] : [5, 60, 300, 1200, 3600] as $d) {
                if (isset($mmp[$d], $best[$d]) && $best[$d] > 0 && (float)$mmp[$d] > $best[$d] * 1.003) $recs[$d] = [(float)$mmp[$d], $best[$d]];
            }
            if ($recs) {
                krsort($recs);                                           // la durée la plus longue est la plus significative
                $d = array_key_first($recs); [$cur, $old] = $recs[$d];
                $lab = $d < 60 ? "$d s" : ($d < 3600 ? intdiv($d, 60) . ' min' : '1 h');
                $txt = $isRun
                    ? "Nouveau record sur $lab : " . dg_pace($cur * 3.6) . ' (précédent meilleur sur 90 jours : ' . dg_pace($old * 3.6) . ').'
                    : "Nouveau record sur $lab : " . dg_n($cur) . ' W (+' . dg_n($cur - $old) . ' W par rapport à vos 90 derniers jours).';
                $add('record', 95, 'good', $txt);
                if (count($recs) > 1) $add('record2', 62, 'good', 'Record également battu sur ' . (count($recs) - 1) . ' autre(s) durée(s).');
            }
        }
    }

    /* ---------- 2. Séance structurée (intervalles / tours) ---------- */
    $metric = $isRun ? 'avg_speed' : 'avg_w';
    $work = []; $rest = [];
    if ($lapSource !== 'split') foreach ($laps as $l) {
        $dl = (int)($l['duration'] ?? 0); $m = (float)($l[$metric] ?? 0);
        if (!empty($l['is_work'])) { if ($dl >= 45 && $m > 0) $work[] = $l; }
        elseif ($dl >= 20) $rest[] = $l;
    }
    $structured = false;
    if (count($work) >= 2 && count($rest) >= 1) {
        $mw = dg_avg(array_map(fn($l) => (float)$l[$metric], $work)); $mr = max(0.01, dg_avg(array_map(fn($l) => (float)($l[$metric] ?? 0), $rest)));
        $structured = $mw >= ($isRun ? 1.12 : 1.25) * $mr && ($isRun || $ftp <= 0 || $mw >= 0.7 * $ftp);
    }
    if ($structured) {
        $vals = array_map(fn($l) => (float)$l[$metric], $work); $n = count($vals);
        $first = $vals[0]; $last = $vals[$n - 1]; $mean = dg_avg($vals);
        $fade = $first > 0 ? ($first - $last) / $first * 100 : 0;
        $durs = array_map(fn($l) => (int)$l['duration'], $work); $md = dg_avg($durs);
        $sd = sqrt(dg_avg(array_map(fn($x) => ($x - $md) ** 2, $durs)));
        $dtxt = ($md > 0 && $sd / $md <= 0.25 ? 'de ' : 'd’environ ') . dg_dur($md);
        $int = $isRun ? dg_pace($mean) : ($ftp > 0 ? (int)round($mean / $ftp * 100) . '% de FTP' : dg_n($mean) . ' W');
        $head = "$n efforts $dtxt à $int";
        $gap = (int)round(abs($fade));
        if (abs($fade) <= 3)      $add('intervals', 85, 'good', "$head : régularité excellente (" . ($gap < 1 ? 'écart inférieur à 1%' : "écart de $gap%") . ' entre le premier et le dernier).');
        elseif ($fade < -3)       $add('intervals', 84, 'good', "$head : efforts en progression de $gap% jusqu’au dernier, départ prudent et réserve en fin de séance.");
        elseif ($fade <= 8)       $add('intervals', 83, 'info', "$head : légère baisse de $gap% sur le dernier effort.");
        else                      $add('intervals', 86, 'warn', "$head : baisse de $gap% au fil des efforts, intensité de départ trop élevée ou récupération trop courte.");
        $hrs = array_map(fn($l) => (float)($l['avg_hr'] ?? 0), $work);
        if ($hrs[0] > 0 && $hrs[$n - 1] > 0 && abs($fade) <= 5 && $hrs[$n - 1] - $hrs[0] >= 6)
            $add('hrfade', 70, 'warn', 'La fréquence cardiaque augmente de ' . (int)round($hrs[$n - 1] - $hrs[0]) . ' bpm entre le premier et le dernier effort à intensité équivalente : fatigue cardiaque.');
    }

    /* ---------- 3. Résistance à la fatigue (sorties longues) ---------- */
    if ($hasPower && $dur >= 7200 && count($w) >= 3 * 600) {
        $t = intdiv(count($w), 3);
        $b1 = best_means(array_slice($w, 0, $t), [300])[300] ?? 0; $b3 = best_means(array_slice($w, 2 * $t), [300])[300] ?? 0;
        if ($b1 > 0 && $b3 > 0) {
            $ratio = $b3 / $b1; $far = $km >= 5 ? ' après ' . dg_n($km) . ' km' : ' après ' . dg_dur($dur * 2 / 3);
            if ($ratio >= 0.97)     $add('fatigue', 80, 'good', 'Excellente résistance à la fatigue : ' . dg_n($b3) . ' W sur 5 min en fin de sortie contre ' . dg_n($b1) . " W au départ,$far à $pctFtp% de FTP.");
            elseif ($ratio >= 0.85) $add('fatigue', 55, 'info', 'Puissance max 5 min en baisse de ' . (int)round((1 - $ratio) * 100) . '% entre le début et la fin de sortie : fatigue normale sur ce format.');
            else                    $add('fatigue', 78, 'warn', 'Puissance max 5 min en chute de ' . (int)round((1 - $ratio) * 100) . '% entre le début et la fin : pensez à l’alimentation et à l’hydratation sur les sorties longues.');
        }
    }

    /* ---------- 4. Dérive cardiaque (séances continues) ---------- */
    if (!$structured && $dur >= 2400) {
        if ($hasPower && $dec !== null && $pctFtp !== null) {
            $ifv = $if ?? ($np / $ftp);
            if ($dec < -2)       $add('drift', 52, 'info', 'La fréquence cardiaque baisse de ' . dg_n(abs($dec), 1) . '% au cours de la séance : échauffement progressif ou effort très contrôlé.');
            elseif ($dec < 2)    $add('drift', 76, 'good', 'Dérive cardiaque inférieure à 2%. ' . ($ifv <= 0.55 ? 'Sortie très facile, idéale pour la récupération.' : ($ifv <= 0.88 ? "Endurance aérobie validée à $pctFtp% de FTP." : "Capacité aérobie solide à $pctFtp% de FTP (zone tempo/seuil).")));
            elseif ($dec < 5)    $add('drift', 66, 'info', 'Dérive cardiaque de ' . dg_n($dec, 1) . "%. Base aérobie correcte à $pctFtp% de FTP, avec encore de la marge de progression.");
            elseif ($dec < 8)    $add('drift', 79, 'warn', 'Dérive cardiaque de ' . dg_n($dec, 1) . "%. Fatigue, chaleur ou hydratation à surveiller à $pctFtp% de FTP.");
            else                 $add('drift', 82, 'warn', 'Dérive cardiaque élevée (' . dg_n($dec, 1) . "%). L’intensité dépasse votre capacité aérobie du moment : visez 5 à 10% de FTP en dessous.");
        }
        // Stabilité cardio-vasculaire sur le dernier tiers (rendement W/FC)
        if ($hasPower && $dur >= 3600 && count($w) >= 1800 && count($hr) >= count($w)) {
            $t = intdiv(count($w), 3); $ef = function (int $i) use ($w, $hr, $t): float { $h = dg_avg(dg_nz(array_slice($hr, $i, $t))); return $h > 0 ? dg_avg(array_slice($w, $i, $t)) / $h : 0.0; };
            $e2 = $ef($t); $e3 = $ef(2 * $t);
            if ($e2 > 0 && $e3 >= 0.98 * $e2) $add('stability', 54, 'good', 'Stabilité cardio-vasculaire optimale sur le dernier tiers de la sortie (rendement watts/FC inchangé).');
            elseif ($e2 > 0 && $e3 < 0.92 * $e2) $add('stability', 60, 'warn', 'Le rendement watts/FC chute de ' . (int)round((1 - $e3 / $e2) * 100) . '% sur le dernier tiers : fatigue cardio-vasculaire en fin de sortie.');
        }
    }

    /* ---------- 5. Course à pied : gestion d'allure et dérive ---------- */
    if ($isRun && $v && $dur >= 1200) {
        $mv = array_values(array_filter($v, fn($x) => $x > 0.8)); $n = count($mv);
        if ($n >= 600) {
            $h = intdiv($n, 2); $s1 = dg_avg(array_slice($mv, 0, $h)) * 3.6; $s2 = dg_avg(array_slice($mv, $h)) * 3.6;
            if ($s1 > 0 && $s2 > 0) {
                $d = round(3600 / $s2 - 3600 / $s1);                       // s/km : négatif = seconde moitié plus rapide
                if (!$structured) {
                    if ($d <= -5)      $add('pace', 80, 'good', 'Négative split : seconde moitié ' . (int)abs($d) . ' s/km plus rapide. Excellente gestion de l’allure.');
                    elseif ($d < 5)    $add('pace', 77, 'good', 'Allure très régulière : moins de 5 s/km d’écart entre les deux moitiés (' . dg_pace(($s1 + $s2) / 2) . ').');
                    elseif ($d <= 15)  $add('pace', 64, 'info', 'Légère baisse d’allure en seconde moitié (+' . (int)$d . ' s/km).');
                    else               $add('pace', 81, 'warn', 'Allure en chute de ' . (int)$d . ' s/km en seconde moitié : départ trop rapide ou fatigue.');
                }
            }
            if ($hr && count($hr) >= count($v) && !$structured) {
                $idx = array_keys(array_filter($v, fn($x) => $x > 0.8)); $half = intdiv(count($idx), 2); $e = [0.0, 0.0];
                foreach ([0, 1] as $k) {
                    $ii = array_slice($idx, $k ? $half : 0, $k ? null : $half); $sp = []; $hh = [];
                    foreach ($ii as $i) { $sp[] = $v[$i]; if ($hr[$i] > 0) $hh[] = $hr[$i]; }
                    $e[$k] = $hh ? dg_avg($sp) / dg_avg($hh) : 0.0;
                }
                if ($e[0] > 0 && $e[1] > 0) {
                    $drift = ($e[0] - $e[1]) / $e[0] * 100; $pl = $lthr > 0 && $avgHr ? ' à ' . (int)round($avgHr) . ' bpm (' . (int)round($avgHr / $lthr * 100) . '% LTHR)' : '';
                    if ($drift < 2)       $add('drift', 76, 'good', "Dérive cardiaque inférieure à 2%. Endurance solide$pl.");
                    elseif ($drift < 4)   $add('drift', 70, 'good', 'Dérive cardiaque de ' . dg_n($drift, 1) . "%. Endurance solide$pl.");
                    elseif ($drift < 7)   $add('drift', 66, 'info', 'Dérive cardiaque de ' . dg_n($drift, 1) . "%. Base aérobie correcte$pl.");
                    else                  $add('drift', 79, 'warn', 'Dérive cardiaque de ' . dg_n($drift, 1) . "%. Fatigue, chaleur ou hydratation à surveiller$pl.");
                }
            }
        }
    }

    /* ---------- 6. W', zones, régularité, relief, cadence ---------- */
    if (isset($wko['min_wbal_pct'])) {
        $mp = (float)$wko['min_wbal_pct'];
        if ($hasPower && $mp <= 10 && (int)($wko['time_above_ftp'] ?? 0) >= 60 && ($if ?? 0) >= 0.7) $add('wbal', 72, 'good', 'Réserve anaérobie (W’) quasiment épuisée (' . dg_n($mp, 0) . '% restant) : effort maximal atteint.');
    }
    if (!empty($wko['matches_burned']) && (int)$wko['matches_burned'] >= 3 && $hasPower)
        $add('matches', 65, 'info', (int)$wko['matches_burned'] . ' allumettes brûlées (efforts au-dessus de 120% de FTP) : séance très explosive.');
    if ($zones && count($zones) >= 7) {
        $tot = max(1, array_sum($zones)); $z = fn(int ...$i) => array_sum(array_map(fn($k) => $zones[$k] ?? 0, $i)) / $tot * 100;
        if ($dur >= 3600 && $z(1) >= 60 && !$structured) $add('zones', 60, 'info', 'Séance d’endurance classique : ' . (int)round($z(1)) . '% du temps en zone 2.');
        elseif (($zones[4] + $zones[5] + $zones[6]) >= 480 && !$structured) $add('zones', 62, 'info', dg_dur($zones[4] + $zones[5] + $zones[6]) . ' passées en zone 5 et au-delà (VO2max et au-delà).');
        elseif ($z(2) >= 45 && !$structured && $dur >= 2700) $add('zones', 63, 'warn', (int)round($z(2)) . '% du temps en zone 3 (« zone grise ») : alternez plutôt endurance douce et intervalles francs.');
        elseif (($zones[3]) >= 1500 && !$structured) $add('zones', 61, 'info', dg_dur($zones[3]) . ' passées en zone 4 (seuil) : stimulus de seuil solide.');
    }
    if ($hasPower && $vi !== null && $dur >= 2700 && !$structured) {
        if ($vi <= 1.05)      $add('vi', 53, 'good', 'Effort très régulier (indice de variabilité ' . dg_n($vi, 2) . ').');
        elseif ($vi >= 1.25)  $add('vi', 56, 'info', 'Effort très irrégulier (indice de variabilité ' . dg_n($vi, 2) . ') : nombreuses relances, parcours vallonné ou trafic.');
    }
    if (!empty($wko['vam']) && (int)($wko['elev_gain'] ?? 0) >= 400 && (int)$wko['vam'] >= 900)
        $add('climb', 57, 'info', 'Grimpée solide : ' . dg_n((float)$wko['elev_gain']) . ' m de dénivelé positif, VAM de ' . dg_n((float)$wko['vam']) . ' m/h.');
    if ($hasPower && $cad !== null && $cad > 0 && $cad < 70 && $dur >= 3600)
        $add('cadence', 40, 'info', 'Cadence moyenne basse (' . (int)round($cad) . ' rpm) : davantage de force par coup de pédale, donc plus de fatigue musculaire.');

    /* ---------- 7. Charge globale (toujours disponible) ---------- */
    if ($tss !== null && $tss > 0) {
        [$lab, $reco, $lvl] = $tss < 40 ? ['légère', 'aucune récupération particulière nécessaire', 'info']
            : ($tss < 80 ? ['modérée', 'récupération normale (≈ 12 h)', 'info']
            : ($tss < 150 ? ['soutenue', 'prévoyez environ 24 h de récupération', 'info']
            : ($tss < 250 ? ['élevée', 'prévoyez 24 à 48 h de récupération', 'info'] : ['très élevée', 'prévoyez 48 à 72 h de récupération', 'warn'])));
        if ($hasPower)    $head = 'Sortie de ' . dg_dur($dur) . " à $pctFtp% de FTP (IF " . dg_n($np / $ftp, 2) . ')';
        elseif ($isRun && $km > 0) $head = 'Course de ' . dg_n($km, 1) . ' km en ' . dg_dur($dur) . ' (' . dg_pace($km / ($dur / 3600)) . ')' . ($avgHr && $lthr > 0 ? ' à ' . (int)round($avgHr) . ' bpm (' . (int)round($avgHr / $lthr * 100) . '% LTHR)' : '');
        elseif ($avgHr && $lthr > 0) $head = 'Séance de ' . dg_dur($dur) . ' à ' . (int)round($avgHr) . ' bpm (' . (int)round($avgHr / $lthr * 100) . '% LTHR)';
        else $head = 'Séance de ' . dg_dur($dur);
        $add('load', $tss >= 200 ? 66 : 40, $lvl, "$head : charge $lab (TSS " . (int)round($tss) . "), $reco.");
    } else {
        $add('load', 10, 'info', 'Séance de ' . dg_dur($dur) . ($km > 0 ? ' sur ' . dg_n($km, 1) . ' km' : '') . ' : pas assez de données de puissance ou de fréquence cardiaque pour un diagnostic détaillé.');
    }

    /* ---------- 8. Résumé narratif (style coach, une relecture de la séance) ---------- */
    $narrative = '';
    {
        if ($hasPower)       $wh = dg_n($np, 0) . ' W sur ' . dg_dur($dur) . ' (IF ' . dg_n($np / $ftp, 2) . ')';
        elseif ($isRun && $km > 0) $wh = dg_n($km, 1) . ' km en ' . dg_dur($dur) . ' (' . dg_pace($km / ($dur / 3600)) . ')';
        elseif ($avgHr)      $wh = dg_dur($dur) . ' à ' . (int)round($avgHr) . ' bpm';
        else                 $wh = dg_dur($dur);
        $lv = fn(string $topic) => isset($ins[$topic]) ? $ins[$topic]['level'] : null;
        $kind = $structured ? 'Séance de travail structuré'
            : ($tss === null ? 'Séance'
            : ($tss < 40 ? 'Sortie de récupération'
            : ($tss < 150 ? 'Sortie endurance' : ($tss < 250 ? 'Grosse sortie' : 'Sortie très éprouvante'))));
        // adjectif de force selon l'intensité et la durée
        $qual = '';
        if (!$structured) {
            if ($tss !== null && $tss < 40)                        $qual = 'facile';
            elseif ($lv('drift') === 'good')                       $qual = 'solide';
        }
        // un enseignement repéré par le diagnostic (max 1)
        $point = '';
        if ($lv('record') === 'good')          $point = 'un nouveau record vient d’être signé';
        elseif ($lv('intervals') !== null && $structured === true)
            $point = $lv('intervals') === 'good' ? 'efforts parfaitement réguliers'
                : ($lv('intervals') === 'info' ? 'un léger essoufflement sur les derniers intervalles'
                : 'intensité de départ trop haute — des intervalles lâchés en fin de séance');
        elseif ($lv('drift') === 'good')       $point = 'dérive cardiaque maîtrisée — construction progressive de la capacité aérobie';
        elseif ($lv('drift') === 'warn')       $point = 'dérive cardiaque à surveiller : le cœur travaille plus pour le même effort';
        elseif ($lv('zones') === 'warn')       $point = 'beaucoup de temps en zone 3 — mieux vaut polariser endurance douce et intervalles francs';
        elseif ($lv('fatigue') === 'good')     $point = 'excellente résistance à la fatigue sur la fin de sortie';
        elseif ($lv('fatigue') === 'warn')     $point = 'grosse fatigue en fin de sortie — nourris et hydrate davantage sur ce format';
        elseif ($lv('pace') === 'warn')        $point = 'l’allure s’essouffle sur la fin — pars un ton en dessous';
        elseif ($lv('pace') === 'good')        $point = 'allure parfaitement gérée du début à la fin';
        $narrative = $kind . ($qual ? ' ' . $qual : '') . ' : ' . $wh . ($point ? ', ' . $point : '') . '.';
    }

    /* ---------- 9. Version « en clair » (débutants, sans jargon) ---------- */
    $simple = '';
    {
        // 1. Ce que tu as fait (vocabulaire courant)
        $pctL = ($avgHr && $lthr > 0) ? (int)round($avgHr / $lthr * 100) : null;
        if ($hasPower) {
            $what = $structured ? 'tu as fait une série d’efforts intenses avec des pauses entre chaque'
                : ($pctFtp <= 60 ? 'tu as bougé tranquillement, sans forcer'
                : ($pctFtp <= 75 ? 'tu as roulé à un rythme confortable qu’on peut tenir longtemps'
                : ($pctFtp <= 90 ? 'tu as roulé plus vite que le rythme confortable : ça demande un vrai effort'
                : 'tu as roulé très vite, proche de ton maximum')));
            if ($pctFtp !== null) $what .= ' (intensité ' . $pctFtp . '% de ta puissance de référence)';
        } elseif ($isRun && $km > 0) {
            $what = 'tu as couru ' . dg_n($km, 1) . ' km en ' . dg_dur($dur)
                . ($pctL === null ? '' : ($pctL <= 72 ? ' tranquillement (très bon rythme pour progresser en endurance)'
                : ($pctL <= 85 ? ' à un rythme soutenu' : ' rapidement, proche de ta limite')));
        } elseif ($avgHr) {
            $what = 'tu as bougé ' . dg_dur($dur) . ($pctL === null ? '' : ($pctL <= 72 ? ' tranquillement' : ($pctL <= 85 ? ' à un rythme soutenu' : ' rapidement')));
        } else {
            $what = 'tu as bougé ' . dg_dur($dur);
        }
        // 2. Comment ça s’est passé (lu sur les règles existantes)
        $goodN = count(array_filter($ins, fn($i) => $i['level'] === 'good'));
        $warnN = count(array_filter($ins, fn($i) => $i['level'] === 'warn'));
        $how = isset($ins['record']) ? 'tu as battu un de tes records, chapeau !'
            : ($warnN > 0 ? 'des signes de fatigue sont apparus en fin de séance — c’est normal sur ce type d’effort, mais ne les ignore pas'
            : ($goodN > 0 ? 'tout s’est bien passé du début à la fin, très propre' : 'rien d’anormal à signaler'));
        // 3. Ce qu’il faut faire ensuite
        $next = $tss === null ? ''
            : ($tss < 40 ? ' Pas de récupération spéciale nécessaire.'
            : ($tss < 80 ? ' Une bonne nuit de sommeil suffit pour récupérer.'
            : ($tss < 150 ? ' Bois et mange dans l’heure qui suit, et garde une journée tranquille demain si possible.'
            : ($tss < 250 ? ' Demain, privilégie le repos ou une sortie très légère.'
            : ' Accorde-toi deux jours faciles : ton corps a besoin de récupérer.'))));
        $simple = 'En clair : ' . $what . '. ' . ucfirst($how) . '.' . ($next);
    }

    uasort($ins, fn($x, $y) => $y['p'] <=> $x['p']);
    $list = array_values($ins);
    $main = array_shift($list);
    return ['narrative' => $narrative, 'simple' => $simple, 'main' => ['level' => $main['level'], 'text' => $main['text']], 'extra' => array_map(fn($i) => ['level' => $i['level'], 'text' => $i['text']], array_slice($list, 0, 3))];
}

/* ============================================================
   Forme du jour (tableau de bord) : lecture en clair de la forme,
   de la fatigue, de la fraîcheur, de la charge sur 7 jours et de
   la récupération (HRV, sommeil). Même philosophie que le
   diagnostic de séance : un texte court style coach + une version
   « en clair » sans jargon pour débuter.
   ============================================================ */
function form_narrative(?string $sport = null): array {
    $pm = pmc($sport);
    if (!$pm) return [];
    $keys = array_keys($pm);
    $last = $pm[end($keys)];
    $ctl = (float)$last['ctl']; $atl = (float)$last['atl']; $tsb = (float)$last['tsb'];
    if ($ctl < 5) return [];                       // historique trop court pour parler de « forme »

    $prevCtl = isset($keys[count($keys) - 8]) ? (float)$pm[$keys[count($keys) - 8]]['ctl'] : $ctl;
    $ctlDelta = $ctl - $prevCtl;                   // évolution de la forme sur 7 jours
    $r = $atl > 0 ? $atl / max($ctl, 1) : 0.0;     // fatigue / forme

    $pdo = db();
    $since = date('Y-m-d', strtotime('-7 days'));
    $st = $pdo->prepare('SELECT SUM(duration) d, SUM(tss) t, COUNT(*) nb FROM activities WHERE date >= ?' . ($sport ? ' AND sport=?' : ''));
    $st->execute($sport ? [$since, $sport] : [$since]);
    $w7 = $st->fetch() ?: [];
    $tss7 = (int)round((float)($w7['t'] ?? 0));
    $nb7 = (int)($w7['nb'] ?? 0);

    /* Restitution : HRV des 7 derniers jours vs base des 28 précédents, sommeil récent */
    $hrv7 = []; $hrvB = []; $sleep7 = [];
    $i = 0;
    foreach ($pdo->query('SELECT hrv, sleep_sec FROM wellness WHERE hrv IS NOT NULL OR sleep_sec IS NOT NULL ORDER BY date DESC LIMIT 35') as $w) {
        if ($w['hrv'] !== null && $w['hrv'] > 0) {
            if ($i < 7) $hrv7[] = (float)$w['hrv']; elseif (count($hrvB) < 28) $hrvB[] = (float)$w['hrv'];
        }
        if ($w['sleep_sec'] !== null && $w['sleep_sec'] > 0 && $i < 7) $sleep7[] = (float)$w['sleep_sec'];
        $i++;
    }
    $hrvAvg = $hrv7 ? round(array_sum($hrv7) / count($hrv7), 1) : null;
    $hrvBase = $hrvB ? round(array_sum($hrvB) / count($hrvB), 1) : null;
    $hrvDelta = ($hrvAvg !== null && $hrvBase !== null) ? round($hrvAvg - $hrvBase, 1) : null;
    $sleepH = $sleep7 ? round(array_sum($sleep7) / count($sleep7) / 3600, 1) : null;

    /* ---------- Phrases (vocabulaire courant, pas de jargon) ---------- */
    $fatigue = $r > 1.5 ? 'tu portes beaucoup trop de fatigue par rapport à ta forme'
        : ($r > 1.3 ? 'tu portes pas mal de fatigue : c’est une vraie phase de charge'
        : ($r > 0.8 ? 'tu portes une fatigue normale d’entraînement'
        : 'tu récupères : ta fatigue est basse par rapport à ta forme'));

    $fresh = $tsb > 25 ? 'tu es très frais — garde un peu de rythme pour ne pas perdre les bénéfices'
        : ($tsb > 5 ? 'tu es frais, un bon moment pour taper une séance de qualité'
        : ($tsb > -10 ? 'tu es dans une zone neutre, ni chargé ni très frais'
        : ($tsb > -30 ? 'tu portes de la charge : c’est là qu’on construit la forme'
        : 'ta fraîcheur est très basse, ton corps réclame une pause')));

    $trend = $ctlDelta > 1 ? 'ta forme monte doucement (+' . number_format($ctlDelta, 1, ',', ' ') . ' en 7 j)'
        : ($ctlDelta < -1 ? 'ta forme baisse doucement (' . number_format($ctlDelta, 1, ',', ' ') . ' en 7 j)'
        : 'ta forme est stable');

    $hrv = '';
    if ($hrvDelta !== null) {
        $hd = number_format(abs($hrvDelta), 0, ',', ' ');
        $hrv = $hrvDelta <= -8 ? 'ton HRV est nettement plus bas que d’habitude (−' . $hd . ' ms) : le corps tire la sonnette d’alarme'
            : ($hrvDelta < -3 ? 'ton HRV est un peu plus bas que d’habitude (−' . $hd . ' ms)'
            : ($hrvDelta < 3 ? 'ton HRV est stable'
            : 'ton HRV est plus haut que d’habitude (+' . $hd . ' ms) : bonne récupération'));
    } elseif ($hrvAvg !== null) {
        $hrv = 'ton HRV tourne autour de ' . (int)round($hrvAvg) . ' ms';
    }

    $sleep = '';
    if ($sleepH !== null) {
        $sh = str_replace('.', ',', (string)$sleepH);
        if ($sleepH < 6.5) $sleep = 'et tes nuits sont courtes (' . $sh . ' h en moyenne)';
        elseif ($sleepH >= 8) $sleep = 'et tes nuits sont solides (' . $sh . ' h en moyenne)';
    }

    $week = $nb7 > 0
        ? 'cette semaine : ' . $tss7 . ' TSS en ' . $nb7 . ' séance' . ($nb7 > 1 ? 's' : '') . ' (' . fmt_dur((int)($w7['d'] ?? 0)) . ')'
        : 'aucune séance enregistrée depuis 7 jours';

    $kind = $tsb > 25 ? 'Pleine fraîcheur'
        : ($tsb > 5 ? 'Frais et disponible'
        : ($tsb > -10 ? 'Rythme régulier'
        : ($tsb > -30 ? 'Belle charge d’entraînement' : 'Corps sous charge lourde')));

    $next = ($r > 1.5 || $tsb < -30) ? 'En priorité, mets une vraie journée de repos au planning, même si tu te sens bien.'
        : ($hrvDelta !== null && $hrvDelta <= -8 ? 'En priorité, dors et mange bien pendant 2-3 jours avant de remonter l’intensité.'
        : ($nb7 === 0 ? 'En priorité, remets un peu de mouvement cette semaine, même une sortie courte.'
        : ($tsb > 5 && $r <= 1.05 ? 'En priorité, profite-en pour mettre une belle séance de qualité.'
        : 'En priorité, continue comme ça : ton rythme actuel construit la forme.')));

    $main = $kind . ' : ' . $fatigue . ', ' . $trend . '.';
    $simple = 'En clair : ' . $fatigue . ' — ' . $fresh . '. ' . ucfirst($week)
        . ($hrv ? '. ' . ucfirst($hrv) : '')
        . ($sleep ? ' ' . $sleep : '')
        . '. ' . $next;

    $level = ($r > 1.5 || $tsb < -30 || ($hrvDelta !== null && $hrvDelta <= -8)) ? 'warn'
        : (($tsb > 5 && $r <= 1.05) || ($hrvDelta !== null && $hrvDelta >= 3 && $r < 1.4) ? 'good' : 'info');

    return ['level' => $level, 'main' => $main, 'simple' => $simple];
}
