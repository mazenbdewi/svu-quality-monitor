<?php

namespace App\Enums;

enum MonitoringCheckType: string
{
    case Http = 'http';
    case Api = 'api';
    case Dns = 'dns';
    case Ssl = 'ssl';
    case Tcp = 'tcp';
}
