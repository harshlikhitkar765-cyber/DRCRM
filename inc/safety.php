<?php
/* ---------------------------------------------------------------
   Prescription safety checks.

   Deliberately small and conservative. This is a safety net for
   typos and tired evenings — NOT a clinical decision system and
   NOT a substitute for the doctor's judgement. Every rule below is
   a well-known, textbook-level contraindication.
   --------------------------------------------------------------- */
declare(strict_types=1);
require_once __DIR__ . '/refdata.php';

/* Drug family map: which allergy keyword covers which drugs. */
/* Indian brand names -> generic ingredient. Without this the safety checks
   miss what doctors actually type (Ecosprin, Dolo, Glycomet...). */
function brand_map(): array {
    /* Now a database table so the doctor can add the brands their own
       patients bring in. Falls back to an empty map rather than fatal
       if the table is missing on an older install. */
    if (function_exists('brand_rows')) return brand_rows();
    return [];
}

function drug_families(): array {
    return [
        'nsaid'        => ['aspirin','ibuprofen','naproxen','diclofenac','aceclofenac','indomethacin','ketorolac','nimesulide'],
        'penicillin'   => ['amoxicillin','amoxyclav','ampicillin','penicillin','augmentin','cloxacillin','piperacillin'],
        'sulfa'        => ['sulfamethoxazole','cotrimoxazole','septran','bactrim','sulfasalazine','glimepiride','glipizide','furosemide'],
        'cephalosporin'=> ['cefixime','ceftriaxone','cefuroxime','cephalexin','cefpodoxime'],
        'macrolide'    => ['azithromycin','erythromycin','clarithromycin'],
        'quinolone'    => ['ciprofloxacin','levofloxacin','ofloxacin','norfloxacin'],
        'statin'       => ['atorvastatin','rosuvastatin','simvastatin'],
        'ace'          => ['ramipril','enalapril','lisinopril','perindopril'],
        'paracetamol'  => ['paracetamol','acetaminophen','dolo','crocin'],
    ];
}

/* Words a doctor might type in the allergy field -> family key. */
function allergy_aliases(): array {
    return [
        'nsaid'=>'nsaid','nsaids'=>'nsaid','aspirin'=>'nsaid','ibuprofen'=>'nsaid','diclofenac'=>'nsaid',
        'penicillin'=>'penicillin','pencillin'=>'penicillin','amoxicillin'=>'penicillin','augmentin'=>'penicillin',
        'sulfa'=>'sulfa','sulpha'=>'sulfa','sulfonamide'=>'sulfa','cotrimoxazole'=>'sulfa',
        'cephalosporin'=>'cephalosporin','cefixime'=>'cephalosporin',
        'macrolide'=>'macrolide','azithromycin'=>'macrolide','erythromycin'=>'macrolide',
        'quinolone'=>'quinolone','ciprofloxacin'=>'quinolone','fluoroquinolone'=>'quinolone',
        'statin'=>'statin','atorvastatin'=>'statin',
        'ace inhibitor'=>'ace','ace'=>'ace','ramipril'=>'ace',
        'paracetamol'=>'paracetamol','acetaminophen'=>'paracetamol',
    ];
}

