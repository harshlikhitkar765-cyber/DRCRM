<?php
/* ==================================================================
   Second wave of medicines: the specialities the first 136 missed —
   gynae, paediatric syrups, ortho, urology, dermatology, ENT,
   psychiatry, injections and the common fixed-dose combinations.

       php tools/seed_drugs2.php

   Safe to re-run. A drug already in the list is left untouched, so
   edits and usage counts survive.

   The dose shown is a common adult starting point, not a
   recommendation. Review in Settings before real use.
   ================================================================== */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Command line only.\n"); }

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();

/* [name, generic, form, strength, dose, unit, when, freq, duration, note] */
$DRUGS = [
  /* --- paediatric syrups: the first pass had almost none --- */
  ['Syp Amoxicillin 125mg/5ml','amoxicillin','Syp','125mg/5ml','5','ml','After Food','TDS','5 days','Dose by weight'],
  ['Syp Azithromycin 200mg/5ml','azithromycin','Syp','200mg/5ml','5','ml','Before Food','OD','3 days','Dose by weight'],
  ['Syp Cefixime 50mg/5ml','cefixime','Syp','50mg/5ml','5','ml','After Food','BD','5 days','Dose by weight'],
  ['Syp Ibuprofen 100mg/5ml','ibuprofen','Syp','100mg/5ml','5','ml','After Food','TDS','3 days','Dose by weight'],
  ['Syp Ondansetron 2mg/5ml','ondansetron','Syp','2mg/5ml','5','ml','Before Food','TDS','2 days','For vomiting'],
  ['Syp Cetirizine 5mg/5ml','cetirizine','Syp','5mg/5ml','5','ml','Bedtime','OD','5 days',''],
  ['Syp Zinc 20mg/5ml','zinc','Syp','20mg/5ml','5','ml','After Food','OD','14 days','With ORS in diarrhoea'],
  ['Syp Albendazole 200mg/5ml','albendazole','Syp','200mg/5ml','10','ml','After Food','SOS','1 day','Single dose'],
  ['Syp Multivitamin Paediatric','multivitamin','Syp','','5','ml','After Food','OD','30 days',''],
  ['Syp Iron Paediatric','iron','Syp','','5','ml','After Food','OD','30 days',''],
  ['Drops Paracetamol 100mg/ml','paracetamol','Drops','100mg/ml','1','ml','After Food','TDS','3 days','Infant drops'],
  ['Drops Vitamin D3','cholecalciferol','Drops','400IU','2','drop','After Food','OD','30 days','Infants'],
  ['Drops Colic','simethicone','Drops','','1','ml','After Food','TDS','5 days','For infant colic'],

  /* --- gynaecology --- */
  ['Tab Mefenamic + Dicyclomine','mefenamic acid dicyclomine','Tab','','1','tab','After Food','BD','3 days','For period pain'],
  ['Tab Norethisterone 5mg','norethisterone','Tab','5mg','1','tab','After Food','BD','10 days',''],
  ['Tab Medroxyprogesterone 10mg','medroxyprogesterone','Tab','10mg','1','tab','After Food','OD','10 days',''],
  ['Tab Clomiphene 50mg','clomiphene','Tab','50mg','1','tab','After Food','OD','5 days',''],
  ['Tab Folic Acid 400mcg','folic acid','Tab','400mcg','1','tab','After Food','OD','30 days','Pre-conception and pregnancy'],
  ['Tab Calcium + D3 Pregnancy','calcium carbonate','Tab','500mg','1','tab','After Food','BD','30 days',''],
  ['Tab Iron + Folic Acid','iron folic acid','Tab','','1','tab','After Food','OD','30 days','Antenatal'],
  ['Tab Tranexamic + Mefenamic','tranexamic acid mefenamic','Tab','','1','tab','After Food','TDS','5 days','Heavy bleeding'],
  ['Cap Clotrimazole Vaginal','clotrimazole','Cap','500mg','1','cap','Bedtime','SOS','1 day','Single dose'],
  ['Tab Metronidazole 200mg','metronidazole','Tab','200mg','1','tab','After Food','TDS','7 days',''],

  /* --- orthopaedic and pain --- */
  ['Tab Aceclofenac + Paracetamol','aceclofenac paracetamol','Tab','','1','tab','After Food','BD','5 days',''],
  ['Tab Diclofenac + Paracetamol','diclofenac paracetamol','Tab','','1','tab','After Food','BD','5 days',''],
  ['Tab Etoricoxib 90mg','etoricoxib','Tab','90mg','1','tab','After Food','OD','5 days',''],
  ['Tab Thiocolchicoside 4mg','thiocolchicoside','Tab','4mg','1','cap','After Food','BD','5 days','Muscle relaxant'],
  ['Tab Chlorzoxazone + Paracetamol','chlorzoxazone paracetamol','Tab','','1','tab','After Food','TDS','5 days',''],
  ['Tab Tizanidine 2mg','tizanidine','Tab','2mg','1','tab','Bedtime','OD','5 days',''],
  ['Tab Calcium + Vitamin K2','calcium carbonate','Tab','','1','tab','After Food','OD','30 days',''],
  ['Tab Glucosamine 750mg','glucosamine','Tab','750mg','1','tab','After Food','BD','60 days','For osteoarthritis'],
  ['Gel Diclofenac','diclofenac','Cream','1%','1','application','Anytime','TDS','7 days','Apply locally'],
  ['Spray Pain Relief','methyl salicylate','Spray','','1','application','Anytime','TDS','7 days',''],

  /* --- urology --- */
  ['Tab Finasteride 5mg','finasteride','Tab','5mg','1','tab','After Food','OD','30 days',''],
  ['Tab Dutasteride 0.5mg','dutasteride','Tab','0.5mg','1','tab','After Food','OD','30 days',''],
  ['Tab Silodosin 8mg','silodosin','Tab','8mg','1','cap','After Food','OD','30 days',''],
  ['Tab Solifenacin 5mg','solifenacin','Tab','5mg','1','tab','After Food','OD','30 days','Overactive bladder'],
  ['Tab Potassium Citrate','potassium citrate','Tab','','10','ml','After Food','BD','15 days','For renal stone'],
  ['Tab Tamsulosin + Dutasteride','tamsulosin dutasteride','Tab','','1','cap','Bedtime','OD','30 days',''],
  ['Tab Sildenafil 50mg','sildenafil','Tab','50mg','1','tab','SOS','SOS','1 day',''],

  /* --- dermatology --- */
  ['Tab Terbinafine 250mg','terbinafine','Tab','250mg','1','tab','After Food','OD','14 days','Antifungal'],
  ['Tab Itraconazole 100mg','itraconazole','Tab','100mg','1','cap','After Food','BD','14 days',''],
  ['Tab Isotretinoin 20mg','isotretinoin','Tab','20mg','1','cap','After Food','OD','30 days','Not in pregnancy'],
  ['Tab Doxycycline 100mg Acne','doxycycline','Tab','100mg','1','tab','After Food','OD','30 days','For acne'],
  ['Cream Terbinafine 1%','terbinafine','Cream','1%','1','application','Anytime','BD','14 days',''],
  ['Cream Ketoconazole 2%','ketoconazole','Cream','2%','1','application','Anytime','BD','14 days',''],
  ['Cream Adapalene 0.1%','adapalene','Cream','0.1%','1','application','Bedtime','OD','30 days','For acne'],
  ['Cream Clobetasol 0.05%','clobetasol','Cream','0.05%','1','application','Anytime','BD','7 days','Short course only'],
  ['Cream Calamine','calamine','Cream','','1','application','Anytime','TDS','7 days','Soothing'],
  ['Cream Fusidic Acid 2%','fusidic acid','Cream','2%','1','application','Anytime','TDS','7 days',''],
  ['Shampoo Ketoconazole','ketoconazole','Spray','2%','1','application','Anytime','Weekly','4 weeks','For dandruff'],
  ['Lotion Benzyl Benzoate','benzyl benzoate','Cream','25%','1','application','Bedtime','SOS','1 day','For scabies'],

  /* --- ENT and eye --- */
  ['Tab Betahistine 8mg','betahistine','Tab','8mg','1','tab','After Food','TDS','15 days',''],
  ['Tab Prochlorperazine 5mg','prochlorperazine','Tab','5mg','1','tab','After Food','TDS','5 days','For vertigo'],
  ['Drops Ofloxacin Ear','ofloxacin','Drops','0.3%','3','drop','Anytime','BD','7 days',''],
  ['Drops Clotrimazole Ear','clotrimazole','Drops','1%','3','drop','Anytime','TDS','7 days',''],
  ['Drops Tobramycin Eye','tobramycin','Drops','0.3%','1','drop','Anytime','QID','7 days',''],
  ['Drops Olopatadine Eye','olopatadine','Drops','0.1%','1','drop','Anytime','BD','14 days','Allergic eye'],
  ['Drops Timolol Eye','timolol','Drops','0.5%','1','drop','Anytime','BD','30 days','For glaucoma'],
  ['Spray Mometasone Nasal','mometasone','Spray','','2','puff','Anytime','OD','30 days',''],
  ['Tab Pseudoephedrine + Cetirizine','pseudoephedrine cetirizine','Tab','','1','tab','After Food','BD','5 days',''],

  /* --- psychiatry and neurology --- */
  ['Tab Sertraline 50mg','sertraline','Tab','50mg','1','tab','After Food','OD','30 days',''],
  ['Tab Fluoxetine 20mg','fluoxetine','Tab','20mg','1','cap','After Food','OD','30 days',''],
  ['Tab Mirtazapine 15mg','mirtazapine','Tab','15mg','1','tab','Bedtime','OD','30 days',''],
  ['Tab Quetiapine 25mg','quetiapine','Tab','25mg','1','tab','Bedtime','OD','30 days',''],
  ['Tab Olanzapine 5mg','olanzapine','Tab','5mg','1','tab','Bedtime','OD','30 days',''],
  ['Tab Levetiracetam 500mg','levetiracetam','Tab','500mg','1','tab','After Food','BD','30 days',''],
  ['Tab Sodium Valproate 500mg','sodium valproate','Tab','500mg','1','tab','After Food','BD','30 days','Not in pregnancy'],
  ['Tab Phenytoin 100mg','phenytoin','Tab','100mg','1','cap','After Food','TDS','30 days',''],
  ['Tab Carbamazepine 200mg','carbamazepine','Tab','200mg','1','tab','After Food','BD','30 days',''],
  ['Tab Donepezil 5mg','donepezil','Tab','5mg','1','tab','Bedtime','OD','30 days',''],
  ['Tab Zolpidem 5mg','zolpidem','Tab','5mg','1','tab','Bedtime','OD','7 days','Short course only'],
  ['Tab Propranolol 40mg','propranolol','Tab','40mg','1','tab','After Food','BD','30 days',''],

  /* --- injections used in an OPD --- */
  ['Inj Diclofenac 75mg','diclofenac','Inj','75mg','1','unit','Anytime','SOS','1 day','Deep IM'],
  ['Inj Ceftriaxone 1g','ceftriaxone','Inj','1g','1','unit','Anytime','OD','3 days','IV/IM after test dose'],
  ['Inj Ondansetron 4mg','ondansetron','Inj','4mg','1','unit','Anytime','SOS','1 day','Slow IV'],
  ['Inj Pantoprazole 40mg','pantoprazole','Inj','40mg','1','unit','Anytime','OD','3 days','IV'],
  ['Inj Hydrocortisone 100mg','hydrocortisone','Inj','100mg','1','unit','Anytime','SOS','1 day','Emergency'],
  ['Inj Adrenaline 1mg','adrenaline','Inj','1mg','1','unit','Anytime','SOS','1 day','Anaphylaxis — IM'],
  ['Inj Tetanus Toxoid','tetanus toxoid','Inj','0.5ml','1','unit','Anytime','SOS','1 day','IM'],
  ['Inj Vitamin B12 1000mcg','vitamin b12','Inj','1000mcg','1','unit','Anytime','Weekly','4 weeks','Deep IM'],
  ['Inj Iron Sucrose','iron sucrose','Inj','100mg','1','unit','Anytime','Weekly','4 weeks','IV infusion'],
  ['Inj Insulin Regular','insulin','Inj','','10','unit','Before Food','TDS','30 days',''],

  /* --- common fixed-dose combinations Indian patients ask for --- */
  ['Tab Paracetamol + Ibuprofen','paracetamol ibuprofen','Tab','','1','tab','After Food','TDS','3 days',''],
  ['Tab Cefixime + Ofloxacin','cefixime ofloxacin','Tab','','1','tab','After Food','BD','5 days',''],
  ['Tab Amoxicillin + Lactobacillus','amoxicillin','Tab','','1','tab','After Food','TDS','5 days',''],
  ['Tab Rabeprazole + Domperidone','rabeprazole domperidone','Tab','','1','cap','Empty Stomach','OD','14 days',''],
  ['Tab Pantoprazole + Domperidone','pantoprazole domperidone','Tab','','1','cap','Empty Stomach','OD','14 days',''],
  ['Tab Glimepiride + Metformin','glimepiride metformin','Tab','','1','tab','Before Food','BD','30 days',''],
  ['Tab Sitagliptin + Metformin','sitagliptin metformin','Tab','','1','tab','After Food','BD','30 days',''],
  ['Tab Amlodipine + Atenolol','amlodipine atenolol','Tab','','1','tab','After Food','OD','30 days',''],
  ['Tab Losartan + HCTZ','losartan hydrochlorothiazide','Tab','','1','tab','After Food','OD','30 days',''],
  ['Tab Aspirin + Atorvastatin','aspirin atorvastatin','Tab','','1','cap','Bedtime','OD','30 days',''],
  ['Tab Levocetirizine + Montelukast','levocetirizine montelukast','Tab','','1','tab','Bedtime','OD','14 days',''],
  ['Tab Ofloxacin + Ornidazole','ofloxacin ornidazole','Tab','','1','tab','After Food','BD','5 days',''],

  /* --- miscellaneous --- */
  ['Tab Levocarnitine','levocarnitine','Tab','500mg','1','tab','After Food','BD','30 days',''],
  ['Tab Ursodeoxycholic 150mg','ursodeoxycholic acid','Tab','150mg','1','tab','After Food','BD','30 days',''],
  ['Tab Rifampicin 450mg','rifampicin','Tab','450mg','1','cap','Empty Stomach','OD','30 days','TB — DOTS'],
  ['Tab Isoniazid 300mg','isoniazid','Tab','300mg','1','tab','Empty Stomach','OD','30 days','TB — DOTS'],
  ['Tab Pyrazinamide 750mg','pyrazinamide','Tab','750mg','1','tab','After Food','OD','30 days','TB — DOTS'],
  ['Tab Ethambutol 800mg','ethambutol','Tab','800mg','1','tab','After Food','OD','30 days','TB — watch vision'],
  ['Tab Hydroxychloroquine 200mg','hydroxychloroquine','Tab','200mg','1','tab','After Food','BD','30 days','Eye check yearly'],
  ['Tab Methotrexate 7.5mg','methotrexate','Tab','7.5mg','1','tab','After Food','Weekly','12 weeks','Weekly — never daily'],
  ['Tab Warfarin 5mg','warfarin','Tab','5mg','1','tab','Bedtime','OD','30 days','Monitor INR'],
  ['Tab Ecosprin AV','aspirin atorvastatin','Tab','','1','cap','Bedtime','OD','30 days',''],
  ['ORS Ready Drink','oral rehydration salts','Syp','','200','ml','Anytime','SOS','3 days',''],
  ['Tab Ivabradine 7.5mg','ivabradine','Tab','7.5mg','1','tab','After Food','BD','30 days',''],
];

