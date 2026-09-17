<?php
/* Handwritten prescription pad for a touch panel / stylus.
   Doctor writes on a real letterhead; the ink is saved as a PNG and
   sent to the patient as a WhatsApp image. */
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

require_login();

$pdo = db();
$apptId = (int)($_GET['appt'] ?? $_POST['appt_id'] ?? 0);
$pid    = (int)($_GET['patient'] ?? $_POST['patient_id'] ?? 0);
if ($apptId) {
    $a = $pdo->prepare('SELECT * FROM appointments WHERE id=?'); $a->execute([$apptId]);
    $appt = $a->fetch();
    if (!$appt) { http_response_code(404); exit('Appointment not found'); }
    $pid = (int)$appt['patient_id'];
}
$p = $pdo->prepare('SELECT * FROM patients WHERE id=?'); $p->execute([$pid]); $pt = $p->fetch();
if (!$pt) { http_response_code(404); exit('Patient not found'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = (string)($_POST['ink'] ?? '');
    if (!preg_match('~^data:image/png;base64,~', $data)) {
        $_SESSION['err'] = 'Nothing was written on the pad.';
        redirect('write.php?patient='.$pid);
    }
    $png = base64_decode(substr($data, strlen('data:image/png;base64,')), true);
    if ($png === false || strlen($png) < 100) {
        $_SESSION['err'] = 'Could not read the handwriting image.';
        redirect('write.php?patient='.$pid);
    }
    $dir = __DIR__.'/data/rx';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $fn = 'rx_'.$pid.'_'.date('Ymd_His').'.png';
    file_put_contents($dir.'/'.$fn, $png);

    $st = $pdo->prepare('INSERT INTO prescriptions(patient_id,rx_date,diagnosis,vitals,meds,labs,advice,follow_up,ink_file,ink_mode)
                         VALUES(?,?,?,?,?,?,?,?,?,1)');
    $st->execute([$pid, date('Y-m-d'),
        pf('diagnosis'), json_encode([]), json_encode([]), json_encode([]),
        '', pf('follow_up'), $fn]);
    $rxId = (int)$pdo->lastInsertId();
    if ($apptId) $pdo->prepare("UPDATE appointments SET status='Completed' WHERE id=?")->execute([$apptId]);

    $_SESSION['ok'] = 'Handwritten prescription saved.';
    redirect("send.php?rx=$rxId");
}

head('Write Prescription');
?>
<div class="page-h">
  <div><h1>Handwritten prescription</h1>
    <p><?= e($pt['name']) ?> · <?= (int)$pt['age'] ?><?= e($pt['sex']) ?> · <?= e($pt['phone']) ?></p></div>
  <div class="spacer"></div>
  <a class="btn ghost" href="consult.php?<?= $apptId?'appt='.$apptId:'patient='.$pid ?>">⌨ Switch to typed form</a>
</div>

<?php if (trim((string)$pt['allergies']) !== ''): ?>
  <div class="flash-err">⚠ Allergies on record: <?= e($pt['allergies']) ?></div>
<?php endif; ?>

<form method="post" id="inkForm">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="appt_id" value="<?= $apptId ?>">
<input type="hidden" name="patient_id" value="<?= $pid ?>">
<input type="hidden" name="ink" id="inkData">

<div class="card" style="padding:0;overflow:hidden">
  <div class="pad-tools">
    <button type="button" class="tool on" data-tool="pen">✒ Pen</button>
    <button type="button" class="tool" data-tool="eraser">⌫ Eraser</button>
    <span class="tsep"></span>
    <button type="button" class="sw on" data-w="2.4" title="Thin"><i style="height:2px"></i></button>
    <button type="button" class="sw" data-w="4" title="Medium"><i style="height:4px"></i></button>
    <button type="button" class="sw" data-w="7" title="Thick"><i style="height:7px"></i></button>
    <span class="tsep"></span>
    <button type="button" class="sc on" data-c="#12305c" style="background:#12305c"></button>
    <button type="button" class="sc" data-c="#111111" style="background:#111"></button>
    <button type="button" class="sc" data-c="#b02020" style="background:#b02020"></button>
    <span class="tsep"></span>
    <button type="button" class="tool" id="undo">↶ Undo</button>
    <button type="button" class="tool" id="clear">✕ Clear page</button>
    <span class="spacer"></span>
    <label class="palm"><input type="checkbox" id="stylusOnly"> Stylus only (palm rejection)</label>
  </div>

  <div id="padWrap" class="pad-wrap">
    <canvas id="pad" width="1240" height="1754"></canvas>
  </div>
</div>

<div class="card" style="margin-top:14px">
  <div class="row3">
    <div class="field"><label>Diagnosis (typed — used for records &amp; search)</label>
      <input name="diagnosis" placeholder="e.g. Acute viral fever"></div>
    <div class="field"><label>Next check-up</label><input type="date" name="follow_up"></div>
    <div class="field" style="display:flex;align-items:flex-end;gap:9px">
      <button class="btn wa" style="padding:11px 16px">Save &amp; send on WhatsApp →</button>
    </div>
  </div>
  <div class="ph">The handwritten sheet is sent to the patient as an image. Typing the diagnosis is
    optional but keeps the chart searchable — handwriting cannot be searched.</div>
</div>
</form>

<style>
.pad-tools{display:flex;align-items:center;gap:7px;padding:10px 13px;background:var(--navy);flex-wrap:wrap}
.pad-tools .spacer{flex:1}
.tool,.sw,.sc{border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.07);color:#e8f1f3;
  border-radius:8px;padding:8px 13px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.tool.on{background:var(--v);border-color:var(--v);color:#fff}
.sw{width:44px;display:grid;place-items:center;padding:8px 0}
.sw i{display:block;width:20px;background:#e8f1f3;border-radius:3px}
.sw.on{background:rgba(255,255,255,.24)}
.sc{width:30px;height:30px;padding:0;border-radius:50%}
.sc.on{box-shadow:0 0 0 3px #fff}
.tsep{width:1px;height:24px;background:rgba(255,255,255,.2);margin:0 4px}
.palm{color:#cfe3e6;font-size:12.5px;display:flex;align-items:center;gap:6px;cursor:pointer}
.pad-wrap{background:#5b6b73;padding:18px;max-height:70vh;overflow:auto;display:flex;justify-content:center}
#pad{background:#fff;box-shadow:0 4px 22px rgba(0,0,0,.3);border-radius:3px;
  width:620px;height:877px;touch-action:none;cursor:crosshair}
@media(min-width:1500px){#pad{width:760px;height:1075px}}
</style>

<script>
(function(){
 var cv=document.getElementById('pad'), cx=cv.getContext('2d');

 /* The letterhead and the handwriting used to share one canvas, and the
    eraser used destination-out — so rubbing out a mistake punched a hole
    through the clinic header and the ruled lines underneath it.

    The form is now painted on a separate layer behind the visible canvas.
    The eraser only ever touches the ink layer; the printed form cannot be
    damaged. The two are merged when the page is saved. */
 var form = document.createElement('canvas');
 form.width = cv.width; form.height = cv.height;
 var fx = form.getContext('2d');
 var CL={name:<?= json_encode(clinic('name')) ?>,doc:<?= json_encode(clinic('doctor')) ?>,
   qual:<?= json_encode(clinic('qual')) ?>,reg:<?= json_encode(clinic('reg')) ?>,
   addr:<?= json_encode(clinic('addr')) ?>,ph:<?= json_encode(clinic('phone')) ?>,
   hrs:<?= json_encode(clinic('hours')) ?>};
 var PT={n:<?= json_encode($pt['name']) ?>,a:<?= json_encode($pt['age'].$pt['sex']) ?>,
   ab:<?= json_encode($pt['abha']) ?>,al:<?= json_encode($pt['allergies']) ?>,
   d:<?= json_encode(date('j M Y')) ?>};

 /* ---- letterhead is painted onto the canvas, so the saved PNG is a complete document ---- */
 function letterhead(){
   fx.fillStyle='#fff'; fx.fillRect(0,0,cv.width,cv.height);
   fx.fillStyle='#0e7c86'; fx.fillRect(0,0,cv.width,14);
   fx.fillStyle='#0e7c86'; fx.beginPath(); fx.roundRect(62,52,74,74,16); fx.fill();
   fx.fillStyle='#fff'; fx.font='800 42px system-ui,sans-serif';
   fx.textAlign='center'; fx.fillText('B',99,101); fx.textAlign='left';
   fx.fillStyle='#0f2a3d'; fx.font='800 34px Georgia,serif'; fx.fillText(CL.name,156,84);
   fx.fillStyle='#436'; fx.font='17px Georgia,serif'; fx.fillText(CL.doc+' · '+CL.qual,156,112);
   fx.fillStyle='#7a8b95'; fx.font='13px system-ui,sans-serif'; fx.fillText('Reg. '+CL.reg,156,134);
   fx.textAlign='right'; fx.fillStyle='#5a6b75'; fx.font='13px system-ui,sans-serif';
   fx.fillText(CL.addr,cv.width-62,74); fx.fillText(CL.ph,cv.width-62,96);
   fx.fillText('OPD: '+CL.hrs,cv.width-62,118); fx.textAlign='left';
   fx.strokeStyle='#0e7c86'; fx.lineWidth=2.5;
   fx.beginPath(); fx.moveTo(62,152); fx.lineTo(cv.width-62,152); fx.stroke();
   fx.fillStyle='#0f2a3d'; fx.font='600 19px system-ui,sans-serif';
   fx.fillText(PT.n+'   ('+PT.a+')',62,190);
   fx.fillStyle='#7a8b95'; fx.font='14px system-ui,sans-serif';
   fx.fillText('ABHA '+PT.ab,62,214);
   fx.textAlign='right'; fx.fillStyle='#0f2a3d'; fx.font='600 16px system-ui,sans-serif';
   fx.fillText('Date: '+PT.d,cv.width-62,190); fx.textAlign='left';
   if(PT.al && PT.al.trim()!==''){
     fx.fillStyle='#fdeaea'; fx.beginPath(); fx.roundRect(62,228,cv.width-124,36,7); fx.fill();
     fx.fillStyle='#9b2c2c'; fx.font='600 15px system-ui,sans-serif';
     fx.fillText('\u26A0  Allergies: '+PT.al,78,252);
   }
   var top = (PT.al && PT.al.trim()!=='') ? 288 : 248;
   fx.strokeStyle='#dfe7ea'; fx.lineWidth=1;
   fx.beginPath(); fx.moveTo(62,top); fx.lineTo(cv.width-62,top); fx.stroke();
   fx.fillStyle='#0e7c86'; fx.font='italic 700 46px Georgia,serif'; fx.fillText('\u211E',66,top+58);
   /* faint ruled lines to write on */
   fx.strokeStyle='#eef3f5';
   for(var y=top+96;y<cv.height-250;y+=54){
     fx.beginPath(); fx.moveTo(62,y); fx.lineTo(cv.width-62,y); fx.stroke();
   }
   fx.strokeStyle='#c9d6db'; fx.lineWidth=1.4;
   fx.beginPath(); fx.moveTo(cv.width-420,cv.height-150); fx.lineTo(cv.width-62,cv.height-150); fx.stroke();
   fx.fillStyle='#5a6b75'; fx.font='15px system-ui,sans-serif'; fx.textAlign='right';
   fx.fillText(CL.doc,cv.width-62,cv.height-122);
   fx.font='12px system-ui,sans-serif'; fx.fillText(CL.qual,cv.width-62,cv.height-102);
   fx.textAlign='center'; fx.fillStyle='#9aa9b1'; fx.font='12px system-ui,sans-serif';
   fx.fillText('This prescription is valid only for the named patient. Not for resale.',cv.width/2,cv.height-46);
   fx.textAlign='left';
 }

 var undo=[], MAX=24;
 function snap(){ undo.push(cx.getImageData(0,0,cv.width,cv.height)); if(undo.length>MAX) undo.shift(); }
 /* Clear only the ink. The printed form is redrawn underneath. */
 function reset(){
   letterhead();
   cx.clearRect(0, 0, cv.width, cv.height);
   paint();
   undo = []; snap();
 }

 /* Show the form behind whatever ink exists. Called after every stroke. */
 function paint(){
   cv.style.backgroundImage = 'url(' + form.toDataURL('image/png') + ')';
   cv.style.backgroundSize = '100% 100%';
 }
 reset();

 var tool='pen', width=2.4, colour='#12305c', drawing=false, last=null;
 function sel(list,el){ list.forEach(function(b){b.classList.remove('on');}); el.classList.add('on'); }
 var tools=[].slice.call(document.querySelectorAll('.tool[data-tool]'));
 tools.forEach(function(b){ b.onclick=function(){ tool=b.dataset.tool; sel(tools,b); }; });
 var sws=[].slice.call(document.querySelectorAll('.sw'));
 sws.forEach(function(b){ b.onclick=function(){ width=parseFloat(b.dataset.w); sel(sws,b); }; });
 var scs=[].slice.call(document.querySelectorAll('.sc'));
 scs.forEach(function(b){ b.onclick=function(){ colour=b.dataset.c; sel(scs,b);
   tool='pen'; sel(tools,tools[0]); }; });

 document.getElementById('clear').onclick=function(){
   if(confirm('Clear the whole page? Handwriting will be lost.')) reset(); };
 document.getElementById('undo').onclick=function(){
   if(undo.length>1){ undo.pop(); cx.putImageData(undo[undo.length-1],0,0); } };

 function pos(e){ var r=cv.getBoundingClientRect();
   return {x:(e.clientX-r.left)*(cv.width/r.width), y:(e.clientY-r.top)*(cv.height/r.height)}; }

 var stylusOnly=document.getElementById('stylusOnly');
 function allowed(e){ return !(stylusOnly.checked && e.pointerType!=='pen'); }

 cv.addEventListener('pointerdown',function(e){
   if(!allowed(e)) return;
   e.preventDefault(); cv.setPointerCapture(e.pointerId);
   snap(); drawing=true; last=pos(e);
 });
 cv.addEventListener('pointermove',function(e){
   if(!drawing||!allowed(e)) return;
   e.preventDefault();
   var p=pos(e);
   /* pressure varies the stroke on a real stylus; mouse reports 0 or .5 */
   var pr=(e.pointerType==='pen'&&e.pressure>0)?(0.45+e.pressure*1.1):1;
   cx.globalCompositeOperation = (tool==='eraser')?'destination-out':'source-over';
   cx.strokeStyle=colour; cx.lineWidth=(tool==='eraser'?width*7:width*pr);
   cx.lineCap='round'; cx.lineJoin='round';
   cx.beginPath(); cx.moveTo(last.x,last.y); cx.lineTo(p.x,p.y); cx.stroke();
   last=p;
 });
 ['pointerup','pointercancel','pointerleave'].forEach(function(ev){
   cv.addEventListener(ev,function(){ drawing=false; cx.globalCompositeOperation='source-over'; });
 });

 document.getElementById('inkForm').addEventListener('submit',function(ev){
   /* Merge the two layers for saving: white paper, then the printed form,
      then the doctor's ink on top. The form layer is never erased, so the
      saved sheet always carries a complete letterhead however much was
      rubbed out. */
   var out=document.createElement('canvas'); out.width=cv.width; out.height=cv.height;
   var ox=out.getContext('2d');
   ox.fillStyle='#fff'; ox.fillRect(0,0,out.width,out.height);
   ox.drawImage(form,0,0);
   ox.drawImage(cv,0,0);
   document.getElementById('inkData').value=out.toDataURL('image/png');
 });
})();
</script>
<?php foot(); ?>
