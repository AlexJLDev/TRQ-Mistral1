<?php
/**
 * TRQ – rapprochement entre une séance planifiée (Workout Builder) et la sortie réalisée.
 *
 * Pourcentage de respect = 60 % structure (cibles étape par étape) + 20 % durée + 20 % charge (TSS).
 *  - structure : pour chaque étape, la moyenne réelle (fenêtre centrale, tolérante aux transitions) est comparée à la cible ;
 *    l'écart relatif à la plage cible donne un score de 1 (dans la cible ±3 %) à 0 (≥ 20 % d'écart). Les efforts pèsent 2× plus
 *    que les récupérations / échauffements. La séance réelle est décalée dans le temps pour retrouver le meilleur alignement.
 *  - durée : 100 % entre 95 % et 110 % de la durée prévue.
 *  - charge : 100 % si le TSS réalisé est à ±10 % du TSS prévu.
 * Sans structure (ou sans données de puissance / FC) : 50 % durée + 50 % charge.
 */

const PC_KIND_FR = ['warmup' => 'Échauffement', 'work' => 'Effort', 'rest' => 'Récupération', 'cooldown' => 'Retour au calme', 'steady' => 'Palier'];

function pc_mode(array $plan): string { return (($plan['tss_type'] ?? '') === 'hrtss') ? 'hr' : 'power'; }

/** Aplati la structure du builder (étapes + blocs répétés) en segments chronologiques. */
function pc_expand(array $structure): array {
    $segs = []; $count = [];
    $push = function (array $s, ?string $rep) use (&$segs, &$count): void {
        $kind = isset(PC_KIND_FR[$s['kind'] ?? '']) ? $s['kind'] : 'work';
        $lo = (float)($s['lo'] ?? 100); $hi = (float)($s['hi'] ?? $lo);
        $count[$kind] = ($count[$kind] ?? 0) + 1;
        $segs[] = ['kind' => $kind, 'dur' => max(1, (int)($s['dur'] ?? 60)), 'lo' => $lo, 'hi' => $hi, 'ramp' => !empty($s['ramp']), 'n' => $count[$kind], 'rep' => $rep];
    };
    foreach ($structure as $st) {
        if (isset($st['steps']) && is_array($st['steps'])) {
            $reps = max(1, (int)($st['reps'] ?? 1));
            for ($r = 1; $r <= $reps; $r++) foreach ($st['steps'] as $c) $push($c, $reps > 1 ? "$r/$reps" : null);
        } else $push($st, null);
    }
    foreach ($segs as &$g) $g['label'] = PC_KIND_FR[$g['kind']] . (($count[$g['kind']] ?? 1) > 1 ? ' ' . $g['n'] : '');
    return $segs;
}

/* ---------------- appariement planifié ↔ réalisé ---------------- */

/** Pour un jour : [id séance planifiée => ligne d'activité]. Liens manuels d'abord, puis même sport / durée la plus proche. */
function plan_pair_day(array $plans, array $acts, array $manualRows = [], array $reserved = []): array {
    $pairs = []; $used = $reserved;
    foreach ($plans as $p) {
        if (!empty($p['activity_id']) && isset($manualRows[$p['activity_id']])) { $pairs[$p['id']] = $manualRows[$p['activity_id']]; $used[$p['activity_id']] = true; }
    }
    $cands = [];
    foreach ($plans as $p) {
        if (isset($pairs[$p['id']]) || !empty($p['activity_id'])) continue;
        foreach ($acts as $a) {
            if (isset($used[$a['id']]) || ($a['sport'] ?? '') !== ($p['sport'] ?? '')) continue;
            $pd = max(1, (int)$p['duration']); $ad = (int)$a['duration'];
            $cands[] = [abs($ad - $pd) / max($pd, $ad, 1), $p, $a];
        }
    }
    usort($cands, fn($x, $y) => $x[0] <=> $y[0]);
    foreach ($cands as [$cost, $p, $a]) {
        if (isset($pairs[$p['id']]) || isset($used[$a['id']])) continue;
        $pairs[$p['id']] = $a; $used[$a['id']] = true;
    }
    return $pairs;
}

function plan_reserved_ids(): array {
    return array_fill_keys(array_map('strval', db()->query("SELECT activity_id FROM planned_workouts WHERE activity_id IS NOT NULL AND activity_id <> ''")->fetchAll(PDO::FETCH_COLUMN)), true);
}