/* Brands for the new molecules, so a spoken brand still matches. */
$BRANDS = [
  'Zifi'=>'cefixime','Taxim-O'=>'cefixime','Monocef'=>'ceftriaxone',
  'Sporanox'=>'itraconazole','Lamisil'=>'terbinafine','Terbicip'=>'terbinafine',
  'Candid'=>'clotrimazole','Nizral'=>'ketoconazole','Deriva'=>'adapalene',
  'Zerodol-P'=>'aceclofenac paracetamol','Hifenac-P'=>'aceclofenac paracetamol',
  'Myospaz'=>'chlorzoxazone paracetamol','Etoshine'=>'etoricoxib',
  'Volini'=>'methyl salicylate','Moov'=>'methyl salicylate',
  'Urimax'=>'tamsulosin','Veltam'=>'tamsulosin','Dutas'=>'dutasteride',
  'Sertima'=>'sertraline','Nexito'=>'escitalopram','Zoloft'=>'sertraline',
  'Levipil'=>'levetiracetam','Encorate'=>'sodium valproate','Eptoin'=>'phenytoin',
  'Tegretol'=>'carbamazepine','Inderal'=>'propranolol',
  'Vertin'=>'betahistine','Stemetil'=>'prochlorperazine',
  'Otek'=>'ofloxacin','Tobracin'=>'tobramycin','Patanol'=>'olopatadine',
  'Nasivion'=>'xylometazoline','Nasonex'=>'mometasone',
  'Meftal-Spas'=>'mefenamic acid dicyclomine','Primolut'=>'norethisterone',
  'Regestrone'=>'norethisterone','Fol-5'=>'folic acid',
  'Galvus Met'=>'sitagliptin metformin','Janumet'=>'sitagliptin metformin',
  'Glycomet-GP'=>'glimepiride metformin','Amaryl-M'=>'glimepiride metformin',
  'Rantac-D'=>'rabeprazole domperidone','Pan-D'=>'pantoprazole domperidone',
  'Montair-LC'=>'levocetirizine montelukast','O2'=>'ofloxacin ornidazole',
  'Ecosprin-AV'=>'aspirin atorvastatin','Amlopres-AT'=>'amlodipine atenolol',
  'Ceftum'=>'cefuroxime','Augpen'=>'amoxicillin clavulanate',
  'Rcinex'=>'rifampicin','HCQS'=>'hydroxychloroquine','Folitrax'=>'methotrexate',
  'Warf'=>'warfarin','Udiliv'=>'ursodeoxycholic acid',
];

