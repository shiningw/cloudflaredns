<?php
namespace shiningw\cloudflare;

$key = 'YOUR API KEY';
$email = 'YOUR EMAIL ADDRESS';
$baseUrl = 'https://api.cloudflare.com/client/v4';

$cloudflare = new Cloudflare($key, $email, $baseUrl);

$opts = getopt('d:DCULAc:n:', array('name:', 'type:', 'domain:', 'content:', ':all'));

main($opts);

function main($opts)
{
    global $cloudflare;
    extract($opts);
    $defaults = array(
        'type' => 'A',
        'ttl' => 120,
        'proxied' => false,
    );
    isset($content) || $content = null;
    isset($name) || $name = null;
    isset($ttl) || $ttl = null;
    isset($type) || $type = null;

    $domain = isset($d) ? $d : $domain;
    $name = isset($n) ? $n : $name;
    $type = isset($t) ? $t : $type;
    $content = isset($c) ? $c : $content;
    $ttl = isset($T) ? $T : $ttl;
    $delete = isset($D) ? 1 : null;
    $create = isset($C) ? 1 : null;
    $update = isset($U) ? 1 : null;
    $list = isset($L) ? 1 : null;
    $all = isset($A) ? 1 : null;

    if (!isset($domain)) {
        echo "Please set the domain name parameter\n";
        showHelp();
        exit;
    }
    $operation = $delete + $create + $update + $list + $all;
    if ($operation > 1 && isset($list) && isset($all)) {
        $res = $cloudflare->listAllRecords($domain);
        $records = [];
        foreach ($res as $result) {
            $result = (array) $result;
            $records[$result['content']] = filterRecords($result);
        }

        print_r($records);
        exit;
    } else if (isset($list) && $operation == 1) {
        if (isset($content)) {
            $paras['content'] = $content;
        }
        if (isset($name)) {
            $paras['name'] = $name;
        }
        $res = $cloudflare->listRecord($domain, $paras);
        //print_r($res);
        if (!$res) {
            echo "no records found \n";
        }
        $records = [];
        foreach ($res as $result) {
            $result = (array) $result;
            $records[] = filterRecords($result);
        }

        print_r($records);
        exit;
    }

    if ($operation > 1) {
        echo "Only one operation is allowed at a time and you cannot delete,create,update or list at the same time\n";
        showHelp();
        exit;
    }

    if (isset($create) && isset($name)) {
        if (!isset($content)) {
            printf("missing content value \n");
            exit;
        }
        $options = array('name' => $name, 'content' => $content);
        if (isset($type)) {
            $options['type'] = $type;
        }
        if (isset($ttl)) {
            $options['ttl'] = $ttl;
        }
        $options += $defaults;
        if ($res = $cloudflare->createRecord($domain, $options)) {
            printf("Created new record %s \n", implode('.', array($name, $domain)));
            return $res;
        }
    } elseif (isset($create) && !isset($name)) {

        if ($res = $cloudflare->createZone($domain)) {
            printf("Created new zone %s \n", $domain);
            return $res;
        }
    }

    if (isset($name) && isset($delete)) {
        if (isset($content)) {
            $paras = array('name' => $name, 'content' => $content);
        } else {
            $paras = array('name' => $name);
        }
        if ($res = $cloudflare->deleteRecord($domain, $paras)) {
            printf("deleted record %s \n", $name . '.' . $domain);
        }

    } elseif (isset($delete) && !isset($name)) {
        printf("DO YOU WANT TO DELETE DOMAIN %s AND ALL ITS DNS RECORDS? type YES to confirm!\n", $domain);
        if (confirm()) {
            if ($result = $cloudflare->deleteZone($domain)) {
                printf("deleted domain %s \n", $domain);

            }
        }
    }

}

function filterRecords($data, $filter = null)
{
    if (!isset($filter)) {
        $filter = array('name', 'content', 'zone_id','type');
    }

    $value = array_filter($data, function ($k) use ($filter) {
        return (in_array($k, $filter));
    }, ARRAY_FILTER_USE_KEY);
    return $value;
}

function confirm()
{
    $fhandle = fopen('php://stdin', 'r');
    $line = trim(fgets($fhandle));
    if (strtolower($line) == 'yes') {
        return true;
    }
    return false;
}

function showHelp()
{

    print <<<EOF
    usage: cloudflare.php [<options>]

create,delete,update DNS records from the command line

OPTIONS
  --domain, -d     domain name.
  --help, -h       Display this help.
  --name, -n       DNS record name eg www.
  --type, -t       DNS Type eg A,CNAME,etc
  --ttl,           TTL Value
  --update -U     Update dns record
  --create -C   Create new domain or DNS record
  --delete -D   Delete existing domain or DNS records\n
EOF;
}

class Cloudflare
{

    public $client;
    public $zoneIDs;

