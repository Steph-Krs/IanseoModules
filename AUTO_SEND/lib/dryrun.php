<?php
/**
 * Simulation mode: the core upload runs for real, and nothing leaves the server.
 *
 * The core talks to ianseo.net with fopen() on $CFG->IanseoServer, twice: once
 * to check the credentials (TourCheckCodes.php), once to upload
 * (Upload-Competition.php). In simulation that address is pointed at a stream
 * wrapper registered here, which answers both calls the way ianseo.net answers
 * a successful exchange. Everything else is the core's own code, unchanged:
 * the rankings are built, serialised and compressed exactly as for a real
 * upload, which is what makes a simulation a faithful measure of the cost.
 *
 * The wrapper also checks that the payload decompresses and unserialises, and
 * reports its size on STDERR for the scheduled task to record.
 *
 * AUTOSEND_DRY_FAULT, an environment variable read only here and therefore
 * only in simulation, rehearses the failures the module must survive:
 *   network — ianseo.net unreachable;
 *   reject  — ianseo.net refuses the upload;
 *   hang    — ianseo.net never answers (the scheduled task must stop the upload).
 */

const AUS_DRY_SCHEME = 'autosend-dry';

/**
 * Point the core's ianseo.net address at the simulated server.
 */
function aus_dryrun_enable() {
    global $CFG;
    if (!in_array(AUS_DRY_SCHEME, stream_get_wrappers(), true)) {
        stream_wrapper_register(AUS_DRY_SCHEME, 'AusDryRunStream');
    }
    $CFG->IanseoServer = AUS_DRY_SCHEME . '://ianseo.net/';
}

/**
 * Is the core's ianseo.net address the simulated one?
 *
 * @return bool
 */
function aus_dryrun_active() {
    global $CFG;
    // bytes: comparing an ASCII scheme prefix.
    return strpos((string)$CFG->IanseoServer, AUS_DRY_SCHEME . '://') === 0;
}

/**
 * Stream wrapper standing in for ianseo.net.
 */
class AusDryRunStream {
    /** @var resource|null Set by PHP: the context passed to fopen(). */
    public $context;

    private $body = '';
    private $pos = 0;

    public function stream_open($path, $mode, $options, &$opened_path) {
        $fault = (string)getenv('AUTOSEND_DRY_FAULT');
        if ($fault === 'network') return false;
        if ($fault === 'hang') sleep(3600);

        $opts = $this->context ? stream_context_get_options($this->context) : [];
        $content = (string)($opts['http']['content'] ?? '');
        $page = basename((string)parse_url($path, PHP_URL_PATH));

        if ($page === 'TourCheckCodes.php') {
            $answer = ['error' => 0, 'services' => 1, 'files' => [], 'urls' => [], 'imgs' => 0];
        } elseif ($page === 'Upload-Competition.php') {
            $answer = $fault === 'reject'
                ? ['error' => 1, 'msg' => 'Simulated refusal']
                : self::upload($content);
        } else {
            $answer = ['error' => 1, 'msg' => 'Unknown address'];
        }
        $this->body = json_encode($answer);
        $this->pos = 0;
        return true;
    }

    public function stream_read($count) {
        // bytes: a stream hands out bytes, whatever they encode.
        $chunk = substr($this->body, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
    }

    public function stream_eof() {
        // bytes: position in the answer, counted in bytes like stream_read().
        return $this->pos >= strlen($this->body);
    }

    public function stream_stat() {
        return [];
    }

    public function stream_close() {
    }

    /**
     * Check what the core would have posted, and describe it on STDERR.
     *
     * @param string $content The urlencoded body of the POST.
     * @return array The answer ianseo.net gives to a good upload.
     */
    private static function upload($content) {
        parse_str($content, $post);
        $packed = (string)($post['Tour'] ?? '');
        $raw = $packed === '' ? false : @gzuncompress($packed);
        $data = $raw === false ? false : @unserialize($raw, ['allowed_classes' => ['stdClass']]);
        if (!is_object($data)) {
            return ['error' => 1, 'msg' => 'Unreadable payload'];
        }

        $sections = [];
        foreach (['IQ', 'TQ', 'IE', 'IP', 'IR', 'IB', 'TB', 'IF', 'TF', 'IC', 'TC', 'MEDSTD', 'MEDLST'] as $k) {
            if (!isset($data->$k)) continue;
            $sections[$k] = is_object($data->$k) ? count(get_object_vars($data->$k)) : 1;
        }
        // bytes: the size of the compressed payload, as it would travel.
        $stats = ['bytes' => strlen($packed), 'sections' => $sections];
        fwrite(STDERR, 'AUTOSEND-DRY ' . json_encode($stats) . "\n");

        return ['error' => 0, 'files' => [], 'urls' => []];
    }
}
