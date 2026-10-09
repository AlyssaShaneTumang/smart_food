<?php require '../config.php'; deviceAuth(); $rows=db()->query("SELECT id,name FROM lockers WHERE status='AVAILABLE' ORDER BY id")->fetchAll();jsonOut(['ok'=>true,'lockers'=>$rows]);
