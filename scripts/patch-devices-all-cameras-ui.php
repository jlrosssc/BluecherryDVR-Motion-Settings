<?php

$template = '/usr/share/bluecherry/www/template/ajax/devices.php';
$fragment = '/tmp/devices_all_cameras_ui.phpfrag';

if (!file_exists($template)) {
    fwrite(STDERR, "Missing Devices template: $template\n");
    exit(1);
}
if (!file_exists($fragment)) {
    fwrite(STDERR, "Missing all-camera UI fragment: $fragment\n");
    exit(1);
}

$text = file_get_contents($template);
$fragmentText = file_get_contents($fragment);
$stamp = date('Ymd-His');
$backup = $template . '.bak-all-motion-ui-' . $stamp;
if (!copy($template, $backup)) {
    fwrite(STDERR, "Could not create backup: $backup\n");
    exit(1);
}

$addip = '<a href="/addip" class="btn btn-success ajax-content" role="button"><i class="fa fa-plus fa-fw"></i> <?php echo AIP_HEADER; ?></a>';
$button = '<button type="button" class="btn btn-warning" id="auto-motion-all-cameras" data-loading-text="Running..."><i class="fa fa-magic fa-fw"></i> Auto Detect Motion Settings All Cameras</button>' . "\n            " . $addip;
if (strpos($text, 'id="auto-motion-all-cameras"') === false) {
    if (strpos($text, $addip) === false) {
        fwrite(STDERR, "Could not find Add IP button insertion point\n");
        exit(1);
    }
    $text = preg_replace('/' . preg_quote($addip, '/') . '/', $button, $text, 1);
}

if (strpos($text, 'id="auto-motion-all-status"') === false) {
    $needle = '        <div class="clearfix"></div>';
    $status = $needle . "\n" . '        <div id="auto-motion-all-status" class="text-muted small" style="margin-top:8px;"></div>';
    if (strpos($text, $needle) === false) {
        fwrite(STDERR, "Could not find Devices status insertion point\n");
        exit(1);
    }
    $text = preg_replace('/' . preg_quote($needle, '/') . '/', $status, $text, 1);
}

$marker = '/* Auto Detect Motion Settings All Cameras: start */';
$start = strpos($text, $marker);
if ($start !== false) {
    $phpStart = strrpos(substr($text, 0, $start), '<?php');
    $phpEnd = strpos($text, '?>', $start);
    if ($phpStart === false || $phpEnd === false) {
        fwrite(STDERR, "Could not replace existing all-camera UI fragment\n");
        exit(1);
    }
    $text = substr($text, 0, $phpStart) . $fragmentText . substr($text, $phpEnd + 2);
} else {
    $text = rtrim($text) . "\n" . $fragmentText . "\n";
}

if (file_put_contents($template, $text) === false) {
    fwrite(STDERR, "Could not write Devices template\n");
    exit(1);
}

echo "Updated Devices template. Backup: $backup\n";
