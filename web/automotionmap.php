<?php

class automotionmap extends Controller {
    public function __construct(){
        parent::__construct();
        $this->chAccess('admin');
    }

    public function postData()
    {
        $action = isset($_POST['action']) ? preg_replace('/[^a-z]/', '', strtolower($_POST['action'])) : 'run';
        if ($action === 'status') {
            $this->status();
        }
        if ($action === 'cancel') {
            $this->cancel();
        }
        if ($action === 'startall') {
            $this->startAll();
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $sensitivity = isset($_POST['sensitivity']) ? intval($_POST['sensitivity']) : 5;
        $noise = isset($_POST['noise_suppression']) ? intval($_POST['noise_suppression']) : 5;
        $mode = isset($_POST['scan_mode']) ? preg_replace('/[^a-z]/', '', strtolower($_POST['scan_mode'])) : 'quick';
        $deep_hours = isset($_POST['deep_hours']) ? intval($_POST['deep_hours']) : 24;
        $samples_per_hour = isset($_POST['samples_per_hour']) ? intval($_POST['samples_per_hour']) : 4;
        $samples = isset($_POST['samples']) ? intval($_POST['samples']) : 0;
        $frames = isset($_POST['frames_per_video']) ? intval($_POST['frames_per_video']) : (($mode === 'deep') ? 3 : 2);

        if ($id < 1 || $sensitivity < 1 || $sensitivity > 10 || $noise < 0 || $noise > 10) {
            $this->json(false, 'Invalid auto-detect request');
        }

        if ($mode !== 'deep' && $mode !== 'optimized') {
            $mode = 'quick';
        }
        $deep_hours = max(24, min(168, $deep_hours));
        $samples_per_hour = max(1, min(8, $samples_per_hour));
        if ($samples < 1) {
            $samples = ($mode === 'deep' || $mode === 'optimized') ? ($deep_hours * $samples_per_hour) : 2;
        }
        $samples = max(1, min(($mode === 'deep' || $mode === 'optimized') ? 672 : 12, $samples));
        $frames = max(2, min(12, $frames));

        $cmd = $this->buildCommand($id, $sensitivity, $noise, $mode, $deep_hours, $samples, $frames);

        if ($action === 'start') {
            $this->startJob($cmd, array(
                'camera_id' => $id,
                'sensitivity' => $sensitivity,
                'noise_suppression' => $noise,
                'scan_mode' => $mode,
                'deep_hours' => $deep_hours,
                'samples_per_hour' => ($mode === 'deep') ? $samples_per_hour : 0,
                'samples' => $samples,
                'frames_per_video' => $frames
            ));
        }

        $output = array();
        $rc = 0;
        exec($cmd . ' 2>&1', $output, $rc);
        $raw = trim(implode("\n", $output));

        if ($rc !== 0 || $raw === '') {
            $this->json(false, 'Auto detect failed: ' . substr($raw, 0, 500));
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['proposed_motion_map'])) {
            $this->json(false, 'Auto detect returned invalid data');
        }

        $map = $payload['proposed_motion_map'];
        if (!preg_match('/^[0-5]+$/', $map)) {
            $this->json(false, 'Auto detect returned invalid motion map');
        }

        $this->json(true, 'Recommended motion sensitivity loaded. Review/edit it, then click Save Changes.', array(
            'motion_map' => $map,
            'camera_id' => $id,
            'camera_name' => isset($payload['camera_name']) ? $payload['camera_name'] : '',
            'current_counts' => isset($payload['current_counts']) ? $payload['current_counts'] : array(),
            'proposed_counts' => isset($payload['proposed_counts']) ? $payload['proposed_counts'] : array(),
            'report' => isset($payload['json_report']) ? $payload['json_report'] : '',
            'preview' => isset($payload['preview_svg']) ? $payload['preview_svg'] : ''
        ));
    }

    private function buildCommand($id, $sensitivity, $noise, $mode, $deep_hours, $samples, $frames)
    {
        $python = is_executable('/usr/bin/python3') ? '/usr/bin/python3' : 'python3';
        $cmd = $python . ' /usr/local/sbin/bluecherry-motion-optimizer-web analyze '
            . '--camera ' . escapeshellarg((string)$id) . ' '
            . '--sensitivity ' . escapeshellarg((string)$sensitivity) . ' '
            . '--noise-suppression ' . escapeshellarg((string)$noise) . ' '
            . '--samples ' . escapeshellarg((string)$samples) . ' '
            . '--frames-per-video ' . escapeshellarg((string)$frames) . ' '
            . '--work-dir ' . escapeshellarg('/var/lib/bluecherry/motion-optimizer') . ' '
            . '--stdout-json';
        if ($mode === 'deep' || $mode === 'optimized') {
            $cmd .= ' --lookback-hours ' . escapeshellarg((string)$deep_hours);
        }
        if ($mode === 'optimized') {
            $cmd .= ' --optimized --optimized-batch-size 24 --optimized-min-samples 48 --optimized-stability-percent 1.0';
        }
        return $cmd;
    }

