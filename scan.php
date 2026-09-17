<?php
/* Paper prescription -> photo -> digital.
   Doctor writes on paper as usual, takes a photo with the phone camera,
   the app cleans it up and sends it on WhatsApp. Zero extra hardware. */
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
    $data = (string)($_POST['shot'] ?? '');
    if (!preg_match('~^data:image/(png|jpeg);base64,~', $data, $m)) {
        $_SESSION['err'] = 'No photo was captured.';
        redirect('scan.php?patient='.$pid);
    }
    $bin = base64_decode(substr($data, strpos($data, ',') + 1), true);
    if ($bin === false || strlen($bin) < 500) {
        $_SESSION['err'] = 'Photo could not be read. Try again.';
        redirect('scan.php?patient='.$pid);
    }
    $dir = __DIR__.'/data/rx';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ext = ($m[1] === 'jpeg') ? 'jpg' : 'png';
    $fn = 'scan_'.$pid.'_'.date('Ymd_His').'.'.$ext;
    file_put_contents($dir.'/'.$fn, $bin);

    $st = $pdo->prepare('INSERT INTO prescriptions(patient_id,rx_date,diagnosis,vitals,meds,labs,advice,follow_up,ink_file,ink_mode)
                         VALUES(?,?,?,?,?,?,?,?,?,1)');
    $st->execute([$pid, date('Y-m-d'),
        pf('diagnosis'), json_encode([]), json_encode([]), json_encode([]),
        '', pf('follow_up'), $fn]);
    $rxId = (int)$pdo->lastInsertId();
    if ($apptId) $pdo->prepare("UPDATE appointments SET status='Completed' WHERE id=?")->execute([$apptId]);

    $_SESSION['ok'] = 'Paper prescription captured.';
    redirect("send.php?rx=$rxId");
}

head('Scan Paper Prescription');
?>
<div class="page-h">
  <div><h1>Scan paper prescription</h1>
    <p><?= e($pt['name']) ?> · <?= (int)$pt['age'] ?><?= e($pt['sex']) ?> · <?= e($pt['phone']) ?></p></div>
  <div class="spacer"></div>
  <a class="btn ghost sm" href="consult.php?<?= $apptId?'appt='.$apptId:'patient='.$pid ?>">⌨ Type instead</a>
</div>

<div class="card" style="background:var(--v-ll);border:1px solid var(--v)">
  <b style="font-size:13.5px">Write the prescription on paper as you always do — then photograph it here.</b>
  <div class="ph" style="margin-top:5px">Hold the phone straight above the sheet in good light.
    The app sharpens it, removes the yellow tint and makes it small enough for WhatsApp.</div>
</div>

<form method="post" id="scanForm">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="appt_id" value="<?= $apptId ?>">
<input type="hidden" name="patient_id" value="<?= $pid ?>">
<input type="hidden" name="shot" id="shotData">

<div class="grid g2" style="align-items:start">
  <div class="card">
    <h3>1 · Take the photo</h3>
    <div class="sub">Camera on a phone, or choose a file on a desktop</div>

    <label for="pick" class="pickbox" id="pickbox">
      <div style="font-size:44px;line-height:1">📄</div>
      <b style="font-size:15px">Tap to photograph the prescription</b>
      <span style="font-size:12.5px;color:var(--muted)">Opens the camera on a phone</span>
    </label>
    <input id="pick" type="file" accept="image/*" capture="environment" hidden>

    <div id="tools" style="display:none;margin-top:14px">
      <label>Clean-up</label>
      <div class="segs">
        <button type="button" class="seg on" data-mode="scan">📃 Paper scan</button>
        <button type="button" class="seg" data-mode="grey">◐ Greyscale</button>
        <button type="button" class="seg" data-mode="raw">🖼 Original</button>
      </div>
      <div class="fld"><label>Brightness <span id="brv">0</span></label>
        <input type="range" id="bright" min="-60" max="60" value="0"></div>
      <div class="fld"><label>Contrast <span id="ctv">35</span></label>
        <input type="range" id="contrast" min="0" max="90" value="35"></div>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <button type="button" class="btn ghost sm" id="rot">⟳ Rotate</button>
        <button type="button" class="btn ghost sm" id="again">↺ Retake</button>
      </div>
    </div>
  </div>

  <div class="card">
    <h3>2 · Check &amp; send</h3>
    <div class="sub">This is exactly what the patient will receive</div>
    <div id="prevWrap" class="prevwrap"><span class="ph2">No photo yet</span></div>
    <canvas id="cv" style="display:none"></canvas>
    <div id="sizeNote" class="ph" style="display:none"></div>

    <div class="row2" style="margin-top:12px">
      <div class="field"><label>Diagnosis (optional — keeps the chart searchable)</label>
        <input name="diagnosis" placeholder="e.g. Acute viral fever"></div>
      <div class="field"><label>Next check-up</label><input type="date" name="follow_up"></div>
    </div>
    <button class="btn wa" id="go" disabled style="padding:11px 17px;width:100%">Save &amp; send on WhatsApp →</button>
  </div>
