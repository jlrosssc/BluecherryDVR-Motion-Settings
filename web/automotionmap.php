<?php

class automotionmap extends Controller {
    public function __construct(){
        parent::__construct();
        $this->chAccess('admin');
    }

    public function postData()
    {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $sensitivity = isset($_POST['sensitivity']) ? intval($_POST['sensitivity']) : 5;
        $noise = isset($_POST['noise_suppression']) ? intval($_POST['noise_suppression']) : 5;
        $samples = isset($_POST['samples']) ? intval($_POST['samples']) : 4;
        $frames = isset($_POST['frames_per_video']) ? intval($_POST['frames_per_video']) : 4;

        if ($id < 1 || $sensitivity < 1 || $sensitivity > 10 || $noise < 0 || $noise > 10) {
            $this->json(false, 'Invalid auto-detect request');
        }

        $samples = max(1, min(12, $samples));
        $frames = max(2, min(12, $frames));

        if (is_executable('/usr/local/sbin/bluecherry-auto-motion-helper')) {
            $cmd = '/usr/local/sbin/bluecherry-auto-motion-helper '
                . escapeshellarg((string)$id) . ' '
                . escapeshellarg((string)$sensitivity) . ' '
                . escapeshellarg((string)$noise) . ' '
                . escapeshellarg((string)$samples) . ' '
                . escapeshellarg((string)$frames);
        } else {
            $python = is_executable('/usr/bin/python3') ? '/usr/bin/python3' : 'python3';
            $cmd = $python . ' /usr/local/sbin/bluecherry-motion-optimizer-web analyze '
                . '--camera ' . escapeshellarg((string)$id) . ' '
                . '--sensitivity ' . escapeshellarg((string)$sensitivity) . ' '
                . '--noise-suppression ' . escapeshellarg((string)$noise) . ' '
                . '--samples ' . escapeshellarg((string)$samples) . ' '
                . '--frames-per-video ' . escapeshellarg((string)$frames) . ' '
                . '--work-dir ' . escapeshellarg('/var/lib/bluecherry/motion-optimizer') . ' '
                . '--stdout-json';
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
