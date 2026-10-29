<?php
// Vérifie requetes.sql sur l'export : php verif_sql.php
// Prérequis : extension PHP pdo_sqlite (sinon voir la remarque affichée).

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Extension pdo_sqlite absente : ouvrez php.ini et décommentez la ligne extension=pdo_sqlite (puis relancez).\n");
    exit(2);
}

$base = __DIR__ . '/base_rendez_vous.sql';
$requetes = __DIR__ . '/requetes.sql';
if (!is_file($base) || !is_file($requetes)) {
    fwrite(STDERR, "base_rendez_vous.sql et requetes.sql doivent être dans le même dossier que ce script.\n");
    exit(2);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents($base));

// On retire les lignes de commentaire puis on découpe sur « ; »
$lignes = array_filter(explode("\n", file_get_contents($requetes)), fn($l) => strpos(ltrim($l), '--') !== 0);
$instructions = array_values(array_filter(array_map('trim', explode(';', implode("\n", $lignes)))));

$noms = ['req 1', 'req 1 bis', 'req 2', 'req 3', 'bonus B1', 'bonus B2', 'bonus B3', 'bonus B4'];
$attendu = [5, 3, 3, 4, 3, 2, 3, 46];

$echecs = 0;
foreach ($instructions as $i => $sql) {
    $n = count($pdo->query($sql)->fetchAll(PDO::FETCH_NUM));
    $ok = isset($attendu[$i]) && $n === $attendu[$i];
    $echecs += $ok ? 0 : 1;
    echo ($ok ? '✅' : '❌') . " {$noms[$i]} : $n lignes (attendu {$attendu[$i]})\n";
}
exit($echecs === 0 ? 0 : 1);