<?php

// Fausse sonde pour PhpUnitProbeRunnerTest : se comporte selon FAKE_PROBE_MODE.

declare(strict_types=1);

$mode = getenv('FAKE_PROBE_MODE') ?: 'ok';
$out = (string) getenv('MIKA_PROBE_OUTPUT');
$write = static fn (array $data): int|false => file_put_contents($out, json_encode($data));

switch ($mode) {
    case 'ok':
        $write(['status' => 'ok', 'steps' => [], 'expectations' => [], 'warnings' => ['avertissement de la sonde']]);
        break;
    case 'echo':
        $decisions = getenv('MIKA_PROBE_DECISIONS');
        $write(['status' => 'failed', 'steps' => [], 'expectations' => [], 'error' => 'scenario=' . getenv('MIKA_PROBE_SCENARIO') . ';decisions=' . (false === $decisions ? 'none' : file_get_contents($decisions))]);
        break;
    case 'env':
        $write(['status' => 'failed', 'steps' => [], 'expectations' => [], 'error' => 'app_env=' . getenv('APP_ENV') . ';xdebug=' . getenv('XDEBUG_MODE') . ';cwd=' . getcwd()]);
        break;
    case 'garbage':
        file_put_contents($out, '{oops');
        break;
    case 'silent':
        fwrite(STDERR, "boom\n");
        echo "sortie partielle\n";
        exit(3);
    case 'sleep':
        sleep(10);
        break;
}
