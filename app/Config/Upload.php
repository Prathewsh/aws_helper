<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Upload extends BaseConfig
{
    /**
     * Maximum upload size in megabytes.
     */
    public int $maxUploadSizeMb = 500;
}