/** Séance planifiée associée à cette activité (lien manuel, sinon appariement automatique du jour). */
function plan_pair_for_activity(array $a): ?array {
    $st = db()->prepare("SELECT * FROM planned_workouts WHERE activity_id = ?"); $st->execute([(string)$a['id']]);
    if ($p = $st->fetch()) return $p;
    $day = substr((string)$a['date'], 0, 10);
    $st = db()->prepare("SELECT * FROM planned_workouts WHERE date = ? AND (activity_id IS NULL OR activity_id = '')"); $st->execute([$day]);
    $plans = $st->fetchAll(); if (!$plans) return null;
    $st = db()->prepare('SELECT ' . ACT_COLS . ' FROM activities WHERE date >= ? AND date <= ?'); $st->execute([$day . 'T00:00:00', $day . 'T23:59:59']);
    $pairs = plan_pair_day($plans, $st->fetchAll(), [], plan_reserved_ids());
    foreach ($plans as $p) if (isset($pairs[$p['id']]) && (string)$pairs[$p['id']]['id'] === (string)$a['id']) return $p;
    return null;
}

/** Séances planifiées proches (±3 jours, même sport) non associées : propositions de liaison manuelle. */
function plan_candidates(array $a): array {
    $day = substr((string)$a['date'], 0, 10);
    $st = db()->prepare("SELECT * FROM planned_workouts WHERE date BETWEEN date(?, '-3 days') AND date(?, '+3 days') AND sport = ? AND (activity_id IS NULL OR activity_id = '') ORDER BY ABS(julianday(date) - julianday(?)), id");
    $st->execute([$day, $day, $a['sport'], $day]);
    $out = [];
    foreach ($st->fetchAll() as $p) {
        $s2 = db()->prepare('SELECT ' . ACT_COLS . ' FROM activities WHERE date >= ? AND date <= ?'); $s2->execute([$p['date'] . 'T00:00:00', $p['date'] . 'T23:59:59']);
        $pairs = plan_pair_day([$p], $s2->fetchAll(), [], plan_reserved_ids());
        if (isset($pairs[$p['id']])) continue;                                  // déjà rapprochée automatiquement d'une autre sortie
        $out[] = $p;
    }
    return array_slice($out, 0, 5);
}

/* ---------------- comparaison ---------------- */

function pc_verdict(?int $score): array {
    if ($score === null) return ['Non évaluable', 'info'];
    if ($score >= 90) return ['Séance respectée', 'good'];
    if ($score >= 75) return ['Bien suivie', 'good'];
    if ($score >= 60) return ['Partiellement suivie', 'warn'];
    return ['Écart important', 'bad'];
}