</div>
</form>

<style>
.pickbox{display:flex;flex-direction:column;align-items:center;gap:7px;padding:34px 18px;cursor:pointer;
 border:2px dashed var(--v);border-radius:13px;background:var(--v-ll);text-align:center}
.pickbox:active{background:#d9eef0}
.segs{display:flex;gap:7px;margin-bottom:12px;flex-wrap:wrap}
.seg{border:1px solid var(--line);background:#fff;border-radius:9px;padding:9px 13px;font-size:13px;
 font-weight:700;cursor:pointer;font-family:inherit;color:var(--navy)}
.seg.on{background:var(--v);border-color:var(--v);color:#fff}
.fld{margin-bottom:9px}
.fld label{display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;font-weight:700}
.fld input[type=range]{width:100%}
.prevwrap{background:#eef2f4;border-radius:11px;min-height:280px;display:flex;align-items:center;
 justify-content:center;overflow:hidden;padding:9px}
.prevwrap img{max-width:100%;max-height:520px;border-radius:6px;box-shadow:0 3px 14px rgba(0,0,0,.18)}
.ph2{color:var(--muted);font-size:13px}
</style>

<script>
(function(){
 var pick=document.getElementById('pick'), cv=document.getElementById('cv'), cx=cv.getContext('2d');
 var prev=document.getElementById('prevWrap'), go=document.getElementById('go');
 var tools=document.getElementById('tools'), pickbox=document.getElementById('pickbox');
 var bright=document.getElementById('bright'), contrast=document.getElementById('contrast');
 var sizeNote=document.getElementById('sizeNote');
 var img=null, mode='scan', rot=0;

 document.querySelectorAll('.seg').forEach(function(b){
   b.onclick=function(){ document.querySelectorAll('.seg').forEach(function(x){x.classList.remove('on');});
     b.classList.add('on'); mode=b.dataset.mode; render(); };
 });
 bright.oninput=function(){ document.getElementById('brv').textContent=bright.value; render(); };
 contrast.oninput=function(){ document.getElementById('ctv').textContent=contrast.value; render(); };
 document.getElementById('rot').onclick=function(){ rot=(rot+90)%360; render(); };
 document.getElementById('again').onclick=function(){ pick.value=''; pick.click(); };

 pick.onchange=function(e){
   var f=e.target.files[0]; if(!f) return;
   var r=new FileReader();
   r.onload=function(ev){ var i=new Image();
     i.onload=function(){ img=i; rot=0; tools.style.display='block';
       pickbox.style.display='none'; render(); };
     i.src=ev.target.result; };
   r.readAsDataURL(f);
 };

 function render(){
   if(!img) return;
   /* cap the long edge at 1600px - keeps it readable but small enough for WhatsApp */
   var MAX=1600, w=img.width, h=img.height;
   var sc=Math.min(1, MAX/Math.max(w,h)); w=Math.round(w*sc); h=Math.round(h*sc);
   var swap=(rot===90||rot===270);
   cv.width=swap?h:w; cv.height=swap?w:h;
   cx.save();
   cx.translate(cv.width/2, cv.height/2); cx.rotate(rot*Math.PI/180);
   cx.drawImage(img, -w/2, -h/2, w, h);
   cx.restore();

   if(mode!=='raw'){
     var d=cx.getImageData(0,0,cv.width,cv.height), a=d.data;
     var br=parseInt(bright.value,10), ct=parseInt(contrast.value,10);
     var f=(259*(ct+255))/(255*(259-ct));
     for(var i=0;i<a.length;i+=4){
       var g=0.299*a[i]+0.587*a[i+1]+0.114*a[i+2];
       g=f*(g-128)+128+br;
       if(mode==='scan'){
         /* push paper to white and ink to dark - the "scanned" look */
         if(g>186) g=255; else if(g<92) g=(g*0.45)|0;
       }
       g=g<0?0:(g>255?255:g);
       a[i]=a[i+1]=a[i+2]=g;
     }
     cx.putImageData(d,0,0);
   }

   var url=cv.toDataURL('image/jpeg',0.82);
   prev.innerHTML='<img src="'+url+'" alt="Prescription preview">';
   go.disabled=false;
   var kb=Math.round((url.length*0.75)/1024);
   sizeNote.style.display='block';
   sizeNote.textContent='Image '+cv.width+'×'+cv.height+' · about '+kb+' KB — fine for WhatsApp.';
 }

 document.getElementById('scanForm').addEventListener('submit',function(ev){
   if(!img){ ev.preventDefault(); return; }
   document.getElementById('shotData').value=cv.toDataURL('image/jpeg',0.82)
     .replace('data:image/jpeg','data:image/jpeg');
 });
})();
</script>
<?php foot(); ?>
