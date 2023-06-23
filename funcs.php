<?php

function print_pr_urls($pr_urls) {
    echo "\rPR urls:\n";
    foreach ($pr_urls as $pr_url) {
        $pr_url = str_replace('api.github.com/repos', 'github.com', $pr_url);
        $pr_url = str_replace('/pulls/', '/pull/', $pr_url);
        echo "- $pr_url\n";
    }
}

function create_ch($url, $post_body = '')
{
    $github_token = cmd('composer config -g github-oauth.github.com');
    $github_user = cmd('echo $(git config --list | grep user.name) | sed -e "s/user.name=//"');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if ($post_body) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept: application/vnd.github+json",
            "Authorization: Bearer $github_token",
            "X-GitHub-Api-Version: 2022-11-28",
            "User-Agent: $github_user"
        ]);
    } else {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/vnd.github.v3+json',
            "Authorization: token $github_token",
            "User-Agent: $github_user"
        ]);
    }
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
    return trim(shell_exec(implode(' && ', $cmds)) ?? '');
}
