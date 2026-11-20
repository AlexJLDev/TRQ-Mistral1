<?php
/* ---------- Progression : rapport hebdomadaire, efficience TSS/RPE, ---------- */
/* ----------  alertes de surmenage et records personnels (P / FC)  ------------ */

const PR_POWER_DURS = [5, 60, 300, 1200];
const PR_HR_DURS    = [60, 300, 600, 1200];
const PR_POWER_LABELS = [5 => '5 s', 60 => '1 min', 300 => '5 min', 1200 => '20 min'];
const PR_HR_LABELS    = [60 => '1 min', 300 => '5 min', 600 => '10 min', 1200 => '20 min'];

/** Meilleure moyenne glissante d'une série 1 Hz sur une fenêtre (sommes glissantes, O(n)). */
function pr_best_mean(array $x, int $w): float {
    $n = count($x);
    if ($w <= 0 || $n < $w) return 0.0;
    $s = 0.0;
    for ($i = 0; $i < $w; $i++) $s += $x[$i];
    $best = $s / $w;
    for ($i = $w; $i < $n; $i++) {
        $s += $x[$i] - $x[$i - $w];
        $m = $s / $w;
        if ($m > $best) $best = $m;
    }
    return $best;
}

/** Met à jour un record : remplacé si > 0,5 au-dessus (anti-équivalence), l'ancien passe dans l'historique. */
function pr_update(array $store, int $dur, float $val, array $meta): array {
    $rec = $store[$dur] ?? null;
    if ($rec === null || $val > (float)($rec['val'] ?? 0) + 0.5) {
        $hist = is_array($rec['hist'] ?? null) ? $rec['hist'] : [];
        if ($rec) {
            $hist[] = ['val' => round((float)$rec['val'], 1), 'date' => $rec['date'] ?? null, 'act_id' => $rec['act_id'] ?? null];
            if (count($hist) > 10) $hist = array_slice($hist, -10);
        }
        $store[$dur] = ['val' => round($val, 1), 'date' => $meta['date'], 'act_id' => $meta['act_id'], 'name' => $meta['name'], 'hist' => $hist];
    }
    return $store;
}

/** Records personnels (puissance vélo, FC tous sports) — scan incrémental par lots (max 60 séances/requête pour éviter la latence), cache dans settings(pr_cache). */
function progress_prs(): array {
    $cache = json_decode((string)cfg('pr_cache', ''), true);
    if (!is_array($cache)) $cache = [];
    $lastId = (int)($cache['last_id'] ?? 0);
    $pw = is_array($cache['pw'] ?? null) ? $cache['pw'] : [];
    $ph = is_array($cache['hr'] ?? null) ? $cache['hr'] : [];

    $pdo = db();
    $st = $pdo->prepare('SELECT id, substr(date,1,10) d, sport, name, streams FROM activities WHERE id > ? AND streams IS NOT NULL ORDER BY id LIMIT 60');
    $st->execute([$lastId]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        $lastId = (int)$r['id'];
        $S = @json_decode((string)@gzuncompress((string)$r['streams']), true);
        if (!is_array($S)) continue;
        $meta = ['act_id' => $lastId, 'date' => $r['d'], 'name' => $r['name']];
        if (($r['sport'] ?? '') === 'ride' && !empty($S['watts'])) {
            $w = clean($S['watts']);
            foreach (PR_POWER_DURS as $d) {
                $m = pr_best_mean($w, $d);
                if ($m > 0) $pw = pr_update($pw, $d, $m, $meta);
            }
        }
        if (!empty($S['heartrate'])) {
            $h = array_values(array_filter(clean($S['heartrate']), fn($v) => $v > 0));
            foreach (PR_HR_DURS as $d) {
                $m = pr_best_mean($h, $d);
                if ($m > 0) $ph = pr_update($ph, $d, $m, $meta);
            }
        }
    }
    if ($rows) cfg_set('pr_cache', json_encode(['last_id' => $lastId, 'pw' => $pw, 'hr' => $ph]));
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM activities WHERE id > " . ((int)$lastId) . " AND streams IS NOT NULL")->fetchColumn();
    return ['pw' => $pw, 'hr' => $ph, 'pending' => $pending];
}

