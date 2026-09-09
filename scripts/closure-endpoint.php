<?php

// Only started inside the temporary acceptance container on loopback.
http_response_code((int) file_get_contents('/tmp/closure-monitor-status'));
echo 'Controlled local acceptance endpoint';
