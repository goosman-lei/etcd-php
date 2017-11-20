<?php
namespace Etcd;
class KeysApi {
    protected $transport;

    protected $uri_version = '/version';
    protected $uri_keys = '/v2/keys';

    protected function __construct($opts) {
        $this->transport = Transport::getInstance($opts['addrs'], $opts['pconn']);
    }

    public static function create($opts = array()) {
        $defaultOpts = array(
            'addrs' => array('127.0.0.1:2379'),
            'pconn' => false,
        );
        $opts = array_merge($defaultOpts, $opts);
        return new self($opts);
    }

    const Err_Http_Not_Ok = 'http status not ok: %d';
    const Err_Invalid_Json = 'response is invalid json: %s';

    public $errno;
    public $error;
    public $data;

    protected function clear() {
        $this->error = '';
        $this->errno = 0;
        $this->data  = null;
    }

    protected function errorNoOk() {
        $this->error = sprintf(self::Err_Http_Not_Ok, $this->transport->status);
        $this->errno = -1;
        $this->data  = FALSE;
    }

    protected function errorInvalidJson() {
        $this->error = sprintf(self::Err_Invalid_Json, $this->transport->body);
        $this->errno = -1;
        $this->data  = FALSE;
    }

    protected function errorResponse($data) {
        $this->error = $data['message'];
        $this->errno = $data['errorCode'];
        $this->data  = FALSE;
    }

    protected function checkAndDecode() {
        if ($this->transport->status < 200 || $this->transport->status >= 300 || !$this->transport->body) {
            $this->errorNoOk();
            return FALSE;
        }
        $data = json_decode($this->transport->body, TRUE);
        if (JSON_ERROR_NONE !== json_last_error()) {
            $this->errorInvalidJson();
            return FALSE;
        }
        if (isset($data['errorCode']) && $data['errorCode']) {
            $this->errorResponse($data);
            return FALSE;
        }
        $this->data = $data;
        return TRUE;
   }

    public function getVersion() {
        $this->clear();

        $this->transport->get($this->uri_version);

        $this->checkAndDecode();

        return $this->data;
    }

    public function set($key, $value, $ttl = null, $condition = array()) {
        $this->clear();

        $data = array('value' => $value);
        if (isset($ttl)) {
            $data['ttl'] = $ttl;
        }

        $this->transport->put($this->uri_keys . '/' . ltrim($key, '/'), $condition, $data);

        $this->checkAndDecode();

        return $this->data;
    }

    public function getNode($key) {
        $this->clear();

        $this->transport->get($this->uri_keys . '/' . ltrim($key, '/'));

        $this->checkAndDecode();

        return isset($this->data['node']) ? $this->data['node'] : array();
    }

    public function get($key) {
        $node = $this->getNode($key);

        if (!empty($node) && isset($node['value'])) {
            return $node['value'];
        }
        return "";
    }

    public function mk($key, $value, $ttl = null) {
        return $this->set($key, $value, $ttl);
    }

    public function mkdir($key, $ttl = null) {
        $this->clear();

        $data = array('dir' => 'true');
        if (isset($ttl)) {
            $data['ttl'] = $ttl;
        }

        $query = array('prevExist' => 'false');

        $this->transport->put($this->uri_keys . '/' . ltrim($key, '/'), $query, $data);

        $this->checkAndDecode();

        return $this->data;
    }

    public function update($key, $value, $ttl = null, $condition = array()) {
        $extra = array('prevExist' => 'true');
        if ($condition) {
            $extra = array_merge($extra, $condition);
        }

        return $this->set($key, $value, $ttl, $extra);
    }

    public function updateDir($key, $ttl) {
        $this->clear();

        $query = array(
            'dir'       => 'true',
            'prevExist' => 'true'
        );

        $data = array('ttl' => $ttl);

        $this->transport->put($this->uri_keys . '/' . ltrim($key, '/'), $query, $data);

        $this->checkAndDecode();

        return $this->data;
    }

    public function rm($key) {
        $this->clear();

        $this->transport->delete($this->uri_keys . '/' . ltrim($key, '/'));

        $this->checkAndDecode();

        return $this->data;
    }

    public function rmdir($key, $recursive = false) {
        $this->clear();

        $this->transport->delete($this->uri_keys . '/' . ltrim($key, '/'), array('dir' => 'true'));

        $this->checkAndDecode();

        return $this->data;
    }

    public function listDir($key = '/', $recursive = false) {
        $this->clear();

        $query = array();
        if ($recursive) {
            $query['recursive'] = 'true';
        }

        $this->transport->get($this->uri_keys . '/' . ltrim($key, '/'), $query);

        $this->checkAndDecode();

        return $this->data;
    }

    public function ls($key = '/', $recursive = false) {
        $this->values = array();
        $this->dirs = array();

        $this->listDir($key, $recursive);
        if ($this->errno  != 0) {
            return $this->data;
        }

        $iterator = new RecursiveArrayIterator($data);
        return $this->traversalDir($iterator);
    }

    protected $dirs = array();
    protected $values = array();

    protected function traversalDir(RecursiveArrayIterator $iterator) {
        $key = '';
        while ($iterator->valid()) {
            if ($iterator->hasChildren()) {
                $this->traversalDir($iterator->getChildren());
            } else {
                if ($iterator->key() == 'key' && ($iterator->current() != '/')) {
                    $this->dirs[] = $key = $iterator->current();
                }

                if ($iterator->key() == 'value') {
                    $this->values[$key] = $iterator->current();
                }
            }
            $iterator->next();
        }
        return $this->dirs;
    }

    public function getKeysValue($root = '/', $recursive = true, $key = null) {
        $this->ls($root, $recursive);
        if (isset($this->values[$key])) {
            return $this->values[$key];
        }
        return $this->values;
    }

    public function mkdirWithInOrderKey($dir, $ttl = null) {
        $this->clear();

        $data = array('dir' => 'true');
        if (isset($ttl)) {
            $data['ttl'] = $ttl;
        }

        $this->transport->post($this->uri_keys . '/' . ltrim($key, '/'), array(), $data);

        $this->checkAndDecode();

        return $this->data;
    }

    public function setWithInOrderKey($dir, $value, $ttl = null, $query = array()) {
        $this->clear();

        $data = array('value' => $value);
        if (isset($ttl)) {
            $data['ttl'] = $ttl;
        }

        $this->transport->post($this->uri_keys . '/' . ltrim($key, '/'), $query, $data);

        $this->checkAndDecode();

        return $this->data;
    }
}
