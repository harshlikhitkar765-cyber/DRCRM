<?php
/* ==================================================================
   Load a full Indian OPD clinical vocabulary:
   drugs, diagnoses, tests and advice.

       php tools/seed_clinical.php

   Safe to re-run. Existing rows are left alone (INSERT IGNORE), so
   anything the doctor has edited or added is never overwritten and
   usage counts are preserved.

   IMPORTANT: the dose, timing and duration on each row are the common
   adult starting points, not a recommendation. They are there so the
   form fills in something sensible; the doctor changes them per
   patient. Review the list once in Settings before real use.
   ================================================================== */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Command line only.\n"); }
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';

$pdo = db();

/* ---------------------------------------------------------------
   DRUGS  [name, generic, form, strength, dose, unit, when, freq, duration, note]
   --------------------------------------------------------------- */
$DRUGS = [
  /* --- pain, fever, inflammation --- */
  ['Tab Paracetamol 500mg','paracetamol','Tab','500mg','1','tab','After Food','TDS','3 days','Only if fever above 100F'],
  ['Tab Paracetamol 650mg','paracetamol','Tab','650mg','1','tab','After Food','TDS','3 days','Only if fever above 100F'],
  ['Syp Paracetamol 250mg/5ml','paracetamol','Syp','250mg/5ml','5','ml','After Food','TDS','3 days','Paediatric — dose by weight'],
  ['Tab Ibuprofen 400mg','ibuprofen','Tab','400mg','1','tab','After Food','BD','3 days','Avoid on empty stomach'],
  ['Tab Aceclofenac 100mg','aceclofenac','Tab','100mg','1','tab','After Food','BD','5 days',''],
  ['Tab Diclofenac 50mg','diclofenac','Tab','50mg','1','tab','After Food','BD','3 days',''],
  ['Tab Mefenamic Acid 500mg','mefenamic acid','Tab','500mg','1','tab','After Food','BD','3 days',''],
  ['Tab Tramadol 50mg','tramadol','Tab','50mg','1','tab','After Food','BD','3 days',''],
  ['Tab Nimesulide 100mg','nimesulide','Tab','100mg','1','tab','After Food','BD','3 days','Short course only'],
  ['Tab Serratiopeptidase 10mg','serratiopeptidase','Tab','10mg','1','tab','Before Food','BD','5 days',''],

  /* --- antibiotics --- */
  ['Tab Amoxicillin 500mg','amoxicillin','Tab','500mg','1','tab','After Food','TDS','5 days',''],
  ['Tab Amoxiclav 625mg','amoxicillin clavulanate','Tab','625mg','1','tab','After Food','BD','5 days',''],
  ['Tab Azithromycin 500mg','azithromycin','Tab','500mg','1','tab','Before Food','OD','3 days',''],
  ['Tab Cefixime 200mg','cefixime','Tab','200mg','1','tab','After Food','BD','5 days',''],
  ['Tab Cefpodoxime 200mg','cefpodoxime','Tab','200mg','1','tab','After Food','BD','5 days',''],
  ['Tab Cephalexin 500mg','cephalexin','Tab','500mg','1','tab','After Food','QID','5 days',''],
  ['Tab Ciprofloxacin 500mg','ciprofloxacin','Tab','500mg','1','tab','After Food','BD','5 days',''],
  ['Tab Levofloxacin 500mg','levofloxacin','Tab','500mg','1','tab','After Food','OD','5 days',''],
  ['Tab Ofloxacin 200mg','ofloxacin','Tab','200mg','1','tab','After Food','BD','5 days',''],
  ['Tab Doxycycline 100mg','doxycycline','Tab','100mg','1','tab','After Food','BD','7 days','Take with plenty of water'],
  ['Tab Metronidazole 400mg','metronidazole','Tab','400mg','1','tab','After Food','TDS','5 days','No alcohol'],
  ['Tab Ornidazole 500mg','ornidazole','Tab','500mg','1','tab','After Food','BD','5 days',''],
  ['Tab Nitrofurantoin 100mg','nitrofurantoin','Tab','100mg','1','tab','After Food','BD','5 days','For urinary infection'],
  ['Tab Cotrimoxazole DS','cotrimoxazole','Tab','800/160mg','1','tab','After Food','BD','5 days',''],
  ['Tab Rifaximin 400mg','rifaximin','Tab','400mg','1','tab','After Food','BD','5 days',''],
  ['Tab Fluconazole 150mg','fluconazole','Tab','150mg','1','tab','After Food','Weekly','2 weeks','Antifungal'],
  ['Tab Albendazole 400mg','albendazole','Tab','400mg','1','tab','After Food','SOS','1 day','Single dose, repeat in 2 weeks'],
  ['Tab Ivermectin 12mg','ivermectin','Tab','12mg','1','tab','Empty Stomach','SOS','1 day',''],

  /* --- acidity and gut --- */
  ['Tab Pantoprazole 40mg','pantoprazole','Tab','40mg','1','tab','Empty Stomach','OD','14 days','30 minutes before breakfast'],
  ['Cap Omeprazole 20mg','omeprazole','Cap','20mg','1','cap','Empty Stomach','OD','14 days',''],
  ['Tab Rabeprazole 20mg','rabeprazole','Tab','20mg','1','tab','Empty Stomach','OD','14 days',''],
  ['Tab Esomeprazole 40mg','esomeprazole','Tab','40mg','1','tab','Empty Stomach','OD','14 days',''],
  ['Tab Ranitidine 150mg','ranitidine','Tab','150mg','1','tab','After Food','BD','7 days',''],
  ['Syp Antacid Gel','antacid','Syp','','10','ml','After Food','TDS','7 days',''],
  ['Syp Sucralfate','sucralfate','Syp','','10','ml','Before Food','TDS','7 days',''],
  ['Tab Domperidone 10mg','domperidone','Tab','10mg','1','tab','Before Food','TDS','3 days',''],
  ['Tab Ondansetron 4mg','ondansetron','Tab','4mg','1','tab','Before Food','TDS','2 days','For vomiting'],
  ['Tab Metoclopramide 10mg','metoclopramide','Tab','10mg','1','tab','Before Food','TDS','3 days',''],
  ['Tab Dicyclomine 20mg','dicyclomine','Tab','20mg','1','tab','After Food','TDS','3 days','For cramps'],
  ['Tab Drotaverine 80mg','drotaverine','Tab','80mg','1','tab','After Food','BD','3 days',''],
  ['Tab Hyoscine 10mg','hyoscine','Tab','10mg','1','tab','After Food','TDS','3 days',''],
  ['ORS sachets','oral rehydration salts','Sachet','','1','sachet','Anytime','SOS','3 days','One sachet in 1 litre water'],
  ['Cap Probiotic','probiotic','Cap','','1','cap','After Food','BD','7 days',''],
  ['Tab Loperamide 2mg','loperamide','Tab','2mg','1','tab','After Food','SOS','2 days','Not in fever or blood in stool'],
  ['Syp Lactulose','lactulose','Syp','','15','ml','Bedtime','OD','7 days','For constipation'],
  ['Tab Bisacodyl 5mg','bisacodyl','Tab','5mg','1','tab','Bedtime','OD','3 days',''],
  ['Tab Mebeverine 135mg','mebeverine','Tab','135mg','1','tab','Before Food','TDS','14 days','For IBS'],
  ['Tab Ursodeoxycholic 300mg','ursodeoxycholic acid','Tab','300mg','1','tab','After Food','BD','30 days',''],

  /* --- diabetes --- */
  ['Tab Metformin 500mg','metformin','Tab','500mg','1','tab','After Food','BD','30 days',''],
  ['Tab Metformin 1000mg','metformin','Tab','1000mg','1','tab','After Food','BD','30 days',''],
  ['Tab Glimepiride 1mg','glimepiride','Tab','1mg','1','tab','Before Food','OD','30 days',''],
  ['Tab Glimepiride 2mg','glimepiride','Tab','2mg','1','tab','Before Food','OD','30 days',''],
  ['Tab Gliclazide 80mg','gliclazide','Tab','80mg','1','tab','Before Food','OD','30 days',''],
  ['Tab Sitagliptin 100mg','sitagliptin','Tab','100mg','1','tab','After Food','OD','30 days',''],
  ['Tab Vildagliptin 50mg','vildagliptin','Tab','50mg','1','tab','After Food','BD','30 days',''],
  ['Tab Teneligliptin 20mg','teneligliptin','Tab','20mg','1','tab','After Food','OD','30 days',''],
  ['Tab Dapagliflozin 10mg','dapagliflozin','Tab','10mg','1','tab','Before Food','OD','30 days',''],
  ['Tab Pioglitazone 15mg','pioglitazone','Tab','15mg','1','tab','After Food','OD','30 days',''],
  ['Tab Acarbose 50mg','acarbose','Tab','50mg','1','tab','With Food','TDS','30 days',''],
  ['Inj Insulin Glargine','insulin glargine','Inj','','10','unit','Bedtime','OD','30 days','Titrate to fasting sugar'],
  ['Inj Human Mixtard 30/70','insulin','Inj','','10','unit','Before Food','BD','30 days',''],

  /* --- blood pressure and heart --- */
  ['Tab Amlodipine 5mg','amlodipine','Tab','5mg','1','tab','After Food','OD','30 days',''],
  ['Tab Amlodipine 10mg','amlodipine','Tab','10mg','1','tab','After Food','OD','30 days',''],
  ['Tab Telmisartan 40mg','telmisartan','Tab','40mg','1','tab','After Food','OD','30 days',''],
  ['Tab Telmisartan + HCTZ','telmisartan hydrochlorothiazide','Tab','40/12.5mg','1','tab','After Food','OD','30 days',''],
  ['Tab Losartan 50mg','losartan','Tab','50mg','1','tab','After Food','OD','30 days',''],
  ['Tab Ramipril 5mg','ramipril','Tab','5mg','1','tab','After Food','OD','30 days','Watch for dry cough'],
  ['Tab Enalapril 5mg','enalapril','Tab','5mg','1','tab','After Food','OD','30 days',''],
  ['Tab Metoprolol 25mg','metoprolol','Tab','25mg','1','tab','After Food','OD','30 days',''],
  ['Tab Atenolol 50mg','atenolol','Tab','50mg','1','tab','After Food','OD','30 days',''],
  ['Tab Carvedilol 3.125mg','carvedilol','Tab','3.125mg','1','tab','After Food','BD','30 days',''],
  ['Tab Furosemide 40mg','furosemide','Tab','40mg','1','tab','Before Food','OD','15 days','Morning dose'],
  ['Tab Torsemide 10mg','torsemide','Tab','10mg','1','tab','Before Food','OD','15 days',''],
  ['Tab Spironolactone 25mg','spironolactone','Tab','25mg','1','tab','After Food','OD','30 days',''],
  ['Tab Hydrochlorothiazide 12.5mg','hydrochlorothiazide','Tab','12.5mg','1','tab','After Food','OD','30 days',''],
  ['Tab Aspirin 75mg','aspirin','Tab','75mg','1','tab','After Food','OD','30 days',''],
  ['Tab Clopidogrel 75mg','clopidogrel','Tab','75mg','1','tab','After Food','OD','30 days',''],
  ['Tab Atorvastatin 10mg','atorvastatin','Tab','10mg','1','tab','Bedtime','OD','30 days',''],
  ['Tab Atorvastatin 20mg','atorvastatin','Tab','20mg','1','tab','Bedtime','OD','30 days',''],
  ['Tab Rosuvastatin 10mg','rosuvastatin','Tab','10mg','1','tab','Bedtime','OD','30 days',''],
  ['Tab Nitroglycerin 2.6mg','nitroglycerin','Tab','2.6mg','1','tab','After Food','BD','30 days',''],
  ['Tab Ivabradine 5mg','ivabradine','Tab','5mg','1','tab','After Food','BD','30 days',''],

  /* --- respiratory and allergy --- */
  ['Tab Cetirizine 10mg','cetirizine','Tab','10mg','1','tab','Bedtime','OD','5 days','May cause drowsiness'],
  ['Tab Levocetirizine 5mg','levocetirizine','Tab','5mg','1','tab','Bedtime','OD','5 days',''],
  ['Tab Fexofenadine 120mg','fexofenadine','Tab','120mg','1','tab','After Food','OD','5 days','Non-drowsy'],
  ['Tab Montelukast 10mg','montelukast','Tab','10mg','1','tab','Bedtime','OD','30 days',''],
  ['Tab Montelukast + Levocetirizine','montelukast levocetirizine','Tab','10/5mg','1','tab','Bedtime','OD','14 days',''],
  ['Tab Chlorpheniramine 4mg','chlorpheniramine','Tab','4mg','1','tab','Bedtime','TDS','3 days',''],
  ['Syp Cough Expectorant','guaifenesin','Syp','','10','ml','After Food','TDS','5 days',''],
  ['Syp Dextromethorphan','dextromethorphan','Syp','','10','ml','After Food','TDS','5 days','For dry cough'],
  ['Syp Ambroxol','ambroxol','Syp','','10','ml','After Food','TDS','5 days',''],
  ['Inh Salbutamol','salbutamol','Inh','100mcg','2','puff','Anytime','SOS','30 days','For breathlessness'],
  ['Inh Formoterol + Budesonide','formoterol budesonide','Inh','','2','puff','After Food','BD','30 days','Rinse mouth after use'],
  ['Inh Tiotropium 18mcg','tiotropium','Inh','18mcg','1','puff','Anytime','OD','30 days',''],
  ['Tab Deriphyllin Retard','etophylline theophylline','Tab','','1','tab','After Food','BD','15 days',''],
  ['Tab Prednisolone 10mg','prednisolone','Tab','10mg','1','tab','After Food','OD','5 days','Take in the morning; taper'],
  ['Tab Deflazacort 6mg','deflazacort','Tab','6mg','1','tab','After Food','OD','5 days',''],

  /* --- thyroid and hormones --- */
  ['Tab Thyronorm 25mcg','levothyroxine','Tab','25mcg','1','tab','Empty Stomach','OD','30 days','Before breakfast, plain water'],
  ['Tab Thyronorm 50mcg','levothyroxine','Tab','50mcg','1','tab','Empty Stomach','OD','30 days','Before breakfast, plain water'],
  ['Tab Thyronorm 100mcg','levothyroxine','Tab','100mcg','1','tab','Empty Stomach','OD','30 days',''],
  ['Tab Carbimazole 5mg','carbimazole','Tab','5mg','1','tab','After Food','TDS','30 days',''],

  /* --- vitamins and supplements --- */
  ['Cap Vitamin D3 60000 IU','cholecalciferol','Cap','60000IU','1','cap','After Food','Weekly','8 weeks',''],
  ['Tab Calcium + Vitamin D3','calcium carbonate','Tab','500mg','1','tab','After Food','OD','30 days',''],
  ['Tab Ferrous Ascorbate','iron','Tab','100mg','1','tab','After Food','OD','30 days','May darken stool'],
  ['Syp Iron Tonic','iron','Syp','','10','ml','After Food','OD','30 days',''],
  ['Cap Vitamin B Complex','vitamin b complex','Cap','','1','cap','After Food','OD','30 days',''],
  ['Tab Methylcobalamin 1500mcg','vitamin b12','Tab','1500mcg','1','tab','After Food','OD','30 days',''],
  ['Cap Multivitamin + Zinc','multivitamin','Cap','','1','cap','After Food','OD','30 days',''],
  ['Tab Folic Acid 5mg','folic acid','Tab','5mg','1','tab','After Food','OD','30 days',''],
  ['Tab Zinc 50mg','zinc','Tab','50mg','1','tab','After Food','OD','14 days',''],

  /* --- neuro and mental health --- */
  ['Tab Amitriptyline 10mg','amitriptyline','Tab','10mg','1','tab','Bedtime','OD','15 days',''],
  ['Tab Pregabalin 75mg','pregabalin','Tab','75mg','1','cap','Bedtime','OD','15 days','For nerve pain'],
  ['Tab Gabapentin 300mg','gabapentin','Tab','300mg','1','cap','Bedtime','OD','15 days',''],
  ['Tab Sumatriptan 50mg','sumatriptan','Tab','50mg','1','tab','SOS','SOS','1 day','For migraine attack'],
  ['Tab Flunarizine 10mg','flunarizine','Tab','10mg','1','tab','Bedtime','OD','30 days','Migraine prevention'],
  ['Tab Betahistine 16mg','betahistine','Tab','16mg','1','tab','After Food','TDS','15 days','For vertigo'],
  ['Tab Alprazolam 0.25mg','alprazolam','Tab','0.25mg','1','tab','Bedtime','OD','7 days','Short course only'],
  ['Tab Escitalopram 10mg','escitalopram','Tab','10mg','1','tab','After Food','OD','30 days',''],
  ['Tab Clonazepam 0.5mg','clonazepam','Tab','0.5mg','1','tab','Bedtime','OD','7 days',''],

  /* --- skin --- */
  ['Cream Clotrimazole','clotrimazole','Cream','','1','application','Anytime','BD','14 days','Apply thinly'],
  ['Cream Mupirocin','mupirocin','Cream','2%','1','application','Anytime','BD','7 days',''],
  ['Cream Betamethasone','betamethasone','Cream','','1','application','Anytime','BD','7 days','Short course'],
  ['Lotion Permethrin 5%','permethrin','Cream','5%','1','application','Bedtime','SOS','1 day','For scabies; whole body'],
  ['Cream Silver Sulfadiazine','silver sulfadiazine','Cream','1%','1','application','Anytime','BD','7 days','For burns'],
  ['Oint Povidone Iodine','povidone iodine','Cream','5%','1','application','Anytime','BD','7 days',''],

  /* --- eye, ear, misc --- */
  ['Drops Moxifloxacin Eye','moxifloxacin','Drops','0.5%','1','drop','Anytime','QID','5 days',''],
  ['Drops Carboxymethylcellulose','carboxymethylcellulose','Drops','','1','drop','Anytime','QID','30 days','Lubricating eye drops'],
  ['Drops Ciprofloxacin Ear','ciprofloxacin','Drops','0.3%','2','drop','Anytime','TDS','5 days',''],
  ['Drops Xylometazoline Nasal','xylometazoline','Drops','0.1%','2','drop','Anytime','BD','5 days','Not more than 5 days'],
  ['Spray Fluticasone Nasal','fluticasone','Spray','','2','puff','Anytime','OD','30 days',''],
  ['Gargle Chlorhexidine','chlorhexidine','Spray','','10','ml','After Food','BD','5 days',''],
  ['Tab Tamsulosin 0.4mg','tamsulosin','Tab','0.4mg','1','tab','Bedtime','OD','30 days','For prostate'],
  ['Tab Allopurinol 100mg','allopurinol','Tab','100mg','1','tab','After Food','OD','30 days','For gout'],
  ['Tab Febuxostat 40mg','febuxostat','Tab','40mg','1','tab','After Food','OD','30 days',''],
  ['Tab Colchicine 0.5mg','colchicine','Tab','0.5mg','1','tab','After Food','BD','5 days',''],
  ['Tab Tranexamic Acid 500mg','tranexamic acid','Tab','500mg','1','tab','After Food','TDS','5 days',''],
];