$before = (int)$pdo->query('SELECT COUNT(*) FROM drugs')->fetchColumn();
$bBefore = (int)$pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn();

$pdo->beginTransaction();
$has = $pdo->prepare('SELECT 1 FROM drugs WHERE name=? LIMIT 1');
$ins = $pdo->prepare('INSERT INTO drugs
  (name,generic,form,strength,def_dose,def_unit,def_when,def_freq,def_duration,notes,active)
  VALUES (?,?,?,?,?,?,?,?,?,?,1)');
foreach ($DRUGS as $d) {
    $has->execute([$d[0]]);
    if ($has->fetchColumn()) continue;
    $ins->execute([$d[0],$d[1],$d[2],$d[3],$d[4],$d[5],$d[6],$d[7],$d[8],$d[9]]);
}
$ib = $pdo->prepare('INSERT IGNORE INTO brands(brand,generic) VALUES(?,?)');
foreach ($BRANDS as $b => $g) $ib->execute([$b,$g]);
$pdo->commit();

printf("drugs  %d -> %d  (+%d)\n", $before, $n = (int)$pdo->query('SELECT COUNT(*) FROM drugs')->fetchColumn(), $n - $before);
printf("brands %d -> %d  (+%d)\n", $bBefore, $m = (int)$pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn(), $m - $bBefore);
echo "\nReview in Settings before real use. Doses are common adult starting\n";
echo "points, not recommendations.\n";