/** Agrégats par semaine (semaines lundi → dimanche, côté PHP) sur les $count dernières semaines. */
function progress_weeks(int $count = 10): array {
    $pdo = db();
    $monday = new DateTime('today');
    $monday->modify('monday this week');
    $start = (clone $monday)->modify('-' . ($count - 1) . ' weeks')->format('Y-m-d');

    $blank = fn(): array => ['sessions' => 0, 'time' => 0, 'km' => 0.0, 'km_sport' => ['ride' => 0.0, 'run' => 0.0, 'swim' => 0.0, 'other' => 0.0],
                             'tss' => 0.0, 'rpe_sum' => 0.0, 'rpe_n' => 0, 'feel' => [], 'weight_n' => 0, 'weight_sum' => 0.0];
    $weeks = [];
    for ($i = $count - 1; $i >= 0; $i--) {
        $k = (clone $monday)->modify("-$i weeks")->format('Y-m-d');
        $weeks[$k] = ['monday' => $k] + $blank();
    }

    $st = $pdo->prepare("SELECT substr(date,1,10) d, sport, duration, distance, tss, rpe, feel FROM activities WHERE substr(date,1,10) >= ?");
    $st->execute([$start]);
    foreach ($st->fetchAll() as $r) {
        $o = new DateTime($r['d']);
        $o->modify('monday this week');
        $k = $o->format('Y-m-d');
        if (!isset($weeks[$k])) continue;
        $w = &$weeks[$k];
        $w['sessions']++;
        $w['time'] += (int)$r['duration'];
        $km = (float)$r['distance'] / 1000;
        $w['km'] += $km;
        $sp = in_array($r['sport'], ['ride', 'run', 'swim'], true) ? $r['sport'] : 'other';
        $w['km_sport'][$sp] += $km;
        $w['tss'] += (float)$r['tss'];
        if ((int)$r['rpe'] > 0) { $w['rpe_sum'] += (int)$r['rpe']; $w['rpe_n']++; }
        if (!empty($r['feel'])) $w['feel'][$r['feel']] = ($w['feel'][$r['feel']] ?? 0) + 1;
        unset($w);
    }

    $st = $pdo->prepare("SELECT substr(date,1,10) d, weight FROM wellness WHERE weight IS NOT NULL AND weight > 0 AND substr(date,1,10) >= ?");
    $st->execute([$start]);
    foreach ($st->fetchAll() as $r) {
        $o = new DateTime($r['d']);
        $o->modify('monday this week');
        $k = $o->format('Y-m-d');
        if (!isset($weeks[$k])) continue;
        $weeks[$k]['weight_n']++;
        $weeks[$k]['weight_sum'] += (float)$r['weight'];
    }
    return $weeks;
}

/** Ratio TSS/RPE (efficience) : une valeur par séance (tss > 0 et rpe > 0), 90 derniers jours. */
function progress_efficiency(): array {
    $rows = db()->query("SELECT substr(date,1,10) d, tss, rpe FROM activities WHERE tss > 0 AND rpe > 0 AND substr(date,1,10) >= date('now','-90 days') ORDER BY date")->fetchAll();
    $vals = [];
    foreach ($rows as $r) $vals[] = ['date' => $r['d'], 'ratio' => round((float)$r['tss'] / (int)$r['rpe'], 1)];
    $t30 = date('Y-m-d', strtotime('-30 days'));
    $t60 = date('Y-m-d', strtotime('-60 days'));
    $a = $b = [];
    foreach ($vals as $v) {
        if ($v['date'] >= $t30) $a[] = $v['ratio'];
        elseif ($v['date'] >= $t60) $b[] = $v['ratio'];
    }
    $avgA = $a ? round(array_sum($a) / count($a), 1) : null;
    $avgB = $b ? round(array_sum($b) / count($b), 1) : null;
    return [
        'vals' => $vals,
        'avg30' => $avgA,
        'avgPrev' => $avgB,
        'delta' => ($avgA !== null && $avgB !== null) ? round($avgA - $avgB, 1) : null,
    ];
}

