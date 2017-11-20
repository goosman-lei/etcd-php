<?php
namespace Etcd;
class Transport {
    protected static $instances = array();

    protected $pconn = false;
    protected $addrs;
    protected $addrs_cnt;
    protected $conns = array();

    public $status = 0;
    public $timeout = 200;
    public $headers = array();
    public $node;
    public $body;

    protected function __construct($addrs, $pconn = false) {
        $this->pconn = $pconn;
        $this->addrs = $addrs;
        $this->addrs_cnt = count($addrs);
    }
    public static function getInstance($addrs, $pconn = false) {
        $sn = md5(json_encode($addrs));
        if (!isset(self::$instances[$sn])) {
            self::$instances[$sn] = new self($addrs, $pconn);
        }
        return self::$instances[$sn];
    }

    protected function clear() {
        $this->timeout = 200; // total request and response
        $this->status = 0;
        $this->headers = array();
    }

    public function get($url, $query = array(), $data = array(), $headers = array()) {
        return $this->request('GET', $url, $query, $data, $headers);
    }

    public function put($url, $query = array(), $data = array(), $headers = array()) {
        return $this->request('PUT', $url, $query, $data, $headers);
    }

    public function post($url, $query = array(), $data = array(), $headers = array()) {
        return $this->request('POST', $url, $query, $data, $headers);
    }

    public function delete($url, $query = array(), $data = array(), $headers = array()) {
        return $this->request('DELETE', $url, $query, $data, $headers);
    }

    public function request($method, $path, $query = array(), $data = array(), $headers = array()) {
        $this->clear();

        $connInfo = $this->getConn();
        if ($connInfo === FALSE) {
            return FALSE;
        }
        list($addr, $conn) = $connInfo;
        $this->node = $addr;

        $request = array(
            'method'  => $method,
            'path'    => $path,
            'headers' => array_merge(array(
                'Host: ' . $addr,
            ), $headers),
        );
        if (!empty($query)) {
            $request['query'] = $query;
        }
        if (!empty($data)) {
            $request['data'] = $data;
        }

        if (FALSE === $this->writeRequest($conn, $request)) {
            $this->closeConn($addr);
            return FALSE;
        }

        $this->body = $this->readResponse($conn);
        if (FALSE === $this->body) {
            $this->closeConn($addr);
            return FALSE;
        }

        return $this->body;
    }

    protected function getConn() {
        $addr = $this->addrs[rand(0, $this->addrs_cnt - 1)];

        if (!isset($this->conns[$addr])) {
            list($host, $port) = explode(':', $addr, 2);
            if ($this->pconn) {
                $conn = @fsockopen($host, $port);
            } else {
                $conn = @pfsockopen($host, $port);
            }
            if ($conn == FALSE) {
                return FALSE;
            }
            stream_set_blocking($conn, false);
            $this->conns[$addr] = $conn;
        }

        return array($addr, $this->conns[$addr]);
    }
    protected function closeConn($addr) {
        fclose($this->conns[$addr]);
        unset($this->conns[$addr]);
    }
    
    // when return false, MUST close this connection
    protected function writeRequest($fp, $request) {
        $begin    = (int)(microtime(TRUE) * 1000);
        $deadline = $begin + $this->timeout;

        $rfd = array();
        $wfd = array($fp);
        $efd = array();
        $nAvailable = stream_select($rfd, $wfd, $efd, (int)($this->timeout / 1000), ($this->timeout % 1000)*1000);
        if ($nAvailable === FALSE || $nAvailable < 1) {
            return FALSE;
        }


        $method     = 'GET';
        $protocol   = 'HTTP/1.1';
        $path       = '/';
        $headers    = array();
        $body       = '';
        if (isset($request['method'])) {
            $method = $request['method'];
        }
        if (isset($request['protocol'])) {
            $protocol = $request['protocol'];
        }
        if (isset($request['path'])) {
            $path = $request['path'];
        }
        if (isset($request['query']) && !empty($request['query'])) {
            $path .= '?' . http_build_query($request['query']);
        }
        if (isset($request['headers']) && !empty($request['headers'])) {
            $headers = array_merge($headers, $request['headers']);
        }
        if (isset($request['data']) && !empty($request['data'])) {
            $body = is_array($request['data']) ? http_build_query($request['data']) : (string)$request['data'];
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: ' . strlen($body);
        } else {
            $headers[] = 'Content-Length: 0';
        }

        $raw  = "{$method} {$path} {$protocol}\r\n";
        foreach ($headers as $header) {
            $raw .= "$header\r\n";
        }
        $raw .= "\r\n";
        if (strlen($body) > 0) {
            $raw .= $body;
        }

        $nWrite = 0;
        do {
            $tmpWrite = fwrite($fp, substr($raw, $nWrite));
            if ($tmpWrite === FALSE) {
                return FALSE;
            }
            $nWrite += $tmpWrite;
        } while ($nWrite < strlen($raw) && (int)(microtime(TRUE) * 1000) < $deadline);

        $this->timeout -= (int)(microtime(TRUE) * 1000) - $begin;
        return TRUE;
    }