/* ---------------------------------------------------------------
   DIAGNOSES — common Indian OPD presentations
   --------------------------------------------------------------- */
$DX = [
  'Viral fever','Dengue fever','Malaria','Typhoid fever','Chikungunya','Influenza',
  'Upper respiratory tract infection','Acute pharyngitis','Tonsillitis','Acute sinusitis',
  'Acute bronchitis','Pneumonia','Bronchial asthma','COPD','Allergic rhinitis',
  'Acute gastroenteritis','Acute gastritis','Acid peptic disease','GERD','Irritable bowel syndrome',
  'Amoebic dysentery','Food poisoning','Constipation','Hepatitis A','Fatty liver disease',
  'Worm infestation','Urinary tract infection','Renal stone','Acute cystitis',
  'Type 2 diabetes mellitus','Type 1 diabetes mellitus','Prediabetes','Diabetic neuropathy',
  'Hypertension','Ischaemic heart disease','Congestive cardiac failure','Dyslipidaemia',
  'Hypothyroidism','Hyperthyroidism','Obesity','Vitamin D deficiency','Iron deficiency anaemia',
  'Vitamin B12 deficiency','Gout','Osteoarthritis','Rheumatoid arthritis','Low back pain',
  'Cervical spondylosis','Frozen shoulder','Sciatica','Muscle strain',
  'Migraine','Tension headache','Vertigo','Benign paroxysmal positional vertigo','Epilepsy',
  'Anxiety disorder','Depression','Insomnia','Stress-related disorder',
  'Fungal skin infection','Tinea corporis','Scabies','Eczema','Contact dermatitis',
  'Urticaria','Acne vulgaris','Psoriasis','Cellulitis','Boils and abscess',
  'Conjunctivitis','Stye','Dry eye','Otitis media','Otitis externa','Wax impaction',
  'Dental caries','Oral ulcer','Gingivitis',
  'Anaemia','Thrombocytopenia','Dehydration','Heat exhaustion','Electrolyte imbalance',
  'Benign prostatic hyperplasia','Menstrual irregularity','Dysmenorrhoea','Pregnancy — antenatal check',
  'Post-viral weakness','Allergic reaction','Insect bite','Minor injury','Follow-up visit',
];

