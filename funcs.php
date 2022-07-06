<?php

function create_ch($url, $post_body = '')
{
    $github_token = cmd('composer config -g github-oauth.github.com');
    $github_user = cmd('echo $(git config --list | grep user.name) | sed -e "s/user.name=//"');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/vnd.github.v3+json',
        "Authorization: token $github_token",
        "User-Agent: $github_user"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    if ($post_body) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
    }
    return $ch;
}

function cmd($cmds)
{
    if (!is_array($cmds)) {
        $cmds = [$cmds];
    }
    return trim(shell_exec(implode(' && ', $cmds)));
}