/* Condition-based cautions. key = keyword in the patient's conditions. */
function condition_rules(): array {
    return [
        'ckd' => [
            ['drugs'=>['metformin'],       'level'=>'warn',   'msg'=>'Metformin in CKD — check eGFR. Avoid if eGFR below 30.'],
            ['drugs'=>['aspirin','ibuprofen','naproxen','diclofenac','aceclofenac','nimesulide'],
             'level'=>'danger','msg'=>'NSAIDs can worsen renal function in CKD.'],
        ],
        'kidney' => [
            ['drugs'=>['metformin'],'level'=>'warn','msg'=>'Metformin — verify renal function first.'],
        ],
        'asthma' => [
            ['drugs'=>['aspirin','ibuprofen','diclofenac','naproxen'],
             'level'=>'warn','msg'=>'NSAIDs may precipitate bronchospasm in asthma.'],
            ['drugs'=>['propranolol','atenolol'],
             'level'=>'danger','msg'=>'Non-selective beta blocker in asthma — can trigger severe bronchospasm.'],
        ],
        'copd' => [
            ['drugs'=>['propranolol'],'level'=>'warn','msg'=>'Beta blocker in COPD — prefer a cardioselective agent.'],
        ],
        'chf' => [
            ['drugs'=>['aspirin','ibuprofen','diclofenac','naproxen','aceclofenac'],
             'level'=>'danger','msg'=>'NSAIDs cause fluid retention — avoid in heart failure.'],
            ['drugs'=>['metformin'],'level'=>'warn','msg'=>'Metformin in CHF — watch for lactic acidosis risk.'],
        ],
        'heart failure' => [
            ['drugs'=>['aspirin','ibuprofen','diclofenac'],'level'=>'danger','msg'=>'NSAIDs worsen fluid overload in heart failure.'],
        ],
        'peptic ulcer' => [
            ['drugs'=>['aspirin','ibuprofen','diclofenac','naproxen','aceclofenac'],
             'level'=>'danger','msg'=>'NSAIDs in peptic ulcer disease — high GI bleed risk.'],
        ],
        'thrombocytopenia' => [
            ['drugs'=>['aspirin','clopidogrel','ibuprofen','diclofenac','naproxen'],
             'level'=>'danger','msg'=>'Antiplatelet/NSAID with low platelets — bleeding risk.'],
        ],
        'dengue' => [
            ['drugs'=>['aspirin','ibuprofen','diclofenac','naproxen','aceclofenac','nimesulide'],
             'level'=>'danger','msg'=>'NSAIDs are contraindicated in dengue — bleeding risk. Use paracetamol.'],
        ],
        'pregnan' => [
            ['drugs'=>['doxycycline','ciprofloxacin','levofloxacin','ofloxacin','atorvastatin','rosuvastatin','ramipril','enalapril','telmisartan','losartan'],
             'level'=>'danger','msg'=>'Contraindicated in pregnancy.'],
        ],
    ];
}

/* Pairwise interactions worth flagging in general practice. */
function interaction_rules(): array {
    return [
        [['aspirin','clopidogrel'],   'warn',  'Dual antiplatelet — confirm this is intended; bleeding risk.'],
        [['warfarin','aspirin'],      'danger','Warfarin + aspirin — major bleeding risk.'],
        [['warfarin','azithromycin'], 'warn',  'Macrolide raises INR — monitor closely.'],
        [['clarithromycin','atorvastatin'],'danger','Macrolide + statin — rhabdomyolysis risk.'],
        [['azithromycin','atorvastatin'],  'warn','Possible statin myopathy — counsel on muscle pain.'],
        [['telmisartan','ramipril'],  'danger','ACE inhibitor + ARB together — avoid; renal failure and hyperkalaemia.'],
        [['furosemide','ramipril'],   'warn',  'Watch for first-dose hypotension and electrolytes.'],
        [['metformin','furosemide'],  'warn',  'Diuretic may affect renal function — recheck creatinine.'],
        [['tramadol','sertraline'],   'danger','Serotonin syndrome risk.'],
        [['omeprazole','clopidogrel'],'warn',  'Omeprazole reduces clopidogrel effect — prefer pantoprazole.'],
    ];
}

function norm(string $s): string { return strtolower(trim($s)); }

/* Does a typed medicine name contain this ingredient? */
function med_has(string $medName, string $ingredient): bool {
    $n = norm($medName); $ing = norm($ingredient);
    if (str_contains($n, $ing)) return true;
    /* brand written instead of the generic? resolve it */
    foreach (brand_map() as $brand => $generic) {
        if ($generic === $ing && preg_match('/\b'.preg_quote($brand,'/').'/', $n)) return true;
    }
    return false;
}