/* ---------------------------------------------------------------
   TESTS
   --------------------------------------------------------------- */
$LABS = [
  ['CBC','Blood'],['ESR','Blood'],['CRP','Blood'],['Peripheral smear','Blood'],
  ['Dengue NS1','Blood'],['Dengue IgM','Blood'],['Malaria antigen','Blood'],['Widal','Blood'],
  ['Fasting blood sugar','Diabetes'],['Post prandial sugar','Diabetes'],['Random blood sugar','Diabetes'],
  ['HbA1c','Diabetes'],['Urine routine','Urine'],['Urine culture','Urine'],
  ['LFT','Biochemistry'],['KFT','Biochemistry'],['Serum electrolytes','Biochemistry'],
  ['Serum creatinine','Biochemistry'],['Uric acid','Biochemistry'],['Lipid profile','Biochemistry'],
  ['Thyroid profile','Hormone'],['TSH','Hormone'],['Vitamin D','Hormone'],['Vitamin B12','Hormone'],
  ['Serum iron studies','Blood'],['Chest X-ray','Imaging'],['USG Abdomen','Imaging'],
  ['USG KUB','Imaging'],['ECG','Cardiac'],['2D Echo','Cardiac'],['TMT','Cardiac'],
  ['X-ray knee','Imaging'],['X-ray cervical spine','Imaging'],['Stool routine','Stool'],
  ['Sputum AFB','Microbiology'],['COVID RT-PCR','Microbiology'],['Blood culture','Microbiology'],
];