/** Alertes de surmenage : charge, TSB soutenu, ressenti, restitution (HRV / FC repos). */
function progress_alerts(): array {
    $pdo = db();
    $alerts = [];

    $pm = pmc();
    if ($pm) {
        $keys = array_keys($pm);
        $cur = $pm[end($keys)];
        $ctl = (float)$cur['ctl'];
        $atl = (float)$cur['atl'];
        if ($ctl > 10) {
            $r = $atl / $ctl;
            if ($r > 1.5) {
                $alerts[] = ['sev' => 'bad', 'title' => 'Charge excessive', 'msg' => sprintf("Fatigue/forme à %.2f (> 1,50) — ATL %.0f pour un CTL de %.0f. Allégez fortement cette semaine.", $r, $atl, $ctl)];
            } elseif ($r > 1.3) {
                $alerts[] = ['sev' => 'warn', 'title' => 'Montée de fatigue', 'msg' => sprintf("Fatigue/forme à %.2f — concentrez-vous sur la récupération (sommeil, apports glucidiques).", $r)];
            }
        }
        $neg14 = 0;
        $last14 = array_slice($pm, -14, null, true);
        foreach ($last14 as $e) if ((float)$e['tsb'] < -25) $neg14++;
        if ($neg14 >= 10) {
            $alerts[] = ['sev' => 'bad', 'title' => 'TSB très négatif soutenu', 'msg' => "Fraîcheur sous −25 pendant $neg14 des 14 derniers jours. Une semaine de recharge est nécessaire."];
        } elseif ($neg14 >= 7) {
            $alerts[] = ['sev' => 'warn', 'title' => 'TSB négatif prolongé', 'msg' => "Fraîcheur sous −25 pendant $neg14 des 14 derniers jours. Planifiez une journée ou deux très légères."];
        }
        $streak = 0;
        for ($i = count($keys) - 1; $i >= 0; $i--) {
            if ((float)$pm[$keys[$i]]['tss'] <= 0) break;
            $streak++;
            if ($streak > 30) break;
        }
        if ($streak >= 7) {
            $alerts[] = ['sev' => 'info', 'title' => "$streak jours d'affilée", 'msg' => "Pas de jour de repos depuis $streak jours. Même léger, un jour off favorise les adaptations."];
        }
    }

    $feels = $pdo->query("SELECT feel FROM activities WHERE feel IS NOT NULL AND feel != '' ORDER BY date DESC, id DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
    $hard = count(array_intersect($feels, ['dur', 'epuise']));
    if (count($feels) >= 3 && $hard >= 3) {
        $alerts[] = ['sev' => 'warn', 'title' => 'Ressentis dégradés', 'msg' => "$hard des 5 dernières séances sont « dur » ou « épuisé ». Vérifiez sommeil, stress et apports énergétiques."];
    }

    $well = $pdo->query("SELECT hrv, resting_hr FROM wellness WHERE hrv IS NOT NULL OR resting_hr IS NOT NULL ORDER BY date DESC LIMIT 35")->fetchAll();
    $hrv7 = $hrvB = $rhr7 = $rhrB = [];
    $i = 0;
    foreach ($well as $r) {
        if ($r['hrv'] !== null && $r['hrv'] > 0) {
            if ($i < 7) $hrv7[] = (float)$r['hrv']; elseif (count($hrvB) < 28) $hrvB[] = (float)$r['hrv'];
        }
        if ($r['resting_hr'] !== null && $r['resting_hr'] > 0) {
            if ($i < 7) $rhr7[] = (float)$r['resting_hr']; elseif (count($rhrB) < 28) $rhrB[] = (float)$r['resting_hr'];
        }
        $i++;
    }
    if (count($hrv7) >= 3 && count($hrvB) >= 5) {
        $m7 = array_sum($hrv7) / count($hrv7);
        $mB = array_sum($hrvB) / count($hrvB);
        if ($mB > 0 && $m7 < 0.95 * $mB) {
            $alerts[] = ['sev' => 'warn', 'title' => 'HRV en baisse', 'msg' => sprintf("HRV moyenne 7 j à %s ms vs %s ms en référence (−%d %%). Signe de stress cumulé.", round($m7), round($mB), round((1 - $m7 / $mB) * 100))];
        }
    }
    if (count($rhr7) >= 3 && count($rhrB) >= 5) {
        $m7 = array_sum($rhr7) / count($rhr7);
        $mB = array_sum($rhrB) / count($rhrB);
        if ($m7 > $mB + 5) {
            $alerts[] = ['sev' => 'warn', 'title' => 'FC repos élevée', 'msg' => sprintf("FC repos moyenne 7 j à %d bpm vs %d bpm en référence (+%d bpm). Fatigue ou maladie possible.", round($m7), round($mB), round($m7 - $mB))];
        }
    }

    $order = ['bad' => 0, 'warn' => 1, 'info' => 2];
    usort($alerts, fn($a, $b) => ($order[$a['sev']] <=> $order[$b['sev']]));
    return $alerts;
}