    public function __construct($key, $mail, $baseUrl, $zoneID = null)
    {
        $this->apikey = $key;
        $this->mail = $mail;
        $this->baseUrl = $baseUrl;
        $this->zoneBaseUrl = $baseUrl . "/zones";
        $this->setZoneID($zoneID);
        $this->client = new Client();
        $this->client->setHeaders('X-Auth-Key', $key);
        $this->client->setHeaders('X-Auth-Email', $mail);
    }

    protected function fetch($url, $data = null)
    {
        $this->client->setHeaders('Content-Type', 'application/json');
        if (isset($data)) {

            if ($this->client->method == 'GET') {
                $this->client->setMethod('POST');
            }
        }

        try {
            $result = $this->client->Request($url, $data);
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        if (!$result) {
            return false;
        }

        if (isset($result->code) && $result->code === 200) {
            return $this->parseResult($result->data);
        }

    }
    public function listZones()
    {

        return $this->fetch($this->zoneBaseUrl);
    }

    public function getZoneID($domain)
    {
        $this->client->setMethod('GET');
        if (isset($this->zoneIDs[$domain])) {
            $this->setZoneID($this->zoneIDs[$domain]);
            return $this->zoneIDs[$domain];
        } else {
            $url = $this->zoneBaseUrl . '?name=' . $domain;
            $res = $this->fetch($url);
            $result = reset($res);
            $this->setZoneID($result->id);
            $this->zoneIDs[$domain] = $result->id;
        }
        return $result->id;
    }

    protected function canonicalize($domain, &$name)
    {
        if (strpos($name, '.') === false) {
            $name = implode('.', array($name, $domain));
        }
    }

    public function listRecordByContent($domain, $paras)
    {
        $this->setDomain($domain);
        if (is_string($paras)) {
            $paras = array('content' => $paras);
        }
        if (!isset($paras['content'])) {
            echo "no content value found \n";
            return false;
        }
        $records = $this->getDNSRecord($paras);
        return $records;
    }
    public function listRecordByName($domain, $paras)
    {
        $this->setDomain($domain);
        if (is_string($paras)) {
            $paras = array('name' => $paras);
        }
        if (isset($paras['name'])) {
            $this->canonicalize($domain, $paras['name']);
        }
        $res = $this->getDNSRecord($paras);
        return $res;
    }

    public function listRecord($domain, $paras)
    {
        $this->setDomain($domain);
        return $this->getDNSRecord($paras);
    }

    protected function getDNSRecord($paras = null)
    {
        if (is_string($paras)) {
            $paras = array('name', $paras);
        }
        if (isset($paras['name'])) {
            $this->canonicalize($this->domain, $paras['name']);
        }
        $this->getZoneID($this->domain);
        $this->client->setMethod('GET');
        $url = $this->recordBaseUrl() . '?' . http_build_query($paras);
        $records = $this->fetch($url);
        if (!$records) {
            return false;
        }
        return $records;
    }

    private function getRecordID($paras = null)
    {
        $records = $this->getDNSRecord($paras);
        $ids = array();
        if (!$records) {
            echo "no records found!\n";
            return false;
        }

        foreach ($records as $record) {
            $ids[] = $record->id;
        }
        return $ids;
    }

    public function listAllRecords($domain)
    {
        $this->client->setMethod('GET');
        if (isset($domain)) {
            $this->setDomain($domain);
        }
        $this->getZoneID($this->domain);
        $url = $this->recordBaseUrl()."?per_page=80";
        $res = $this->fetch($url);
        return $res;
    }

    protected function recordBaseUrl()
    {
        return implode('/', array($this->zoneBaseUrl, $this->zoneID, 'dns_records'));
    }

    public function setDomain($domain)
    {
        $this->domain = $domain;
    }
    public function setZoneID($id)
    {
        $this->zoneID = $id;
    }
    public function setRecordID($id)
    {
        $this->recordID = $id;
    }
    public function deleteRecord($domain, $paras, $content = null)
    {
        $this->setDomain($domain);
        $ids = $this->getRecordID($paras);
        if (!$ids) {
            echo "failed to get any records \n";
            return false;
        }
        $this->client->setMethod('DELETE');
        foreach ($ids as $id) {
            $url = $this->recordBaseUrl() . '/' . $id;
            $result = $this->fetch($url);
        }
        return $result;

    }
    public function deleteZone($domain)
    {
        $zoneid = $this->getZoneID($domain);
        $url = $this->zoneBaseUrl . '/' . $this->zoneID;
        $this->client->setMethod('DELETE');
        try {
            print $url . "\n";
            return $this->fetch($url);
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    protected function getAccount()
    {
        $url = $this->baseUrl . "/accounts";
        $this->client->setMethod('GET');
        $results = $this->fetch($url);

        return $results;
    }

    public function createZone($domain)
    {
        $results = $this->getAccount();
        $result = reset($results);
        $data = array('name' => $domain, 'account' => array('id' => $result->id));
        print $this->zoneBaseUrl . "\n";
        $res = $this->fetch($this->zoneBaseUrl, $data);

        print_r($res);
    }
    public function createRecord($domain, $options)
    {
        $this->getZoneID($domain);
        $url = $this->recordBaseUrl();
        $res = $this->fetch($url, $options);
        return $res;
    }
    public function updateRecord($domain, $options, $new = array())
    {
        $this->setDomain($domain);
        $ids = $this->getRecordID($options);
        foreach ($ids as $index => $id) {
            $data = $new[$index];
            $url = $this->recordBaseUrl() . '/' . $id;
            $this->client->setMethod('PUT');
            $result = $this->fetch($url, $data);
            print_r($result);
        }
        return $result;

    }
    public function parseResult($data)
    {
        $result = json_decode($data);
        $this->data = $result->result;
        return $this->data;
    }
}

class Client
{

    const HTTP_REQUEST_TIMEOUT = -1;

    protected $charset = 'UTF-8';
    public $responses;
    protected $options = array();
    protected $headers = array();

    public function __construct()
    {
        $this->responses = new \stdClass();
        $this->method = 'GET';
    }

    public function setOption($name, $value)
    {

        $this->options[$name] = $value;
    }

    public function setMethod($method)
    {
        $this->method = $method;
    }

    public function setHeaders($name, $value)
    {

        $this->options['headers'][$name] = $value;
        $this->headers = $this->options['headers'];
    }

    public function setPostData($data)
    {

        $this->options['data'] = $this->urlEncode($data);
    }

    public static function parseResponseStatus($response)
    {
        $response_array = explode(' ', trim($response), 3);
        // Set up empty values.
        $result = array(
            'reason_phrase' => '',
        );
        $result['http_version'] = $response_array[0];
        $result['response_code'] = $response_array[1];
        if (isset($response_array[2])) {
            $result['reason_phrase'] = $response_array[2];
        }
        return $result;
    }

    public function timer_start($name)
    {
        global $timers;

        $timers[$name]['start'] = microtime(true);
        $timers[$name]['count'] = isset($timers[$name]['count']) ? ++$timers[$name]['count'] : 1;
    }

    public function timer_read($name)
    {
        global $timers;

        if (isset($timers[$name]['start'])) {
            $stop = microtime(true);
            $diff = round(($stop - $timers[$name]['start']) * 1000, 2);

            if (isset($timers[$name]['time'])) {
                $diff += $timers[$name]['time'];
            }
            return $diff;
        }
        return $timers[$name]['time'];
    }

    protected function urlEncode($paras = array())
    {

        $str = '';

        foreach ($paras as $k => $v) {

            $str .= "$k=" . urlencode($this->characet($v, $this->charset)) . "&";
        }
        return substr($str, 0, -1);
    }

    public function characet($data, $targetCharset = 'UTF-8')
    {

        if (!empty($data)) {

            if (strcasecmp($this->charset, $targetCharset) != 0) {

                $data = mb_convert_encoding($data, $targetCharset);
            }
        }

        return $data;
    }

    public function Request($url, $params = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, 0);
        $this->headers += array(
            'User-Agent' => 'phpclient',
        );

        foreach ($this->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $this->content = null;

        if (isset($this->headers['Content-Type']) && strtolower($this->headers['Content-Type'])
            == 'application/json' && isset($params)) {
            $this->content = json_encode($params);
        }

        if (in_array($this->method, array('DELETE', 'PATCH', 'PUT'))) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->method);
        }
        if (isset($this->content)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->content);
        }

        if ($this->method == "POST" && !isset($this->content)) {
            curl_setopt($ch, CURLOPT_POST, 1);
            if (isset($params)) {
                $postBody = "";
                $multipart = null;
                $encodeParams = array();

                foreach ($params as $k => $v) {
                    if ("@" != substr($v, 0, 1)) {

                        $postBody .= "$k=" . urlencode($this->characet($v, $this->charset)) . "&";
                        $encodeParams[$k] = $this->characet($v, $this->charset);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, substr($postBody, 0, -1));
                        $headers = array('content-type: application/x-www-form-urlencoded;charset=' . $this->charset);
                    } else {
                        $encodeParams[$k] = new \CURLFile(substr($v, 1));
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $encodeParams);
                        $headers = array('content-type: multipart/form-data;charset=' . $this->charset);
                    }
                }

            }
        }
        $res = curl_exec($ch);

        if (curl_errno($ch)) {

            throw new \Exception(curl_error($ch), 0);
        } else {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->responses->code = $code;
        }

        curl_close($ch);
        $this->responses->data = $res;
        return $this->responses;
    }

}