/* ---------------------------------------------------------------
   ADVICE
   --------------------------------------------------------------- */
$ADVICE = [
  ['Plenty of fluids','English'],['Take rest','English'],
  ['Avoid oily and spicy food','English'],['Light home food only','English'],
  ['Come back immediately if it worsens','English'],
  ['Check sugar daily and keep a record','English'],['Check BP weekly','English'],
  ['Walk 30 minutes daily','English'],['Steam inhalation twice a day','English'],
  ['Warm salt water gargle','English'],['Avoid cold drinks and ice','English'],
  ['Do not skip meals','English'],['Stop smoking','English'],['Avoid alcohol','English'],
  ['Stay out of the sun','English'],['Follow the diet advised','English'],
  ['Reduce salt in food','English'],['Reduce sugar and sweets','English'],
  ['Keep the wound clean and dry','English'],['Complete the full antibiotic course','English'],
  ['Use mosquito net or repellent','English'],['Drink only boiled or filtered water','English'],
  ['ज्यादा पानी पिएं','Hindi'],['आराम करें','Hindi'],
  ['तेल मसाला मत खाइए','Hindi'],['हालत बिगड़े तो तुरंत आएं','Hindi'],
  ['ठंडा पानी मत पिएं','Hindi'],['रोज 30 मिनट टहलें','Hindi'],
  ['नमक कम करें','Hindi'],['दवा पूरी लें','Hindi'],
];