    private function buildAllCommand()
    {
        $python = is_executable('/usr/bin/python3') ? '/usr/bin/python3' : 'python3';
        return $python . ' /usr/local/sbin/bluecherry-motion-optimizer-web optimize-all '
            . '--sensitivity 8 '
            . '--noise-suppression 5 '
            . '--lookback-hours 168 '
            . '--samples 672 '
            . '--frames-per-video 3 '
            . '--work-dir ' . escapeshellarg('/var/lib/bluecherry/motion-optimizer') . ' '
            . '--stdout-json';
    }

    private function startAll()
    {
        $this->startJob($this->buildAllCommand(), array(
            'job_type' => 'all_cameras',
            'scan_mode' => 'optimized',
            'deep_hours' => 168,
            'samples' => 672,
            'frames_per_video' => 3,
            'sensitivity' => 8,
            'noise_suppression' => 5
        ));
    }

    private function jobsDir()
    {
        $dir = '/var/lib/bluecherry/motion-optimizer/jobs';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            $this->json(false, 'Could not create recommendation job directory');
        }
        if (!is_writable($dir)) {
            $this->json(false, 'Recommendation job directory is not writable');
        }
        return $dir;
    }

    private function startJob($cmd, $meta)
    {
        $dir = $this->jobsDir();
        $job_id = date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $base = $dir . '/' . $job_id;
        $meta_written = file_put_contents($base . '.meta.json', json_encode(array(
            'job_id' => $job_id,
            'started_at' => date('c'),
            'state' => 'running',
            'request' => $meta
        )));
        if ($meta_written === false) {
            $this->json(false, 'Could not write recommendation job metadata');
        }

        $inner = $cmd . ' --progress-file ' . escapeshellarg($base . '.progress.json')
            . ' > ' . escapeshellarg($base . '.result.json')
            . ' 2> ' . escapeshellarg($base . '.error.log')
            . ' & child=$!; echo $child > ' . escapeshellarg($base . '.pid')
            . '; wait $child; rc=$?; echo $rc > ' . escapeshellarg($base . '.exit');
        $shell = 'sh -c ' . escapeshellarg($inner) . ' > /dev/null 2>&1 & echo $!';
        $output = array();
        exec($shell, $output);
        if (!empty($output[0]) && preg_match('/^[0-9]+$/', trim($output[0]))) {
            file_put_contents($base . '.wrapper.pid', trim($output[0]) . "\n");
        }

        $this->json(true, 'Recommendation scan started.', array(
            'job_id' => $job_id,
            'state' => 'running'
        ));
    }

    private function status()
    {
        $job_id = isset($_POST['job_id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['job_id']) : '';
        if ($job_id === '') {
            $this->json(false, 'Missing recommendation job id');
        }

        $base = $this->jobsDir() . '/' . $job_id;
        $progress = $this->readProgress($base);
        $meta = $this->readJsonFile($base . '.meta.json');
        $job_type = isset($meta['request']['job_type']) ? $meta['request']['job_type'] : 'single_camera';
        if (file_exists($base . '.cancelled')) {
            $this->json(true, 'Recommendation scan canceled.', array(
                'job_id' => $job_id,
                'state' => 'canceled',
                'job_type' => $job_type,
                'progress' => $progress
            ));
        }
        $exit_file = $base . '.exit';
        if (!file_exists($exit_file)) {
            $this->json(true, 'Recommendation scan is still running.', array(
                'job_id' => $job_id,
                'state' => 'running',
                'progress' => $progress
            ));
        }

        $rc = intval(trim(file_get_contents($exit_file)));
        if ($rc !== 0) {
            $error = file_exists($base . '.error.log') ? trim(file_get_contents($base . '.error.log')) : '';
            $this->json(false, 'Recommendation scan failed: ' . substr($error, 0, 500), array(
                'job_id' => $job_id,
                'state' => 'failed',
                'progress' => $progress
            ));
        }

        $raw = file_exists($base . '.result.json') ? trim(file_get_contents($base . '.result.json')) : '';
        $payload = json_decode($raw, true);
        if ($job_type === 'all_cameras') {
            if (!is_array($payload) || !isset($payload['results'])) {
                $this->json(false, 'All-camera recommendation returned invalid data', array(
                    'job_id' => $job_id,
                    'state' => 'failed',
                    'progress' => $progress
                ));
            }
            $this->json(true, 'All-camera optimized motion settings complete.', array(
                'job_id' => $job_id,
                'state' => 'complete',
                'job_type' => 'all_cameras',
                'summary' => $payload,
                'progress' => $progress
            ));
        }
        if (!is_array($payload) || empty($payload['proposed_motion_map'])) {
            $this->json(false, 'Recommendation scan returned invalid data', array(
                'job_id' => $job_id,
                'state' => 'failed',
                'progress' => $progress
            ));
        }

        $map = $payload['proposed_motion_map'];
        if (!preg_match('/^[0-5]+$/', $map)) {
            $this->json(false, 'Recommendation scan returned invalid motion map', array(
                'job_id' => $job_id,
                'state' => 'failed',
                'progress' => $progress
            ));
        }

        $this->json(true, 'Recommended motion sensitivity loaded. Review/edit it, then click Save Changes.', array(
            'job_id' => $job_id,
            'state' => 'complete',
            'motion_map' => $map,
            'camera_name' => isset($payload['camera_name']) ? $payload['camera_name'] : '',
            'current_counts' => isset($payload['current_counts']) ? $payload['current_counts'] : array(),
            'proposed_counts' => isset($payload['proposed_counts']) ? $payload['proposed_counts'] : array(),
            'report' => isset($payload['json_report']) ? $payload['json_report'] : '',
            'preview' => isset($payload['preview_svg']) ? $payload['preview_svg'] : '',
            'progress' => $progress
        ));
    }

    private function cancel()
    {
        $job_id = isset($_POST['job_id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['job_id']) : '';
        if ($job_id === '') {
            $this->json(false, 'Missing recommendation job id');
        }

        $base = $this->jobsDir() . '/' . $job_id;
        if (!file_exists($base . '.meta.json')) {
            $this->json(false, 'Recommendation job not found');
        }

        if (file_exists($base . '.exit')) {
            file_put_contents($base . '.cancelled', date('c') . "\n");
            $this->writeCancelProgress($base);
            $this->json(true, 'Recommendation scan is no longer running.', array(
                'job_id' => $job_id,
                'state' => 'canceled',
                'progress' => $this->readProgress($base)
            ));
        }

        $killed = false;
        $pids = array();
        foreach (array($base . '.pid', $base . '.wrapper.pid') as $pid_file) {
            if (!file_exists($pid_file)) {
                continue;
            }
            $pid = intval(trim(file_get_contents($pid_file)));
            if ($pid > 1) {
                $pids[] = $pid;
            }
        }
        $pids = array_unique(array_merge($pids, $this->findPidsForJob($job_id)));
        rsort($pids);
        foreach ($pids as $pid) {
            exec('kill -TERM ' . escapeshellarg((string)$pid) . ' 2>/dev/null', $output, $rc);
            $killed = $killed || ($rc === 0);
        }
        if ($killed) {
            usleep(500000);
        }

        file_put_contents($base . '.cancelled', date('c') . "\n");
        $this->cleanupTempFiles();
        $this->writeCancelProgress($base);
        if (!file_exists($base . '.exit')) {
            file_put_contents($base . '.exit', "130\n");
        }

        $this->json(true, $killed ? 'Recommendation scan canceled.' : 'Recommendation scan marked canceled.', array(
            'job_id' => $job_id,
            'state' => 'canceled',
            'progress' => $this->readProgress($base)
        ));
    }

    private function findPidsForJob($job_id)
    {
        $pids = array();
        exec('ps -ef 2>/dev/null', $lines);
        foreach ($lines as $line) {
            if (strpos($line, $job_id) === false || strpos($line, 'bluecherry-motion-optimizer-web') === false) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line));
            if (isset($parts[1]) && preg_match('/^[0-9]+$/', $parts[1])) {
                $pid = intval($parts[1]);
                if ($pid > 1) {
                    $pids[] = $pid;
                }
            }
        }
        return array_unique($pids);
    }

    private function cleanupTempFiles()
    {
        $root = '/var/lib/bluecherry/motion-optimizer';
        foreach (glob($root . '/camera-*', GLOB_ONLYDIR) as $camera_dir) {
            foreach (array('samples', 'frames') as $name) {
                $path = $camera_dir . '/' . $name;
                if (is_dir($path)) {
                    exec('rm -rf ' . escapeshellarg($path) . ' 2>/dev/null');
                }
            }
        }
    }

    private function writeCancelProgress($base)
    {
        $progress = $this->readProgress($base);
        $progress['state'] = 'canceled';
        $progress['phase'] = 'canceled';
        $progress['message'] = 'Recommendation scan canceled by user.';
        $progress['updated_at'] = date('M j, Y g:i:s A T');
        file_put_contents($base . '.progress.json', json_encode($progress) . "\n");
    }

    private function readProgress($base)
    {
        return $this->readJsonFile($base . '.progress.json');
    }

    private function readJsonFile($file)
    {
        if (!file_exists($file)) {
            return array();
        }
        $raw = trim(file_get_contents($file));
        $payload = json_decode($raw, true);
        return is_array($payload) ? $payload : array();
    }

    private function json($ok, $message, $data = array())
    {
        header('Content-Type: application/json');
        echo json_encode(array(
            'status' => $ok ? 6 : 7,
            'msg' => $message,
            'data' => $data
        ));
        die();
    }
}
