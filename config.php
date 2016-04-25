<?php
$config = [
    'token' => getenv('TOKEN'),
    'token_github' => getenv('OAUTH_TOKEN_GITHUB')
];
return $config;