function pc_compare(array $plan, array $a, array $S, float $ftp, float $lthr, bool $withChart = true): array {
    $mode = pc_mode($plan); $isHr = $mode === 'hr'; $unit = $isHr ? 'bpm' : 'W';
    $structure = json_decode((string)($plan['structure'] ?? ''), true) ?: [];
    $segs = pc_expand($structure);
    $plannedDur = $segs ? array_sum(array_column($segs, 'dur')) : 0;
    $declared = (int)($plan['duration'] ?? 0) ?: $plannedDur;
    $actDur = (int)($a['duration'] ?? 0);

    /* durée */
    $r = $declared > 0 ? $actDur / $declared : 1.0;
    $sDur = ($r >= 0.95 && $r <= 1.10) ? 1.0 : ($r < 0.95 ? max(0.0, $r / 0.95) : max(0.0, 1 - ($r - 1.10)));
    /* charge */
    $pt = (float)($plan['tss'] ?? 0); $at = (float)($a['tss'] ?? 0); $sLoad = null;
    if ($pt > 0 && $at > 0) { $q = $at / $pt; $sLoad = ($q >= 0.9 && $q <= 1.1) ? 1.0 : max(0.0, 1 - (abs($q - 1) - 0.1) / 0.5); }

    /* structure : alignement temporel puis score étape par étape */
    $x = $isHr ? clean($S['heartrate'] ?? []) : clean($S['watts'] ?? []);
    $thr = $isHr ? $lthr : $ftp; $n = count($x);
    $note = null; $steps = []; $sStruct = null; $off = 0;
    if (!$segs) $note = 'Séance planifiée sans structure : comparaison de la durée et de la charge uniquement.';
    elseif ($n < 60 || array_sum($x) <= 0) $note = $isHr ? 'Pas de fréquence cardiaque enregistrée : comparaison de la durée et de la charge uniquement.' : 'Pas de données de puissance : comparaison de la durée et de la charge uniquement.';
    elseif ($thr <= 0) $note = $isHr ? 'Seuil de FC (LTHR) non renseigné dans les Paramètres.' : 'FTP non renseigné dans les Paramètres.';
    else {
        $P = [0.0]; foreach ($x as $v) $P[] = $P[count($P) - 1] + $v;
        $evalAt = function (int $off) use ($segs, $P, $n, $thr, $isHr): array {
            $t0 = 0; $rows = []; $tw = 0.0; $ts = 0.0;
            foreach ($segs as $g) {
                $d = $g['dur']; $loose = in_array($g['kind'], ['warmup', 'cooldown'], true);
                $trS = $isHr ? min(0.30 * $d, 120) : min(0.12 * $d, 20); $trE = $isHr ? min(0.08 * $d, 15) : min(0.12 * $d, 20);
                $s = $off + $t0 + $trS; $e = $off + $t0 + $d - $trE;
                if ($e - $s < 3) { $s = $off + $t0; $e = $off + $t0 + $d; }
                $a0 = (int)round(max(0, $s)); $b0 = (int)round(min($n, $e)); $cov = ($e - $s) > 0 ? max(0.0, min(1.0, ($b0 - $a0) / ($e - $s))) : 0.0;
                $mean = $b0 > $a0 ? ($P[$b0] - $P[$a0]) / ($b0 - $a0) : null;
                $frac = $d > 0 ? ((($trS + $d - $trE) / 2) / $d) : 0.5;
                if ($g['ramp']) { $mid = ($g['lo'] + ($g['hi'] - $g['lo']) * $frac) * $thr / 100; $loA = $hiA = $mid; $tol = 0.06 * $mid; }
                else { $loA = min($g['lo'], $g['hi']) * $thr / 100; $hiA = max($g['lo'], $g['hi']) * $thr / 100; $mid = ($loA + $hiA) / 2; $tol = 0.03 * $mid; }
                if ($loose) $tol = max($tol, 0.12 * $mid);
                $dev = 0.0; $score = 0.0;
                if ($mean !== null && $mid > 0) {
                    if ($mean < $loA - $tol) $dev = $mean - $loA; elseif ($mean > $hiA + $tol) $dev = $mean - $hiA;
                    if ($g['kind'] === 'rest' && $dev < 0) $dev = 0.0;               // récupérer plus doucement n'est pas une faute
                    if ($loose && $dev < 0) $dev *= 0.5;
                    $score = max(0.0, 1 - (abs($dev) / $mid) / 0.20) * $cov;
                }
                $w = $d * (in_array($g['kind'], ['work', 'steady'], true) ? 1.0 : 0.5);
                $tw += $w; $ts += $w * $score;
                $rows[] = ['start' => $t0, 'dur' => $d, 'lo' => $loA, 'hi' => $hiA, 'mean' => $mean, 'dev' => $dev, 'mid' => $mid, 'score' => $score, 'w' => $w, 'cov' => $cov];
                $t0 += $d;
            }
            return [$tw > 0 ? $ts / $tw : 0.0, $rows];
        };
        /* Décalage de départ : corrélation entre la FORME du profil prévu et la courbe réelle lissée (insensible à l'intensité :
           une séance trop faible ou trop forte ne fausse pas l'alignement). Profil plat -> repli sur le score, plage restreinte. */
        $win = $isHr ? 20 : 15; $half = intdiv($win, 2); $sm = [];
        for ($i = 0; $i < $n; $i++) { $a0 = max(0, $i - $half); $b0 = min($n, $i + $half + 1); $sm[$i] = ($P[$b0] - $P[$a0]) / ($b0 - $a0); }
        $pm = [];
        foreach ($segs as $g) for ($i = 0; $i < $g['dur']; $i++) $pm[] = $g['ramp'] ? $g['lo'] + ($g['hi'] - $g['lo']) * ($i + 0.5) / $g['dur'] : ($g['lo'] + $g['hi']) / 2;
        $mP = array_sum($pm) / count($pm); $varP = dg_avg(array_map(fn($v) => ($v - $mP) ** 2, $pm));
        $corrAt = function (int $o, int $stepS) use ($pm, $sm, $n): ?float {
            $m = count($pm); $cnt = 0; $sx = $sy = $sxx = $syy = $sxy = 0.0; $tot = 0;
            for ($i = 0; $i < $m; $i += $stepS) {
                $tot++; $j = $i + $o; if ($j < 0 || $j >= $n) continue;
                $x = $pm[$i]; $y = $sm[$j]; $cnt++; $sx += $x; $sy += $y; $sxx += $x * $x; $syy += $y * $y; $sxy += $x * $y;
            }
            if ($cnt < 0.6 * $tot || $cnt < 10) return null;
            $cov = $sxy - $sx * $sy / $cnt; $vx = $sxx - $sx * $sx / $cnt; $vy = $syy - $sy * $sy / $cnt;
            return ($vx > 1e-9 && $vy > 1e-9) ? $cov / sqrt($vx * $vy) : null;
        };
        $off = 0;
        if ($varP > 25) {
            $best = -2.0;
            for ($o = -60; $o <= 600; $o += 4) { $c = $corrAt($o, 3); if ($c !== null && ($c > $best + 1e-9 || (abs($c - $best) < 1e-9 && abs($o) < abs($off)))) { $best = $c; $off = $o; } }
            $c0 = $off;
            for ($o = $c0 - 4; $o <= $c0 + 4; $o++) { $c = $corrAt($o, 1); if ($c !== null && $c > $best + 1e-9) { $best = $c; $off = $o; } }
            if ($best < 0.2) $off = 0;                                  // aucune ressemblance : pas de décalage
        } else {
            $best = -1.0;
            for ($o = -30; $o <= 300; $o += 5) { [$sc] = $evalAt($o); if ($sc > $best + 1e-9 || (abs($sc - $best) < 1e-9 && abs($o) < abs($off))) { $best = $sc; $off = $o; } }
        }
        [$sStruct, $rows] = $evalAt($off);
        foreach ($segs as $i => $g) {
            $rw = $rows[$i]; $pct = $g['ramp'] ? round($g['lo']) . '→' . round($g['hi']) : (abs($g['lo'] - $g['hi']) < 0.05 ? round($g['lo']) : round(min($g['lo'], $g['hi'])) . '–' . round(max($g['lo'], $g['hi'])));
            $abs = $g['ramp'] ? round($g['lo'] * $thr / 100) . '→' . round($g['hi'] * $thr / 100) : (abs($g['lo'] - $g['hi']) < 0.05 ? round($g['lo'] * $thr / 100) : round(min($g['lo'], $g['hi']) * $thr / 100) . '–' . round(max($g['lo'], $g['hi']) * $thr / 100));
            $steps[] = ['label' => $g['label'], 'rep' => $g['rep'], 'kind' => $g['kind'], 'start' => $rw['start'], 'dur' => $g['dur'], 'target' => $pct . '% ' . ($isHr ? 'LTHR' : 'FTP') . ' · ' . $abs . ' ' . $unit,
                       'cov' => round($rw['cov'], 2), 'actual' => ($rw['mean'] === null || $rw['cov'] < 0.3) ? null : (int)round($rw['mean']), 'dev_pct' => $rw['mid'] > 0 ? round($rw['dev'] / $rw['mid'] * 100, 1) : 0.0, 'score' => round($rw['score'], 3), 'w' => $rw['w']];
        }
    }

    /* score global */
    $parts = ['structure' => $sStruct === null ? null : (int)round($sStruct * 100), 'duration' => (int)round($sDur * 100), 'load' => $sLoad === null ? null : (int)round($sLoad * 100)];
    if ($sStruct !== null) $score = $sLoad !== null ? 0.6 * $sStruct + 0.2 * $sDur + 0.2 * $sLoad : 0.7 * $sStruct + 0.3 * $sDur;
    else $score = $sLoad !== null ? 0.5 * $sDur + 0.5 * $sLoad : $sDur;
    $score = (int)round(max(0, min(1, $score)) * 100);
    [$verdict, $level] = pc_verdict($score);

    /* phrase de synthèse */
    $summary = '';
    if ($steps) {
        $ok = count(array_filter($steps, fn($s) => $s['score'] >= 0.85));
        $missing = count(array_filter($steps, fn($s) => $s['cov'] < 0.5));
        $worst = array_filter($steps, fn($s) => $s['cov'] >= 0.5); usort($worst, fn($p, $q) => ($p['score'] * $p['w']) <=> ($q['score'] * $q['w']));
        $w0 = null; foreach ($worst as $s) { if ($s['score'] < 0.85 && $s['actual'] !== null && abs($s['dev_pct']) >= 1) { $w0 = $s; break; } }
        $summary = "$ok étape" . ($ok > 1 ? 's' : '') . ' sur ' . count($steps) . ' dans la cible.';
        if ($missing) $summary .= " $missing étape" . ($missing > 1 ? 's' : '') . ' non réalisée' . ($missing > 1 ? 's' : '') . ' (séance interrompue ou plus courte).';
        if ($w0) $summary .= ' Plus gros écart : ' . $w0['label'] . ($w0['rep'] ? ' (' . $w0['rep'] . ')' : '') . ', ' . abs((int)round($w0['dev_pct'])) . '% ' . ($w0['dev_pct'] < 0 ? 'sous' : 'au-dessus de') . ' la cible.';
    }
    if ($r < 0.95) $summary .= ($summary ? ' ' : '') . 'Séance écourtée : ' . (int)round($r * 100) . '% de la durée prévue.';
    elseif ($r > 1.10) $summary .= ($summary ? ' ' : '') . 'Séance prolongée : ' . (int)round($r * 100) . '% de la durée prévue.';

    $res = ['score' => $score, 'verdict' => $verdict, 'level' => $level, 'parts' => $parts, 'summary' => $summary, 'note' => $note, 'offset' => $off, 'unit' => $unit, 'mode' => $mode,
            'dur' => ['planned' => $declared, 'actual' => $actDur], 'tss' => ['planned' => $pt, 'actual' => $at], 'steps' => $steps, 'chart' => null];

    /* données du graphique : cible (bande + milieu) et réalisé lissé, sur l'axe de temps de la sortie */
    if ($withChart && $segs && $n >= 60 && $thr > 0) {
        $N = max($n, $off + $plannedDur, 1); $step = max(1, (int)ceil($N / 800));
        $pl = array_fill(0, $N, null); $lo = $pl; $hi = $pl; $t0 = 0;
        foreach ($segs as $g) {
            for ($i = 0; $i < $g['dur']; $i++) {
                $k = $off + $t0 + $i; if ($k < 0 || $k >= $N) continue;
                $f = $g['ramp'] ? ($g['lo'] + ($g['hi'] - $g['lo']) * ($i + 0.5) / $g['dur']) : null;
                $pl[$k] = $f !== null ? $f * $thr / 100 : (($g['lo'] + $g['hi']) / 2) * $thr / 100;
                $lo[$k] = $f !== null ? $f * $thr / 100 : min($g['lo'], $g['hi']) * $thr / 100; $hi[$k] = $f !== null ? $f * $thr / 100 : max($g['lo'], $g['hi']) * $thr / 100;
            }
            $t0 += $g['dur'];
        }
        $ds = function (array $arr, bool $nullable) use ($N, $step): array {
            $o = [];
            for ($i = 0; $i < $N; $i += $step) {
                $sum = 0.0; $c = 0;
                for ($j = $i; $j < min($N, $i + $step); $j++) if (isset($arr[$j])) { $sum += $arr[$j]; $c++; }
                $o[] = $c ? (int)round($sum / $c) : null;
            }
            return $o;
        };
        $res['chart'] = ['step' => $step, 'n' => $N, 'off' => $off, 'plannedDur' => $plannedDur, 'planned' => $ds($pl, true), 'lo' => $ds($lo, true), 'hi' => $ds($hi, true), 'actual' => $ds($sm, true)];
    }
    return $res;
}

