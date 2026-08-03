<?php

declare(strict_types=1);

header('Set-Cookie: artifactflow_edge_probe=present; Path=/; HttpOnly; SameSite=Lax');
http_response_code(204);
