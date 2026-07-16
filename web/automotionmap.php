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

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $sensitivity = isset($_POST['sensitivity']) ? intval($_POST['sensitivity']) : 5;
        $noise = isset($_POST['noise_suppression']) ? intval($_POST['noise_suppression']) : 5;
        $mode = isset($_POST['scan_mode']) ? preg_replace('/[^a-z]/', '', strtolower($_POST['scan_mode'])) : 'quick';
        $deep_hours = isset($_POST['deep_hours']) ? intval($_POST['deep_hours']) : 24;
        $samples = isset($_POST['samples']) ? intval($_POST['samples']) : (($mode === 'deep') ? 24 : 2);
        $frames = isset($_POST['frames_per_video']) ? intval($_POST['frames_per_video']) : (($mode === 'deep') ? 3 : 2);

        if ($id < 1 || $sensitivity < 1 || $sensitivity > 10 || $noise < 0 || $noise > 10) {
            $this->json(false, 'Invalid auto-detect request');
        }

        if ($mode !== 'deep') {
            $mode = 'quick';
        }
        $deep_hours = max(24, min(168, $deep_hours));
        $samples = max(1, min(36, $samples));
        $frames = max(2, min(12, $frames));

        $cmd = $this->buildCommand($id, $sensitivity, $noise, $mode, $deep_hours, $samples, $frames);

        if ($action === 'start') {
            $this->startJob($cmd, array(
                'camera_id' => $id,
                'sensitivity' => $sensitivity,
                'noise_suppression' => $noise,
                'scan_mode' => $mode,
                'deep_hours' => $deep_hours,
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
        if ($mode === 'deep') {
            $cmd .= ' --lookback-hours ' . escapeshellarg((string)$deep_hours);
        }
        return $cmd;
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

        $shell = 'sh -c ' . escapeshellarg(
            $cmd . ' --progress-file ' . escapeshellarg($base . '.progress.json')
            . ' > ' . escapeshellarg($base . '.result.json')
            . ' 2> ' . escapeshellarg($base . '.error.log')
            . '; rc=$?; echo $rc > ' . escapeshellarg($base . '.exit')
        ) . ' > /dev/null 2>&1 &';
        exec($shell);

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

    private function readProgress($base)
    {
        $file = $base . '.progress.json';
        if (!file_exists($file)) {
            return array();
        }
        $raw = trim(file_get_contents($file));
        $progress = json_decode($raw, true);
        return is_array($progress) ? $progress : array();
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
