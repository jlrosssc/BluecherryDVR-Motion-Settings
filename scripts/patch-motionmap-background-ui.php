<?php

$template = '/usr/share/bluecherry/www/template/ajax/motionmap.php';
$fragment = '/tmp/background_job_ui.phpfrag';

if (!file_exists($template)) {
    fwrite(STDERR, "Missing motion-map template: $template\n");
    exit(1);
}
if (!file_exists($fragment)) {
    fwrite(STDERR, "Missing background UI fragment: $fragment\n");
    exit(1);
}

$text = file_get_contents($template);
$fragmentText = file_get_contents($fragment);
$stamp = date('Ymd-His');
$backup = $template . '.bak-auto-motion-background-' . $stamp;
if (!copy($template, $backup)) {
    fwrite(STDERR, "Could not create backup: $backup\n");
    exit(1);
}

$marker = '/* Recommend Motion Sensitivity background job: start */';
$start = strpos($text, $marker);
if ($start !== false) {
    $blockStart = strrpos(substr($text, 0, $start), "\naddJs(<<<'JS'");
    if ($blockStart === false) {
        $blockStart = strrpos(substr($text, 0, $start), "addJs(<<<'JS'");
    }
    $blockEnd = strpos($text, "\nJS\n);\n", $start);
    if ($blockStart === false || $blockEnd === false) {
        fwrite(STDERR, "Could not replace existing background UI fragment\n");
        exit(1);
    }
    $text = substr($text, 0, $blockStart) . "\n" . $fragmentText . substr($text, $blockEnd + strlen("\nJS\n);\n"));
} else {
    $pos = strrpos($text, '?>');
    if ($pos === false) {
        fwrite(STDERR, "Could not find PHP close tag\n");
        exit(1);
    }
    $text = substr($text, 0, $pos) . "\n" . $fragmentText . "\n" . substr($text, $pos);
}

if (file_put_contents($template, $text) === false) {
    fwrite(STDERR, "Could not write motion-map template\n");
    exit(1);
}

echo "Updated motion-map template. Backup: $backup\n";