/**
 * Run every check.
 * @return array<int,array{level:string,drug:string,msg:string}>
 */
function safety_check(array $meds, array $patient): array {
    $alerts = [];
    $names  = [];
    foreach ($meds as $m) {
        $n = trim((string)($m['name'] ?? ''));
        if ($n !== '') $names[] = $n;
    }
    if (!$names) return [];

    /* 1. Allergies */
    $fams    = drug_families();
    $aliases = allergy_aliases();
    $allergyRaw = norm((string)($patient['allergies'] ?? ''));
    if ($allergyRaw !== '') {
        foreach ($aliases as $word => $famKey) {
            if (!str_contains($allergyRaw, $word)) continue;
            foreach ($fams[$famKey] as $ingredient) {
                foreach ($names as $n) {
                    if (med_has($n, $ingredient)) {
                        $alerts[] = ['level'=>'danger','drug'=>$n,
                          'msg'=>'ALLERGY: patient is recorded as allergic to '.strtoupper($famKey).'.'];
                    }
                }
            }
        }
    }

    /* 2. Conditions */
    $cond = norm((string)($patient['conditions'] ?? ''));
    if ($cond !== '') {
        foreach (condition_rules() as $key => $rules) {
            if (!str_contains($cond, $key)) continue;
            foreach ($rules as $r) {
                foreach ($r['drugs'] as $ingredient) {
                    foreach ($names as $n) {
                        if (med_has($n, $ingredient)) {
                            $alerts[] = ['level'=>$r['level'],'drug'=>$n,'msg'=>$r['msg']];
                        }
                    }
                }
            }
        }
    }

    /* 3. Interactions */
    foreach (interaction_rules() as [$pair, $level, $msg]) {
        $hitA = null; $hitB = null;
        foreach ($names as $n) {
            if ($hitA === null && med_has($n, $pair[0])) { $hitA = $n; continue; }
            if ($hitB === null && med_has($n, $pair[1])) { $hitB = $n; }
        }
        if ($hitA && $hitB) {
            $alerts[] = ['level'=>$level,'drug'=>$hitA.' + '.$hitB,'msg'=>$msg];
        }
    }

    /* 4. Duplicate ingredient (e.g. Dolo 650 + Paracetamol 500) */
    foreach (drug_families() as $famKey => $ingredients) {
        $matched = [];
        foreach ($names as $n) {
            foreach ($ingredients as $ing) {
                if (med_has($n, $ing)) { $matched[$n] = true; break; }
            }
        }
        if (count($matched) > 1) {
            $alerts[] = ['level'=>'warn','drug'=>implode(' + ', array_keys($matched)),
              'msg'=>'Two drugs from the same group ('.strtoupper($famKey).') — check for double dosing.'];
        }
    }

    /* 5. Paediatric / geriatric dose sanity */
    $age = (int)($patient['age'] ?? 0);
    if ($age > 0 && $age < 12) {
        foreach ($names as $n) {
            foreach (['doxycycline','ciprofloxacin','levofloxacin','ofloxacin','aspirin'] as $ing) {
                if (med_has($n, $ing)) {
                    $alerts[] = ['level'=>'danger','drug'=>$n,
                      'msg'=>'Not recommended under 12 years'.(str_contains(norm($n),'aspirin')?" — Reye's syndrome risk.":'.')];
                }
            }
        }
    }

    /* de-duplicate identical alerts */
    $seen = []; $out = [];
    foreach ($alerts as $a) {
        $k = $a['level'].'|'.$a['drug'].'|'.$a['msg'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true; $out[] = $a;
    }
    /* danger first */
    usort($out, fn($x,$y) => ($x['level']==='danger'?0:1) <=> ($y['level']==='danger'?0:1));
    return $out;
}
