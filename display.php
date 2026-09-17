<?php
/* Waiting-room token board. Open full-screen on a TV.
   PHI-safe: shows token + first name and last initial only. No login required
   so a dumb media player can load it, but it exposes no clinical data. */
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

$pdo=db(); $today=date('Y-m-d');

function shortname(string $n): string {
  $p=preg_split('/\s+/',trim($n));
  return count($p)<2 ? $p[0] : $p[0].' '.mb_substr($p[count($p)-1],0,1).'.';
}
$q=$pdo->prepare("SELECT a.token,a.appt_time,a.status,a.mode,p.name FROM appointments a
                  JOIN patients p ON p.id=a.patient_id
                  WHERE a.appt_date=? AND a.status IN ('Waiting','In Consult')
                  ORDER BY (a.status='In Consult') DESC, a.appt_time");
$q->execute([$today]);
$rows=$q->fetchAll();
$now = array_values(array_filter($rows, fn($r)=>$r['status']==='In Consult'));
$wait= array_values(array_filter($rows, fn($r)=>$r['status']==='Waiting'));
$done=(int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appt_date='$today' AND status='Completed'")->fetchColumn();
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="15">
<title>Now Serving — <?= e(clinic('name')) ?></title>
<style>
 *{box-sizing:border-box;margin:0;padding:0}
 body{background:#07202e;color:#fff;font-family:system-ui,Segoe UI,sans-serif;height:100vh;
      display:flex;flex-direction:column;overflow:hidden}
 header{display:flex;align-items:center;gap:1.2vw;padding:1.6vh 2.4vw;background:#0e7c86}
 .lg{width:4.4vh;height:4.4vh;border-radius:1vh;background:#fff;color:#0e7c86;display:grid;
     place-items:center;font-weight:900;font-size:2.6vh}
 h1{font-size:2.7vh;letter-spacing:.3px}
 header .sub{font-size:1.5vh;opacity:.9}
 .clock{margin-left:auto;text-align:right;font-size:2.6vh;font-weight:800;font-variant-numeric:tabular-nums}
 .clock small{display:block;font-size:1.4vh;font-weight:500;opacity:.9}
 main{flex:1;display:grid;grid-template-columns:1.15fr .85fr;gap:2vw;padding:2.4vh 2.4vw;min-height:0}
 .lbl{font-size:1.7vh;letter-spacing:.22em;text-transform:uppercase;color:#7fd3dc;margin-bottom:1.4vh;font-weight:800}
 .now{background:linear-gradient(160deg,#0e7c86,#0a5c66);border-radius:2.2vh;padding:3vh;
      display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;height:100%}
 .tok{font-size:17vh;font-weight:900;line-height:.95;font-variant-numeric:tabular-nums;
      text-shadow:0 .6vh 2.4vh rgba(0,0,0,.3)}
 .pnm{font-size:4.4vh;font-weight:700;margin-top:1.6vh}
 .rm{font-size:2vh;opacity:.85;margin-top:.8vh;letter-spacing:.1em;text-transform:uppercase}
 .idle{font-size:5vh;font-weight:800;opacity:.85}
 ul{list-style:none;display:flex;flex-direction:column;gap:1.3vh;overflow:hidden}
 li{display:flex;align-items:center;gap:1.6vw;background:#0d2f42;border-radius:1.4vh;padding:1.7vh 2vh}
 li .t{font-size:4.2vh;font-weight:900;color:#4fd1c5;min-width:5.5ch;font-variant-numeric:tabular-nums}
 li .n{font-size:2.9vh;font-weight:600}
 li .tm{margin-left:auto;font-size:2.1vh;opacity:.72;font-variant-numeric:tabular-nums}
 li.nx{background:#12475f;box-shadow:inset 0 0 0 .35vh #4fd1c5}
 li.nx .n:after{content:'NEXT';margin-left:1vw;font-size:1.4vh;background:#4fd1c5;color:#07202e;
                padding:.4vh 1vh;border-radius:1vh;font-weight:900;letter-spacing:.1em;vertical-align:middle}
 .empty{opacity:.55;font-size:2.4vh;padding:3vh 0}
 footer{display:flex;align-items:center;gap:2.4vw;padding:1.5vh 2.4vw;background:#061a25;font-size:1.9vh}
 footer b{color:#4fd1c5}
 .mq{margin-left:auto;opacity:.85;font-size:1.8vh}
</style></head><body>
<header>
  <div class="lg">B</div>
  <div><h1><?= e(clinic('name')) ?></h1>
    <div class="sub"><?= e(clinic('doctor')) ?> · <?= e(clinic('qual')) ?></div></div>
  <div class="clock"><span id="ck"><?= date('h:i A') ?></span>
    <small><?= date('l, j F Y') ?></small></div>
</header>
<main>
  <div><div class="lbl">Now in consultation</div>
    <?php if($now): $c=$now[0]; ?>
      <div class="now">
        <div class="tok"><?= e($c['token']) ?></div>
        <div class="pnm"><?= e(shortname($c['name'])) ?></div>
        <div class="rm">Consultation Room</div>
      </div>
    <?php else: ?>
      <div class="now"><div class="idle">Please wait<br>— you will be called shortly —</div></div>
    <?php endif; ?>
  </div>
  <div style="display:flex;flex-direction:column;min-height:0">
    <div class="lbl">Waiting · <?= count($wait) ?></div>
    <ul>
      <?php if(!$wait): ?><li class="empty" style="background:none">No patients waiting.</li><?php endif; ?>
      <?php foreach(array_slice($wait,0,7) as $i=>$w): ?>
        <li class="<?= $i===0?'nx':'' ?>"><span class="t"><?= e($w['token']) ?></span>
          <span class="n"><?= e(shortname($w['name'])) ?></span>
          <span class="tm"><?= e(date('h:i A', strtotime($w['appt_time']))) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</main>
<footer>
  <div>OPD <b><?= e(clinic('hours')) ?></b></div>
  <div>Appointments on WhatsApp <b><?= e(clinic('phone')) ?></b></div>
  <div>Seen today <b><?= $done ?></b></div>
  <div class="mq">Please keep your reports ready · Mobile phones on silent</div>
</footer>
<script>
setInterval(()=>{const d=new Date();document.getElementById('ck').textContent=
 d.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',hour12:true}).toUpperCase();},1000);
</script>
</body></html>
