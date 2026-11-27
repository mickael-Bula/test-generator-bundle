<?php

// Faux PHPUnit pour les tests de la classe de sonde générée : rapporte le fichier de sonde reçu (dernier argument) et son contenu.

declare(strict_types=1);

if ('silent' === getenv('FAKE_PROBE_MODE')) {
    fwrite(STDERR, "boom\n");
    exit(3);
}

$probe = (string) end($argv);
$exists = is_file($probe);

file_put_contents((string) getenv('MIKA_PROBE_OUTPUT'), json_encode([
    'status' => 'failed',
    'steps' => [],
    'expectations' => [],
    'error' => 'probe=' . $probe . ';exists=' . ($exists ? '1' : '0') . ';args=' . implode(' ', array_slice($argv, 1, -1)) . "\n" . ($exists ? file_get_contents($probe) : ''),
]));