    // when return false, MUST close this connection
    protected function readResponse($fp) {
        $begin    = (int)(microtime(TRUE) * 1000);
        $deadline = $begin + $this->timeout;

        $rfd = array($fp);
        $wfd = array();
        $efd = array();
        $nAvailable = stream_select($rfd, $wfd, $efd, (int)($this->timeout / 1000), ($this->timeout % 1000)*1000);
        if ($nAvailable === FALSE || $nAvailable < 1) {
            return FALSE;
        }

        $inHeader = true;
        $body = '';
        do {
            if ($inHeader) {
                $line = fgets($fp);
                if ($line === FALSE) {
                    return FALSE;
                }
                if (strlen($line) <= 0) {
                    usleep(100);
                    continue;
                }
                if (preg_match(';^HTTP/1\.[01]\s+(\d+)\s+.+\r\n$;', $line, $matches)) {
                    $this->status = (int)$matches[1];
                } else if (preg_match(';^([\w-]+)\s*:\s*(.*)\r\n$;', $line, $matches)) {
                    if (isset($this->headers[$matches[1]])) {
                        if (in_array($matches[1], array('Content-Length', 'Transfer-Encoding'))) {
                            // this header MUST have no ambiguity
                            if ($matches[2] != $this->headers[$matches[1]]) {
                                return FALSE;
                            }
                        } else if (is_array($this->headers[$matches[1]])) {
                            $this->headers[$matches[1]][] = $matches[2];
                        } else {
                            $this->headers[$matches[1]] = array($this->headers[$matches[1]], $matches[2]);
                        }
                    } else {
                        $this->headers[$matches[1]] = $matches[2];
                    }
                } else if (preg_match(';^\s*\r\n$;', $line, $matches)) {
                    // RFC 7230 3.3.3
                    // 1. these response have no body
                    if ($this->status < 200 || $this->status == 204 || $this->status == 304) {
                        break;
                    }
                    $inHeader = FALSE;
                }
            } else if (isset($this->headers['Transfer-Encoding']) && $this->headers['Transfer-Encoding'] == 'chunked') {
                // RFC 7230 3.3.3
                // 2. read content with chunked
                // RFC 7230 4.1 chunk body format:
                /*
                chunked-body        = *chunk
                                      last-chunk
                                      trailer-part
                                      CRLF
                chunk               = chunk-size [ chunk-ext ] CRLF
                                      chunk-data CRLF
                chunk-size          = 1*HEXDIG
                last-chunk          = 1*("0") [ chunk-ext ] CRLF
                chunk-data          = 1*OCTET

                chunk-ext           = *( ";" chunk-ext-name [ "=" chunk-ext-val ] )
                chunk-ext-name      = token
                chunk-ext-val       = token

                trailer-part        = *( header-field CRLF )
                header-field        = field-name ":" OWS field-value OWS
                 */
                do {
                    $line = fgets($fp);
                    if ($line === FALSE) {
                        return FALSE;
                    }
                    if (strlen($line) <= 0) {
                        usleep(100);
                        continue;
                    }
                    // chunk first line check
                    if (!preg_match(";^([0-9A-Za-z]+)(?:\;[\w!#\$%&'*+-\.^`\|~]+=[\w!#\$%&'*+-\.^`\|~]+)*\r\n$;", $line, $matches)) {
                        return FALSE;
                    }
                    // +2 is CRLF
                    $chunkSize = hexdec($matches[1]) + 2;
                    $chunkBody = '';
                    do {
                        $buf = fread($fp, $chunkSize - strlen($chunkBody));
                        if ($buf === FALSE) {
                            return FALSE;
                        }
                        $chunkBody .= $buf;
                    } while (strlen($chunkBody) < $chunkSize && (int)(microtime(TRUE) * 1000) < $deadline);

                    // exceed last-chunk
                    if ($chunkSize == 2) {
                        break;
                    }

                    $body .= substr($chunkBody, 0, $chunkSize - 2);
                } while((int)(microtime(TRUE) * 1000) < $deadline);
                break;
                // Here, ignore trailer-part AND lastest CRLF, because etcd server have no response them
            } else if (isset($this->headers['Content-Length']) && $this->headers['Content-Length'] > 0) {
                // RFC 7230 3.3.3
                // 5. read content with Content-Length
                do {
                    $buf = fread($fp, $this->headers['Content-Length']);
                    if ($buf === FALSE) {
                        return FALSE;
                    }
                    $body .= $buf;
                } while (strlen($body) < $this->headers['Content-Length'] && (int)(microtime(TRUE) * 1000) < $deadline);
                break;
            } else {
                // Other Case. Failure
                return FALSE;
            }
        } while ((int)(microtime(TRUE) * 1000) < $deadline);

        $this->timeout -= (int)(microtime(TRUE) * 1000) - $begin;
        return $body;
    }
}
