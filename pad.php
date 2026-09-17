<?php
/* The phone/tablet side of the Smart Pad Link.
   Opened by scanning the QR on the desk. No login: the token is the credential. */
declare(strict_types=1);
require_once __DIR__.'/inc/db.php'; require_once __DIR__.'/inc/refdata.php';
require_once __DIR__.'/inc/pad.php';
require_once __DIR__.'/inc/config.php';

$tok = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$s   = $tok !== '' ? pad_get($tok) : null;

function pad_fail(string $msg): void {
    ?><!DOCTYPE html><html><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pad link</title><style>
    body{font-family:system-ui,sans-serif;background:#0f2a3d;color:#fff;display:grid;place-items:center;
    height:100vh;margin:0;text-align:center;padding:22px}
    .b{background:#16394f;padding:30px 24px;border-radius:16px;max-width:340px}
    h1{font-size:19px;margin:0 0 9px}p{opacity:.85;font-size:14px;line-height:1.5;margin:0}
    </style></head><body><div class="b"><h1><?= e($msg) ?></h1>
    <p>Ask the doctor to show a fresh QR code on the desk screen, then scan it again.</p></div></body></html><?php
    exit;
}
if (!$s)                       pad_fail('This pad link is not valid.');
if (!empty($s['expired']))     pad_fail('This pad link has expired.');
if ($s['status'] === 'done')   pad_fail('This prescription has already been sent to the desk.');

$pdo = db();
$q = $pdo->prepare('SELECT * FROM patients WHERE id=?'); $q->execute([(int)$s['patient_id']]);
$pt = $q->fetch();
if (!$pt) pad_fail('Patient not found.');

/* ---- receive the finished image ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = (string)($_POST['img'] ?? '');
    if (!preg_match('~^data:image/(png|jpeg);base64,~', $data, $m)) pad_fail('Nothing was captured.');
    $bin = base64_decode(substr($data, strpos($data, ',') + 1), true);
    if ($bin === false || strlen($bin) < 400) pad_fail('Image could not be read.');
    $dir = __DIR__.'/data/rx';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ext = ($m[1] === 'jpeg') ? 'jpg' : 'png';
    $fn  = 'pad_'.(int)$s['patient_id'].'_'.date('Ymd_His').'.'.$ext;
    file_put_contents($dir.'/'.$fn, $bin);
    pad_complete($tok, $fn, (string)($_POST['kind'] ?? 'write'));
    ?><!DOCTYPE html><html><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"><title>Sent</title><style>
    body{font-family:system-ui,sans-serif;background:#0b6b3a;color:#fff;display:grid;place-items:center;
    height:100vh;margin:0;text-align:center;padding:22px}
    .b{max-width:330px}.t{font-size:60px}h1{font-size:22px;margin:12px 0 8px}
    p{opacity:.9;font-size:14.5px;line-height:1.5}</style></head><body><div class="b">
    <div class="t">✓</div><h1>Sent to the desk</h1>
    <p>It is now on the doctor's screen. You can put the phone down.</p></div></body></html><?php
    exit;
}

$mode = ($s['mode'] === 'scan') ? 'scan' : 'write';
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Pad · <?= e($pt['name']) ?></title>
<style>
 *{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
 body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#0f2a3d;color:#fff;
      height:100dvh;display:flex;flex-direction:column;overflow:hidden}
 header{padding:10px 14px;background:#0e7c86;display:flex;align-items:center;gap:10px;flex:none}
 header b{font-size:14.5px}header .s{font-size:11.5px;opacity:.9}
 .warn{background:#8f2222;font-size:12px;padding:6px 14px;flex:none}
 .tools{display:flex;gap:6px;padding:8px 10px;background:#16394f;flex:none;overflow-x:auto}
 .tl{border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.08);color:#eaf3f5;
     border-radius:8px;padding:9px 12px;font-size:13px;font-weight:700;white-space:nowrap;font-family:inherit}
 .tl.on{background:#0e7c86;border-color:#0e7c86}
 .sc{width:28px;height:28px;border-radius:50%;padding:0;border:1px solid rgba(255,255,255,.3)}
 .sc.on{box-shadow:0 0 0 3px #fff}
 .stage{flex:1;min-height:0;background:#5b6b73;display:flex;align-items:center;justify-content:center;
        overflow:auto;padding:8px}
 canvas{background:#fff;touch-action:none;max-width:100%;max-height:100%;border-radius:3px;
        box-shadow:0 3px 16px rgba(0,0,0,.35)}
 footer{padding:10px;background:#0b2334;display:flex;gap:9px;flex:none}
 .go{flex:1;background:#25d366;color:#062b16;border:0;border-radius:11px;padding:15px;
     font-size:15.5px;font-weight:800;font-family:inherit}
 .go:disabled{opacity:.45}
 .gh{background:rgba(255,255,255,.12);color:#fff;border:0;border-radius:11px;padding:15px 16px;
     font-size:14px;font-weight:700;font-family:inherit}
 .pickb{display:flex;flex-direction:column;align-items:center;gap:8px;padding:40px 20px;color:#eaf3f5;
        border:2px dashed rgba(255,255,255,.5);border-radius:14px;margin:16px;text-align:center}
</style></head><body>
<header>
  <div style="width:32px;height:32px;border-radius:8px;background:#fff;color:#0e7c86;display:grid;
       place-items:center;font-weight:900">B</div>
  <div><b><?= e($pt['name']) ?></b>
    <div class="s"><?= (int)$pt['age'] ?><?= e($pt['sex']) ?> · <?= e(date('j M Y')) ?></div></div>
  <div style="margin-left:auto;font-size:10.5px;text-align:right;opacity:.9">
    <?= $mode === 'scan' ? 'PHOTO MODE' : 'WRITING PAD' ?><br>linked to desk</div>
</header>
<?php if (trim((string)$pt['allergies']) !== ''): ?>
  <div class="warn">⚠ Allergies: <?= e($pt['allergies']) ?></div>
<?php endif; ?>

<form method="post" id="f" style="display:contents">
<input type="hidden" name="t" value="<?= e($tok) ?>">
<input type="hidden" name="kind" value="<?= e($mode) ?>">
<input type="hidden" name="img" id="img">

<?php if ($mode === 'write'): ?>
  <div class="tools">
    <button type="button" class="tl on" id="tpen">✒ Pen</button>
    <button type="button" class="tl" id="ter">⌫ Erase</button>
    <button type="button" class="tl sc on" data-c="#12305c" style="background:#12305c"></button>
    <button type="button" class="tl sc" data-c="#111" style="background:#111"></button>
    <button type="button" class="tl sc" data-c="#b02020" style="background:#b02020"></button>
    <button type="button" class="tl" id="undo">↶ Undo</button>
    <button type="button" class="tl" id="clr">✕ Clear</button>
  </div>
  <div class="stage"><canvas id="cv" width="1000" height="1414"></canvas></div>
<?php else: ?>
  <div class="stage" id="stage">
    <label class="pickb" id="pb" for="pick">
      <div style="font-size:46px">📄</div>
      <b style="font-size:16px">Tap to photograph the prescription</b>
      <span style="font-size:12.5px;opacity:.85">Hold the phone flat above the paper</span>
    </label>
    <canvas id="cv" style="display:none"></canvas>
  </div>
  <input id="pick" type="file" accept="image/*" capture="environment" hidden>
<?php endif; ?>

<footer>
  <button type="button" class="gh" id="retry"><?= $mode==='scan' ? '↺ Retake' : '↺ Reset' ?></button>
  <button class="go" id="send" <?= $mode==='scan' ? 'disabled' : '' ?>>Send to desk →</button>
</footer>
</form>

<script>
var MODE=<?= json_encode($mode) ?>;
var CL=<?= json_encode(['n'=>clinic('name'),'d'=>clinic('doctor'),'q'=>clinic('qual'),
                        'p'=>clinic('phone'),'a'=>clinic('addr')]) ?>;
var PT=<?= json_encode(['n'=>$pt['name'],'a'=>$pt['age'].$pt['sex'],'ab'=>$pt['abha'],
                        'al'=>$pt['allergies'],'d'=>date('j M Y')]) ?>;
var cv=document.getElementById('cv'), cx=cv.getContext('2d');

if(MODE==='write'){
  var undo=[], colour='#12305c', tool='pen', drawing=false, last=null;
  function head(){
    cx.fillStyle='#fff'; cx.fillRect(0,0,cv.width,cv.height);
    cx.fillStyle='#0e7c86'; cx.fillRect(0,0,cv.width,10);
    cx.fillStyle='#0f2a3d'; cx.font='800 30px Georgia,serif'; cx.fillText(CL.n,44,64);
    cx.fillStyle='#4a5a66'; cx.font='15px Georgia,serif'; cx.fillText(CL.d+' · '+CL.q,44,90);
    cx.textAlign='right'; cx.fillStyle='#5a6b75'; cx.font='12px system-ui';
    cx.fillText(CL.p,cv.width-44,64); cx.fillText(CL.a,cv.width-44,84); cx.textAlign='left';
    cx.strokeStyle='#0e7c86'; cx.lineWidth=2.2;
    cx.beginPath(); cx.moveTo(44,112); cx.lineTo(cv.width-44,112); cx.stroke();
    cx.fillStyle='#0f2a3d'; cx.font='600 18px system-ui';
    cx.fillText(PT.n+'  ('+PT.a+')',44,146);
    cx.fillStyle='#7a8b95'; cx.font='13px system-ui'; cx.fillText('ABHA '+PT.ab,44,168);
    cx.textAlign='right'; cx.fillStyle='#0f2a3d'; cx.font='600 14px system-ui';
    cx.fillText(PT.d,cv.width-44,146); cx.textAlign='left';
    var top=190;
    if(PT.al && PT.al.trim()!==''){
      cx.fillStyle='#fdeaea'; cx.fillRect(44,182,cv.width-88,32);
      cx.fillStyle='#9b2c2c'; cx.font='600 14px system-ui';
      cx.fillText('\u26A0  Allergies: '+PT.al,58,203); top=232;
    }
    cx.fillStyle='#0e7c86'; cx.font='italic 700 38px Georgia,serif'; cx.fillText('\u211E',48,top+42);
    cx.strokeStyle='#eef3f5'; cx.lineWidth=1;
    for(var y=top+80;y<cv.height-90;y+=50){ cx.beginPath(); cx.moveTo(44,y); cx.lineTo(cv.width-44,y); cx.stroke(); }
    cx.strokeStyle='#c9d6db'; cx.lineWidth=1.3;
    cx.beginPath(); cx.moveTo(cv.width-330,cv.height-70); cx.lineTo(cv.width-44,cv.height-70); cx.stroke();
    cx.fillStyle='#5a6b75'; cx.font='13px system-ui'; cx.textAlign='right';
    cx.fillText(CL.d,cv.width-44,cv.height-48); cx.textAlign='left';
  }
  function snap(){ undo.push(cx.getImageData(0,0,cv.width,cv.height)); if(undo.length>18) undo.shift(); }
  function reset(){ head(); undo=[]; snap(); }
  reset();
  document.getElementById('tpen').onclick=function(){tool='pen';this.classList.add('on');
    document.getElementById('ter').classList.remove('on');};
  document.getElementById('ter').onclick=function(){tool='er';this.classList.add('on');
    document.getElementById('tpen').classList.remove('on');};
  document.querySelectorAll('.sc').forEach(function(b){ b.onclick=function(){
    colour=b.dataset.c; document.querySelectorAll('.sc').forEach(function(x){x.classList.remove('on');});
    b.classList.add('on'); tool='pen';
    document.getElementById('tpen').classList.add('on'); document.getElementById('ter').classList.remove('on'); };});
  document.getElementById('undo').onclick=function(){ if(undo.length>1){undo.pop();cx.putImageData(undo[undo.length-1],0,0);} };
  document.getElementById('clr').onclick=function(){ if(confirm('Clear the page?')) reset(); };
  document.getElementById('retry').onclick=function(){ if(confirm('Clear the page?')) reset(); };
  function pos(e){ var r=cv.getBoundingClientRect();
    return {x:(e.clientX-r.left)*(cv.width/r.width), y:(e.clientY-r.top)*(cv.height/r.height)}; }
  cv.addEventListener('pointerdown',function(e){ e.preventDefault(); cv.setPointerCapture(e.pointerId);
    snap(); drawing=true; last=pos(e); });
  cv.addEventListener('pointermove',function(e){ if(!drawing) return; e.preventDefault();
    var p=pos(e), pr=(e.pointerType==='pen'&&e.pressure>0)?(0.5+e.pressure*1.2):1;
    cx.globalCompositeOperation=(tool==='er')?'destination-out':'source-over';
    cx.strokeStyle=colour; cx.lineWidth=(tool==='er'?26:3*pr); cx.lineCap='round'; cx.lineJoin='round';
    cx.beginPath(); cx.moveTo(last.x,last.y); cx.lineTo(p.x,p.y); cx.stroke(); last=p; });
  ['pointerup','pointercancel','pointerleave'].forEach(function(ev){
    cv.addEventListener(ev,function(){ drawing=false; cx.globalCompositeOperation='source-over'; }); });
} else {
  var pick=document.getElementById('pick'), img=null;
  pick.onchange=function(e){ var f=e.target.files[0]; if(!f) return;
    var rd=new FileReader(); rd.onload=function(ev){ var i=new Image();
      i.onload=function(){ img=i; draw(); }; i.src=ev.target.result; }; rd.readAsDataURL(f); };
  function draw(){
    var MAX=1600, w=img.width, h=img.height, s=Math.min(1,MAX/Math.max(w,h));
    cv.width=Math.round(w*s); cv.height=Math.round(h*s);
    cx.drawImage(img,0,0,cv.width,cv.height);
    var d=cx.getImageData(0,0,cv.width,cv.height), a=d.data, f=(259*(35+255))/(255*(259-35));
    for(var i=0;i<a.length;i+=4){ var g=0.299*a[i]+0.587*a[i+1]+0.114*a[i+2]; g=f*(g-128)+128;
      if(g>186)g=255; else if(g<92)g=(g*0.45)|0; g=g<0?0:(g>255?255:g); a[i]=a[i+1]=a[i+2]=g; }
    cx.putImageData(d,0,0);
    document.getElementById('pb').style.display='none';
    cv.style.display='block'; document.getElementById('send').disabled=false;
  }
  document.getElementById('retry').onclick=function(){ pick.value=''; pick.click(); };
}

document.getElementById('f').addEventListener('submit',function(ev){
  var out=document.createElement('canvas'); out.width=cv.width; out.height=cv.height;
  var ox=out.getContext('2d'); ox.fillStyle='#fff'; ox.fillRect(0,0,out.width,out.height);
  ox.drawImage(cv,0,0);
  document.getElementById('img').value=out.toDataURL(MODE==='scan'?'image/jpeg':'image/png',0.85);
  document.getElementById('send').disabled=true;
  document.getElementById('send').textContent='Sending…';
});
</script>
</body></html>
