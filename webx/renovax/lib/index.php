<?php
// Belt-and-suspenders guard in case the .htaccess (or nginx deny rule) is missing.
http_response_code(403);
exit;
