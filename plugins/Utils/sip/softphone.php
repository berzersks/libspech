<?php

namespace sip;


class softphone
{
    public bool|string $username = false;
    public bool|string $password = false;
    public bool|string $domain = false;
    public bool|string $host = false;
    public int $port = 5060;
    public int $audioReceivePort = 0;
    public int $expires = 0;
    public int $timeoutCall = 0;
    public string $localIp = '';
    public string $calledNumber = '';
    public function __construct(array $config)
    {
        if (isset($config['username'])) $this->username = $config['username'];
        if (isset($config['password'])) $this->password = $config['password'];
        if (isset($config['fromHost'])) $this->domain = $config['fromHost'];
        if (isset($config['host'])) $this->host = $config['host'];
        if (isset($config['port'])) $this->port = $config['port'];
        else $this->port = 5060;
        if (isset($config['audioReceivePort'])) $this->audioReceivePort = $config['audioReceivePort'];
        if (isset($config['expires'])) $this->expires = $config['expires'];
        if (isset($config['timeoutCall'])) $this->timeoutCall = $config['timeoutCall'];
        if (isset($config['localIp'])) $this->localIp = $config['localIp'];
        if (isset($config['calledNumber'])) $this->calledNumber = $config['calledNumber'];
    }
}