/* ---------------- cache (calendrier) ---------------- */

function plan_cmp_key(array $plan, array $act, float $ftp, float $lthr): string {
    return md5(json_encode([$plan['structure'] ?? '', $plan['duration'] ?? 0, $plan['tss'] ?? 0, $plan['tss_type'] ?? '', $act['id'], $act['duration'] ?? 0, $act['tss'] ?? 0, round($ftp), round($lthr)]));
}
function plan_cmp_store(array $plan, array $act, array $res): void {
    $sum = ['score' => $res['score'], 'verdict' => $res['verdict'], 'level' => $res['level'], 'act' => (string)$act['id']];
    db()->prepare('UPDATE planned_workouts SET cmp_key=?, cmp_json=? WHERE id=?')
        ->execute([plan_cmp_key($plan, $act, (float)cfg('ftp', 250), (float)cfg('lthr', 0)), json_encode($sum), $plan['id']]);
}
/** Résumé pour le calendrier : lu dans le cache si valide, sinon calculé (nombre de calculs limité par requête). */
function plan_cmp_summary(array $plan, array $act, int &$budget): ?array {
    $ftp = (float)cfg('ftp', 250); $lthr = (float)cfg('lthr', 0);
    if (($plan['cmp_key'] ?? '') === plan_cmp_key($plan, $act, $ftp, $lthr) && !empty($plan['cmp_json'])) return json_decode($plan['cmp_json'], true) ?: null;
    if ($budget <= 0) return null;
    $budget--;
    $S = [];
    if (!empty($plan['structure'])) {
        $st = db()->prepare('SELECT streams FROM activities WHERE id=?'); $st->execute([$act['id']]); $blob = $st->fetchColumn();
        if ($blob) $S = json_decode(gzuncompress($blob), true) ?: [];
    }
    $res = pc_compare($plan, $act, $S, $ftp, $lthr, false);
    plan_cmp_store($plan, $act, $res);
    return ['score' => $res['score'], 'verdict' => $res['verdict'], 'level' => $res['level'], 'act' => (string)$act['id']];
}
