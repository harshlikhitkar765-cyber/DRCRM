<?php
/* One-click backup: a real SQL dump of the database plus the image folders. */
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

 require_login();

/* ----------------------------------------------------------
   Produce a real .sql dump of the MySQL database, in pure PHP.
   Shared hosts often disable exec(), so mysqldump cannot be
   relied on — this walks the tables and writes the INSERTs.
   ---------------------------------------------------------- */
function sql_dump(PDO $pdo): string {
    $out  = "-- Dr Bakshi Clinic backup\n";
    $out .= '-- taken ' . date('Y-m-d H:i:s') . " (Asia/Kolkata)\n";
    $out .= "-- restore with:  mysql -u USER -p DBNAME < thisfile.sql\n\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $out .= "DROP TABLE IF EXISTS `$t`;\n$create;\n\n";

        $rows = $pdo->query("SELECT * FROM `$t`");
        $buf = [];
        while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(function ($v) use ($pdo) {
                return $v === null ? 'NULL' : $pdo->quote((string)$v);
            }, array_values($r));
            $buf[] = '(' . implode(',', $vals) . ')';
            if (count($buf) >= 200) {
                $out .= "INSERT INTO `$t` VALUES\n" . implode(",\n", $buf) . ";\n";
                $buf = [];
            }
        }
        if ($buf) $out .= "INSERT INTO `$t` VALUES\n" . implode(",\n", $buf) . ";\n";
        $out .= "\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $out;
}

if (isset($_GET['download'])) {
    $sql = sql_dump(db());
    $stamp = date('Ymd-His');

    if (!class_exists('ZipArchive')) {
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="clinic-'.$stamp.'.sql"');
        header('Content-Length: '.strlen($sql));
        audit('backup_download','db',0,'plain sql');
        echo $sql; exit;
    }

    $zipPath = sys_get_temp_dir().'/drbakshi-backup-'.$stamp.'.zip';
    $z = new ZipArchive();
    $z->open($zipPath, ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $z->addFromString('clinic-'.$stamp.'.sql', $sql);
    foreach (['rx','docs'] as $sub) {
        $dir = __DIR__.'/data/'.$sub;
        if (!is_dir($dir)) continue;
        foreach (glob($dir.'/*') ?: [] as $f) if (is_file($f)) $z->addFile($f, $sub.'/'.basename($f));
    }
    $z->close();
    audit('backup_download','db',0,'zip + sql');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.basename($zipPath).'"');
    header('Content-Length: '.filesize($zipPath));
    readfile($zipPath); @unlink($zipPath); exit;
}

$pdo=db();
$dbsize=(float)$pdo->query("SELECT COALESCE(SUM(data_length+index_length),0)
                            FROM information_schema.tables
                            WHERE table_schema = DATABASE()")->fetchColumn();
$counts=[];
foreach (['patients','appointments','prescriptions','payments','documents','wa_messages','audit'] as $t) {
    $counts[$t]=(int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}
$imgs=count(glob(__DIR__.'/data/rx/*')?:[])+count(glob(__DIR__.'/data/docs/*')?:[]);
$log=$pdo->query('SELECT * FROM audit ORDER BY id DESC LIMIT 25')->fetchAll();
head('Backup & Audit');
?>
<div class="page-h"><div><h1>Backup &amp; audit</h1>
  <p>The entire clinic is one file — protect it</p></div></div>

<div class="grid g2" style="align-items:start">
  <div>
    <div class="card" style="border:1px solid var(--v)">
      <h3>Download a backup</h3>
      <div class="sub">Database + every prescription and report image</div>
      <div class="tw"><table style="margin:9px 0"><tbody>
      <?php foreach($counts as $t=>$c): ?>
        <tr><td style="color:var(--muted);text-transform:capitalize"><?= e(str_replace('_',' ',$t)) ?></td>
          <td style="text-align:right"><b><?= number_format($c) ?></b></td></tr>
      <?php endforeach; ?>
        <tr><td style="color:var(--muted)">Images</td><td style="text-align:right"><b><?= $imgs ?></b></td></tr>
        <tr><td style="color:var(--muted)">Database size</td>
          <td style="text-align:right"><b><?= $dbsize>0?number_format($dbsize/1024,0).' KB':'—' ?></b></td></tr>
      </tbody></table></div>
      <a class="btn" style="width:100%;text-align:center;padding:11px" href="?download=1">⬇ Download backup now</a>
      <div class="ph" style="margin-top:9px"><b>Do this every evening.</b> Copy it to a pen drive or
        Google Drive. If this machine dies and you have no backup, every patient record is gone.</div>
    </div>
    <div class="card" style="background:#fff6e5;border:1px solid var(--amber)">
      <b style="font-size:13px">Automate it (recommended)</b>
      <div class="ph" style="margin-top:6px">Add to <code>crontab -e</code> on the server —
        keeps a nightly dump for 30 days:</div>
      <pre style="background:#0f2a3d;color:#d7e8ea;padding:10px;border-radius:8px;font-size:11px;
           overflow:auto;margin-top:8px">30 22 * * * mysqldump -u <?= e(DB_USER) ?> -p'YOUR_PASSWORD' <?= e(DB_NAME) ?> \
  | gzip > ~/clinic-backups/clinic-$(date +\%Y\%m\%d).sql.gz && \
  find ~/clinic-backups -mtime +30 -delete</pre>
      <div class="ph" style="margin-top:7px">Also copy <code>data/rx</code> and <code>data/docs</code> —
        the prescription and report images are files, not database rows.</div>
    </div>
  </div>

  <div class="card p0"><div style="padding:13px 16px 0"><h3>Audit trail</h3>
    <div class="sub">Who did what — required under DPDP</div></div>
    <div class="tw"><table><thead><tr><th>User</th><th>Action</th><th>Record</th><th>When</th></tr></thead><tbody>
    <?php if(!$log): ?><tr><td colspan="4" class="empty">Nothing logged yet.</td></tr><?php endif; ?>
    <?php foreach($log as $a): ?>
      <tr><td style="font-size:12px"><b><?= e($a['username']) ?></b></td>
        <td><span class="pill <?= str_contains((string)$a['action'],'fail')?'p-red':'p-gray' ?>"><?= e($a['action']) ?></span></td>
        <td style="font-size:11.5px;color:var(--muted)"><?= e($a['entity']) ?><?= $a['entity_id']?' #'.(int)$a['entity_id']:'' ?>
          <?= trim((string)$a['detail'])!==''?' · '.e($a['detail']):'' ?></td>
        <td style="font-size:11.5px"><?= e($a['at']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php foot(); ?>
