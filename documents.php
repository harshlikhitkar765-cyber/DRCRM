<?php
/* Lab reports and other paper, photographed into the patient chart. */
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

 require_login();
$pdo=db();
$pid=(int)($_GET['patient'] ?? $_POST['patient_id'] ?? 0);
$q=$pdo->prepare('SELECT * FROM patients WHERE id=?'); $q->execute([$pid]); $pt=$q->fetch();
if(!$pt){ http_response_code(404); exit('Patient not found'); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    try {
        $fn = store_image_data_url((string)($_POST['img'] ?? ''), __DIR__.'/data/docs', 'doc_'.$pid);
        $pdo->prepare('INSERT INTO documents(patient_id,kind,title,file,doc_date) VALUES(?,?,?,?,?)')
            ->execute([$pid,pf('kind'),pf('title'),$fn,dnull((string)$_POST['doc_date'])]);
        audit('doc_add','patient',$pid,pf('kind'));
        $_SESSION['ok']='Report saved to the chart.';
    } catch (Throwable $e) {
        $_SESSION['err'] = $e->getMessage();
    }
    redirect('documents.php?patient='.$pid);
}
$docs=$pdo->prepare('SELECT * FROM documents WHERE patient_id=? ORDER BY id DESC');
$docs->execute([$pid]); $list=$docs->fetchAll();
head('Reports — '.$pt['name']);
?>
<div class="page-h"><div><h1>Reports &amp; documents</h1>
  <p><?= e($pt['name']) ?> · <?= count($list) ?> on file</p></div>
  <div class="spacer"></div><a class="btn ghost" href="patient.php?id=<?= $pid ?>">← Chart</a></div>

<div class="grid g2" style="align-items:start">
  <div class="card"><h3>Add a report</h3><div class="sub">Photograph the paper the patient brought</div>
    <form method="post" id="df">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="patient_id" value="<?= $pid ?>">
      <input type="hidden" name="img" id="img">
      <label for="pk" class="pickbox2"><div style="font-size:40px">🧪</div>
        <b>Tap to photograph the report</b>
        <span style="font-size:12px;color:var(--muted)">Opens the camera on a phone</span></label>
      <input id="pk" type="file" accept="image/*" capture="environment" hidden>
      <div id="pv" style="margin:12px 0"></div>
      <div class="row2">
        <div class="field"><label>Type</label><select name="kind">
          <option>Lab Report</option><option>X-Ray / Scan</option><option>ECG</option>
          <option>Discharge Summary</option><option>Referral</option><option>Other</option></select></div>
        <div class="field"><label>Date on report</label>
          <input type="date" name="doc_date" value="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="field"><label>Title</label><input name="title" placeholder="e.g. CBC + HbA1c, Dr Lal PathLabs"></div>
      <button class="btn" id="sv" disabled>Save to chart</button>
    </form>
  </div>
  <div class="card p0"><div style="padding:13px 16px 0"><h3>On file</h3>
    <div class="sub">Newest first</div></div>
    <?php if(!$list): ?><div class="empty">No reports yet.</div><?php endif; ?>
    <div style="padding:0 16px 16px;display:grid;grid-template-columns:1fr 1fr;gap:11px">
    <?php foreach($list as $d): ?>
      <a href="docimg.php?id=<?= (int)$d['id'] ?>" target="_blank" style="text-decoration:none;color:inherit">
        <div style="border:1px solid var(--line);border-radius:10px;overflow:hidden">
          <img src="docimg.php?id=<?= (int)$d['id'] ?>" style="width:100%;height:130px;object-fit:cover;display:block">
          <div style="padding:8px 10px"><b style="font-size:12px"><?= e($d['title'] ?: $d['kind']) ?></b>
            <div class="psub"><?= e($d['kind']) ?> · <?= e($d['doc_date']) ?></div></div></div></a>
    <?php endforeach; ?>
    </div>
  </div>
</div>
<style>.pickbox2{display:flex;flex-direction:column;align-items:center;gap:6px;padding:28px 16px;cursor:pointer;
 border:2px dashed var(--v);border-radius:12px;background:var(--v-ll);text-align:center}</style>
<script>
var pk=document.getElementById('pk');
pk.onchange=function(e){var f=e.target.files[0];if(!f)return;var r=new FileReader();
 r.onload=function(ev){var i=new Image();i.onload=function(){
   var c=document.createElement('canvas'),MAX=1600,s=Math.min(1,MAX/Math.max(i.width,i.height));
   c.width=Math.round(i.width*s);c.height=Math.round(i.height*s);
   c.getContext('2d').drawImage(i,0,0,c.width,c.height);
   var u=c.toDataURL('image/jpeg',0.82);
   document.getElementById('img').value=u;
   document.getElementById('pv').innerHTML='<img src="'+u+'" style="width:100%;border-radius:9px">';
   document.getElementById('sv').disabled=false;
 };i.src=ev.target.result;};r.readAsDataURL(f);};
</script>
<?php foot(); ?>