/* ---------------------------------------------------------------
   Load. INSERT IGNORE everywhere so existing rows keep their
   edits and their usage counts.
   --------------------------------------------------------------- */
$before = [
  'drugs'        => (int)$pdo->query('SELECT COUNT(*) FROM drugs')->fetchColumn(),
  'diagnoses'    => (int)$pdo->query('SELECT COUNT(*) FROM diagnoses')->fetchColumn(),
  'labs'         => (int)$pdo->query('SELECT COUNT(*) FROM labs')->fetchColumn(),
  'advice_lines' => (int)$pdo->query('SELECT COUNT(*) FROM advice_lines')->fetchColumn(),
];

$pdo->beginTransaction();

/* drugs has no unique key on name, so check before inserting */
$exists = $pdo->prepare('SELECT 1 FROM drugs WHERE name=? LIMIT 1');
$insD   = $pdo->prepare('INSERT INTO drugs
    (name,generic,form,strength,def_dose,def_unit,def_when,def_freq,def_duration,notes,active)
    VALUES (?,?,?,?,?,?,?,?,?,?,1)');
foreach ($DRUGS as $d) {
    $exists->execute([$d[0]]);
    if ($exists->fetchColumn()) continue;
    $insD->execute([$d[0],$d[1],$d[2],$d[3],$d[4],$d[5],$d[6],$d[7],$d[8],$d[9]]);
}

$insX = $pdo->prepare('INSERT IGNORE INTO diagnoses(name) VALUES(?)');
foreach ($DX as $x) $insX->execute([$x]);

$insL = $pdo->prepare('INSERT IGNORE INTO labs(name,grp) VALUES(?,?)');
foreach ($LABS as $l) $insL->execute([$l[0],$l[1]]);

$hasA = $pdo->prepare('SELECT 1 FROM advice_lines WHERE text=? LIMIT 1');
$insA = $pdo->prepare('INSERT INTO advice_lines(text,lang) VALUES(?,?)');
foreach ($ADVICE as $a) {
    $hasA->execute([$a[0]]);
    if (!$hasA->fetchColumn()) $insA->execute([$a[0],$a[1]]);
}

$pdo->commit();

printf("%-14s %5s %5s %s\n", 'table', 'was', 'now', 'added');
foreach (array_keys($before) as $t) {
    $now = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    printf("%-14s %5d %5d %+d\n", $t, $before[$t], $now, $now - $before[$t]);
}
echo "\nReview the list in Settings before real use — the doses shown are\n";
echo "common adult starting points, not a recommendation.\n";
